<?php

namespace App\Service\Matching;

use App\Dto\NormalizedLabel;
use App\Entity\CategorizationRule;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Enum\TransactionNature;
use App\Repository\CategorizationRuleRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Apprentissage des règles de catégorisation.
 *
 * Chaque catégorisation manuelle crée ou renforce une règle. Le token
 * discriminant d'une règle est l'intersection des tokens de toutes les
 * transactions classées pareil — tokens génériques exclus (ville, mot-outil,
 * cf. GenericTokenDetector). Une règle corrigée est dégradée : le système ne
 * s'entête pas.
 *
 * Quand un libellé n'a aucun token discriminant, la règle ne porte pas de
 * token : elle ne matche qu'à l'empreinte exacte.
 */
class RuleLearner
{
    public function __construct(
        private readonly CategorizationRuleRepository $ruleRepository,
        private readonly TokenSelectivity $tokenSelectivity,
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
        $generic = $this->tokenSelectivity->genericTokens();

        $rule = $this->findReinforcableRule($transaction, $category, $label, $generic)
            ?? $this->createRule($transaction, $category, $label, $generic);

        $rule->addFingerprint($label->getFingerprint());
        $rule->recordConfirmation();
        $this->learnNature($rule, $transaction);

        $this->entityManager->flush();

        return $rule;
    }

    /**
     * L'utilisateur valide la suggestion pré-remplie : la règle est
     * renforcée et mémorise cette empreinte. Retourne la règle effectivement
     * créditée : si les tokens de la transaction n'ont RIEN de discriminant
     * en commun avec ceux de la règle (match fuzzy, par périodicité, ou règle
     * réduite à ses empreintes), on ne pollue pas la règle — on apprend
     * séparément.
     */
    public function confirmSuggestion(Transaction $transaction): ?CategorizationRule
    {
        $rule = $transaction->getMatchedRule();
        if ($rule === null) {
            return null;
        }

        $label = $this->labelFromTransaction($transaction);
        $generic = $this->tokenSelectivity->genericTokens();

        if ($this->discriminantIntersection($rule->getTokens(), $label->tokens, $generic) === []) {
            return $this->learnFromCategorization($transaction, $rule->getCategory());
        }

        $this->narrowTokens($rule, $label, $generic);
        $rule->addFingerprint($label->getFingerprint());
        $rule->recordConfirmation();
        $this->learnNature($rule, $transaction);

        $this->entityManager->flush();

        return $rule;
    }

    /**
     * @param list<string> $generic
     */
    private function findReinforcableRule(Transaction $transaction, Category $category, NormalizedLabel $label, array $generic): ?CategorizationRule
    {
        $rules = $this->ruleRepository->findByCategoryAndDirection($category, $transaction->getDirection());
        $fingerprint = $label->getFingerprint();

        $best = null;
        $bestSize = 0;
        $exact = null;
        foreach ($rules as $rule) {
            if ($rule->getAmountCents() !== null && $rule->getAmountCents() !== $transaction->getAmountCents()) {
                continue;
            }
            if ($exact === null && \in_array($fingerprint, $rule->getFingerprints(), true)) {
                $exact = $rule;
            }
            $size = \count($this->discriminantIntersection($rule->getTokens(), $label->tokens, $generic));
            if ($size > $bestSize) {
                $best = $rule;
                $bestSize = $size;
            }
        }

        if ($best !== null) {
            $this->narrowTokens($best, $label, $generic);

            return $best;
        }

        // Aucune règle à token commun : une règle « empreintes seules » n'est
        // renforcée que si le libellé n'a lui-même rien de discriminant —
        // sinon on préfère créer une vraie règle.
        return TokenSelectivity::discriminant($label->tokens, $generic) === [] ? $exact : null;
    }

    /**
     * @param list<string> $generic
     */
    private function createRule(Transaction $transaction, Category $category, NormalizedLabel $label, array $generic): CategorizationRule
    {
        $tokens = TokenSelectivity::discriminant($label->tokens, $generic);

        $rule = new CategorizationRule(
            $tokens !== [] ? implode(' ', $tokens) : $transaction->getLabel(),
            $category,
            $transaction->getDirection(),
        );
        $rule->setTokens($tokens);

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
     *
     * @param list<string> $generic
     */
    private function narrowTokens(CategorizationRule $rule, NormalizedLabel $label, array $generic): void
    {
        $intersection = $this->discriminantIntersection($rule->getTokens(), $label->tokens, $generic);
        if ($intersection === [] || $intersection === $rule->getTokens()) {
            return;
        }

        $rule->setTokens($intersection);
        $rule->setName(implode(' ', $intersection));
    }

    /**
     * @param list<string> $ruleTokens
     * @param list<string> $labelTokens
     * @param list<string> $generic
     *
     * @return list<string>
     */
    private function discriminantIntersection(array $ruleTokens, array $labelTokens, array $generic): array
    {
        return TokenSelectivity::discriminant(array_values(array_intersect($ruleTokens, $labelTokens)), $generic);
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
