<?php

namespace App\Service\Matching;

use App\Dto\NormalizedLabel;
use App\Entity\CategorizationRule;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Enum\TransactionNature;
use App\Repository\CategorizationRuleRepository;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Apprentissage des règles de catégorisation.
 *
 * Chaque catégorisation manuelle crée ou renforce une règle. Le token
 * discriminant d'une règle est l'intersection des tokens de toutes les
 * transactions classées pareil. Une règle corrigée est dégradée : le
 * système ne s'entête pas.
 */
class RuleLearner
{
    /**
     * Mots-outils français : jamais discriminants, quelle que soit leur
     * fréquence.
     */
    private const array STOPWORDS = ['DE', 'LE', 'LA', 'LES', 'DU', 'DES', 'ET', 'EN', 'AU', 'AUX', 'SUR', 'CHEZ'];

    /**
     * Au-delà de cette part du corpus, un token (nom de ville…) ne
     * discrimine plus rien.
     */
    private const float MAX_TOKEN_FREQUENCY = 0.04;

    public function __construct(
        private readonly CategorizationRuleRepository $ruleRepository,
        private readonly TransactionRepository $transactionRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Apprend d'une catégorisation manuelle : dégrade l'éventuelle règle qui
     * s'était trompée, puis renforce (ou crée) la règle de la catégorie
     * choisie.
     */
    public function learnFromCategorization(Transaction $transaction, Category $category): CategorizationRule
    {
        $previousRule = $transaction->getMatchedRule();
        if ($previousRule !== null && $previousRule->getCategory() !== $category) {
            $previousRule->recordCorrection();
        }

        $label = $this->labelFromTransaction($transaction);

        $rule = $this->findReinforcableRule($transaction, $category, $label)
            ?? $this->createRule($transaction, $category, $label);

        $rule->addFingerprint($label->getFingerprint());
        $rule->recordConfirmation();
        $this->learnNature($rule, $transaction);

        $this->entityManager->flush();

        return $rule;
    }

    /**
     * L'utilisateur valide la suggestion pré-remplie : la règle est
     * renforcée et mémorise cette empreinte. Retourne la règle effectivement
     * créditée : si les tokens de la transaction n'ont RIEN en commun avec
     * ceux de la règle (match fuzzy ou par périodicité sur une contrepartie
     * différente), on ne pollue pas la règle — on apprend séparément.
     */
    public function confirmSuggestion(Transaction $transaction): ?CategorizationRule
    {
        $rule = $transaction->getMatchedRule();
        if ($rule === null) {
            return null;
        }

        $label = $this->labelFromTransaction($transaction);

        if ($rule->getTokens() !== [] && array_intersect($rule->getTokens(), $label->tokens) === []) {
            return $this->learnFromCategorization($transaction, $rule->getCategory());
        }

        $this->narrowTokens($rule, $label);
        $rule->addFingerprint($label->getFingerprint());
        $rule->recordConfirmation();
        $this->learnNature($rule, $transaction);

        $this->entityManager->flush();

        return $rule;
    }

    private function findReinforcableRule(Transaction $transaction, Category $category, NormalizedLabel $label): ?CategorizationRule
    {
        $rules = $this->ruleRepository->findByCategoryAndDirection($category, $transaction->getDirection());

        $best = null;
        $bestSize = 0;
        foreach ($rules as $rule) {
            if ($rule->getAmountCents() !== null && $rule->getAmountCents() !== $transaction->getAmountCents()) {
                continue;
            }
            $intersection = array_values(array_intersect($rule->getTokens(), $label->tokens));
            if (\count($intersection) > $bestSize) {
                $best = $rule;
                $bestSize = \count($intersection);
            }
        }

        if ($best !== null) {
            $this->narrowTokens($best, $label);
        }

        return $best;
    }

    private function createRule(Transaction $transaction, Category $category, NormalizedLabel $label): CategorizationRule
    {
        $rule = new CategorizationRule(
            $label->tokens !== [] ? implode(' ', $label->tokens) : $transaction->getLabel(),
            $category,
            $transaction->getDirection(),
        );
        $rule->setTokens($label->tokens);

        // Cas agrégateur (PayPal) : si une règle d'une AUTRE catégorie matche
        // déjà ces tokens, la nouvelle règle est scopée par montant pour ne
        // pas entrer en conflit sur le seul libellé.
        if ($this->conflictsWithOtherCategory($transaction, $category, $label)) {
            $rule->setAmountCents($transaction->getAmountCents());
        }

        $this->entityManager->persist($rule);

        return $rule;
    }

    private function conflictsWithOtherCategory(Transaction $transaction, Category $category, NormalizedLabel $label): bool
    {
        foreach ($this->ruleRepository->findByDirection($transaction->getDirection()) as $rule) {
            if ($rule->getCategory() === $category || $rule->getTokens() === []) {
                continue;
            }
            if (array_diff($rule->getTokens(), $label->tokens) === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resserre les tokens discriminants de la règle : intersection avec les
     * tokens de la nouvelle transaction — SI le résultat discrimine encore
     * quelque chose. Deux restos bordelais ne doivent pas dégénérer la règle
     * en [BORDEAUX] (ni pire, en [DE]) : dans ce cas la règle garde ses
     * tokens, l'empreinte exacte couvrant la nouvelle graphie.
     */
    private function narrowTokens(CategorizationRule $rule, NormalizedLabel $label): void
    {
        $intersection = array_values(array_intersect($rule->getTokens(), $label->tokens));
        if ($intersection === [] || $intersection === $rule->getTokens()) {
            return;
        }

        if (!$this->isDiscriminant($intersection)) {
            return;
        }

        $rule->setTokens($intersection);
        $rule->setName(implode(' ', $intersection));
    }

    /**
     * Au moins un token ni mot-outil ni ultra-fréquent dans le corpus.
     *
     * @param list<string> $tokens
     */
    private function isDiscriminant(array $tokens): bool
    {
        $total = $this->transactionRepository->countAll();
        $threshold = max(30, (int) ($total * self::MAX_TOKEN_FREQUENCY));

        foreach ($tokens as $token) {
            if (\in_array($token, self::STOPWORDS, true)) {
                continue;
            }
            if ($this->transactionRepository->countWithToken($token) <= $threshold) {
                return true;
            }
        }

        return false;
    }

    /**
     * La règle mémorise la nature choisie quand elle n'est pas celle par
     * défaut du sens (transfert interne, remboursement) : les prochaines
     * suggestions pré-rempliront la bonne nature.
     */
    private function learnNature(CategorizationRule $rule, Transaction $transaction): void
    {
        $default = TransactionNature::defaultForAmountCents($transaction->getAmountCents());
        $rule->setNature($transaction->getNature() !== $default ? $transaction->getNature() : null);
    }

    private function labelFromTransaction(Transaction $transaction): NormalizedLabel
    {
        return new NormalizedLabel($transaction->getType(), $transaction->getTokens());
    }
}
