<?php

namespace App\Service\Matching;

use App\Dto\MatchResult;
use App\Dto\NormalizedLabel;
use App\Entity\CategorizationRule;
use App\Enum\Direction;
use App\Enum\MatchConfidence;
use App\Enum\TransactionType;
use App\Repository\CategorizationRuleRepository;
use App\Repository\TransactionRepository;

/**
 * Cascade de matching, du plus sûr au moins sûr :
 *
 * 1. Empreinte normalisée exacte déjà connue.
 * 2. Tokens discriminants appris, présents en MOT ENTIER (jamais en
 *    sous-chaîne : CHRONOVET ≠ CHRONO).
 * 3. Fuzzy (Levenshtein sur tokens) : rattrape les typos.
 * 4. Montant + périodicité : recolle les récurrences dont le libellé dérive.
 *
 * Une règle porteuse d'un montant (sous-règle PayPal) ne matche que si le
 * montant de la transaction correspond exactement.
 */
class MatchingEngine
{
    public function __construct(
        private readonly CategorizationRuleRepository $ruleRepository,
        private readonly TransactionRepository $transactionRepository,
    ) {
    }

    public function match(
        NormalizedLabel $label,
        int $amountCents,
        ?\DateTimeImmutable $operationDate = null,
    ): MatchResult {
        $rules = $this->ruleRepository->findByDirection(Direction::fromAmountCents($amountCents));

        $result = $this->matchAgainstRules($label, $amountCents, $rules);
        if ($result->isMatch()) {
            return $result;
        }

        if ($operationDate !== null && $label->type === TransactionType::AnnulationCarte) {
            $result = $this->matchRefundOrigin($label, $amountCents, $operationDate);
            if ($result->isMatch()) {
                return $result;
            }
        }

        if ($operationDate !== null) {
            $result = $this->matchByPeriodicity($label, $amountCents, $operationDate);
        }

        return $result;
    }

    /**
     * Rapprochement d'une annulation carte avec son achat d'origine : même
     * marchand (au moins un token commun), montant exactement opposé,
     * quelques jours ou semaines avant. La catégorie de l'achat est suggérée
     * pour neutraliser la paire (cas réel : achat Apple 218 € annulé 13
     * jours après).
     */
    public function matchRefundOrigin(NormalizedLabel $label, int $amountCents, \DateTimeImmutable $operationDate): MatchResult
    {
        foreach ($this->transactionRepository->findRefundOriginCandidates($amountCents, $operationDate) as $origin) {
            if (array_intersect($label->tokens, $origin->getTokens()) === []) {
                continue;
            }

            $category = $origin->getCategory();
            if ($category !== null) {
                return MatchResult::fromRefundOrigin($category);
            }
        }

        return MatchResult::none();
    }

    /**
     * Niveaux 1 à 3 de la cascade, sur un jeu de règles donné (pur, testable).
     *
     * @param list<CategorizationRule> $rules
     */
    public function matchAgainstRules(NormalizedLabel $label, int $amountCents, array $rules): MatchResult
    {
        $applicable = array_values(array_filter(
            $rules,
            static fn (CategorizationRule $rule): bool => $rule->getAmountCents() === null || $rule->getAmountCents() === $amountCents,
        ));

        foreach ([MatchConfidence::Exact, MatchConfidence::Token, MatchConfidence::Fuzzy] as $confidence) {
            $candidates = array_values(array_filter(
                $applicable,
                fn (CategorizationRule $rule): bool => $this->ruleMatches($rule, $label, $confidence),
            ));

            if ($candidates !== []) {
                return MatchResult::fromRule($confidence, $this->pickMostSpecific($candidates));
            }
        }

        return MatchResult::none();
    }

    private function ruleMatches(CategorizationRule $rule, NormalizedLabel $label, MatchConfidence $confidence): bool
    {
        return match ($confidence) {
            MatchConfidence::Exact => \in_array($label->getFingerprint(), $rule->getFingerprints(), true),
            MatchConfidence::Token => $this->allTokensPresent($rule->getTokens(), $label->tokens),
            MatchConfidence::Fuzzy => $this->allTokensFuzzyPresent($rule->getTokens(), $label->tokens),
            default => false,
        };
    }

    /**
     * @param list<string> $ruleTokens
     * @param list<string> $labelTokens
     */
    private function allTokensPresent(array $ruleTokens, array $labelTokens): bool
    {
        if ($ruleTokens === []) {
            return false;
        }

        foreach ($ruleTokens as $token) {
            if (!\in_array($token, $labelTokens, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $ruleTokens
     * @param list<string> $labelTokens
     */
    private function allTokensFuzzyPresent(array $ruleTokens, array $labelTokens): bool
    {
        if ($ruleTokens === []) {
            return false;
        }

        foreach ($ruleTokens as $ruleToken) {
            $found = false;
            foreach ($labelTokens as $labelToken) {
                if ($this->tokensAreClose($ruleToken, $labelToken)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }

        return true;
    }

    private function tokensAreClose(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        // Les identifiants numériques (n° de prêt, de compte…) ne sont jamais
        // des typos l'un de l'autre : un chiffre d'écart = un objet différent
        // (cas réel : ECH PRET 0545773921701/…02/…03 = trois prêts distincts).
        if (ctype_digit($a) || ctype_digit($b)) {
            return false;
        }

        $minLength = min(mb_strlen($a), mb_strlen($b));

        // Trop court pour tolérer une différence sans faux positifs.
        if ($minLength < 5) {
            return false;
        }

        $maxDistance = $minLength >= 8 ? 2 : 1;

        return levenshtein($a, $b) <= $maxDistance;
    }

    /**
     * À niveau de confiance égal : une sous-règle par montant l'emporte sur
     * une règle générique, puis la règle aux tokens les plus nombreux (la
     * plus spécifique), puis la plus confirmée.
     *
     * @param non-empty-list<CategorizationRule> $candidates
     */
    private function pickMostSpecific(array $candidates): CategorizationRule
    {
        usort($candidates, static function (CategorizationRule $a, CategorizationRule $b): int {
            return [$b->getAmountCents() !== null, \count($b->getTokens()), $b->getConfirmations()]
                <=> [$a->getAmountCents() !== null, \count($a->getTokens()), $a->getConfirmations()];
        });

        return $candidates[0];
    }

    /**
     * Niveau 4 : une transaction du même type, d'un montant proche (±15 %),
     * environ un mois plus tôt et déjà catégorisée → probablement la même
     * chose, même si le libellé a changé. Toujours une simple suggestion.
     */
    private function matchByPeriodicity(NormalizedLabel $label, int $amountCents, \DateTimeImmutable $operationDate): MatchResult
    {
        $previous = $this->transactionRepository->findPeriodicityCandidate(
            $label->type,
            $amountCents,
            $operationDate,
        );

        $category = $previous?->getCategory();
        if ($category === null) {
            return MatchResult::none();
        }

        return MatchResult::fromPeriodicity($category);
    }
}
