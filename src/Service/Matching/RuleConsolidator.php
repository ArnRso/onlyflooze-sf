<?php

namespace App\Service\Matching;

use App\Dto\ConsolidationReport;
use App\Dto\NormalizedLabel;
use App\Dto\RuleChange;
use App\Entity\CategorizationRule;
use App\Enum\RuleChangeKind;
use App\Repository\CategorizationRuleRepository;
use App\Service\Review\RuleReapplier;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Auto-amélioration des règles : relit périodiquement (après chaque import,
 * ou via app:rules:consolidate) l'ensemble des règles à la lumière du corpus
 * actuel, et corrige ce que l'apprentissage au fil de l'eau a laissé passer.
 *
 * - Une règle qui porte des tokens génériques (ville, suffixe) en est
 *   débarrassée ; si elle ne reposait que sur eux, elle est reconstruite à
 *   partir des empreintes que l'utilisateur a validées.
 * - Une règle plus corrigée que confirmée est rétrogradée : elle ne matche
 *   plus qu'à l'empreinte exacte.
 * - Une règle « empreintes seules » dont toutes les empreintes sont couvertes
 *   par une vraie règle de la même catégorie est supprimée.
 *
 * Jamais de catégorisation : les suggestions sont ensuite rejouées, et
 * celles devenues orphelines retirées. Les tokens posés à la main ne sont
 * jamais élargis : on retire, on ne rajoute que s'il ne reste rien.
 */
class RuleConsolidator
{
    public function __construct(
        private readonly CategorizationRuleRepository $ruleRepository,
        private readonly TokenSelectivity $tokenSelectivity,
        private readonly RuleReapplier $ruleReapplier,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Calcule ce qui changerait, sans rien modifier.
     */
    public function plan(): ConsolidationReport
    {
        $generic = $this->tokenSelectivity->genericTokens();
        $report = new ConsolidationReport($generic);

        $rules = $this->ruleRepository->findAllOrdered();
        foreach ($rules as $rule) {
            $change = $this->planRule($rule, $generic, $rules);
            if ($change !== null) {
                $report->changes[] = $change;
            }
        }

        return $report;
    }

    public function consolidate(): ConsolidationReport
    {
        $report = $this->plan();

        foreach ($report->changes as $change) {
            $this->apply($change);
        }
        $this->entityManager->flush();

        $report->suggestionsUpdated = $this->ruleReapplier->reapply();

        return $report;
    }

    /**
     * @param list<string>             $generic
     * @param list<CategorizationRule> $rules
     */
    private function planRule(CategorizationRule $rule, array $generic, array $rules): ?RuleChange
    {
        $before = $rule->getTokens();

        if ($rule->getCorrections() > $rule->getConfirmations() && $before !== []) {
            return new RuleChange($rule, RuleChangeKind::Demoted, $before, []);
        }

        if ($before === []) {
            return $this->isCoveredByAnotherRule($rule, $rules)
                ? new RuleChange($rule, RuleChangeKind::Dropped, $before, [])
                : null;
        }

        $specific = TokenSelectivity::discriminant($before, $generic);
        if ($specific !== []) {
            return $specific === $before ? null : new RuleChange($rule, RuleChangeKind::Cleaned, $before, $specific);
        }

        $rebuilt = $this->commonDiscriminantTokens($rule, $generic);

        return $rebuilt !== []
            ? new RuleChange($rule, RuleChangeKind::Rebuilt, $before, $rebuilt)
            : new RuleChange($rule, RuleChangeKind::Demoted, $before, []);
    }

    private function apply(RuleChange $change): void
    {
        match ($change->kind) {
            RuleChangeKind::Cleaned, RuleChangeKind::Rebuilt => $change->rule
                ->setTokens($change->after)
                ->setName(implode(' ', $change->after)),
            RuleChangeKind::Demoted => $change->rule->setTokens([]),
            RuleChangeKind::Dropped => $this->entityManager->remove($change->rule),
        };
    }

    /**
     * Tokens discriminants communs à toutes les empreintes validées de la
     * règle (celles sans aucun token discriminant ne comptent pas).
     *
     * @param list<string> $generic
     *
     * @return list<string>
     */
    private function commonDiscriminantTokens(CategorizationRule $rule, array $generic): array
    {
        $common = null;
        foreach ($rule->getFingerprints() as $fingerprint) {
            $tokens = TokenSelectivity::discriminant(NormalizedLabel::tokensFromFingerprint($fingerprint), $generic);
            if ($tokens === []) {
                continue;
            }
            $common = $common === null ? $tokens : array_values(array_intersect($common, $tokens));
            if ($common === []) {
                return [];
            }
        }

        return $common ?? [];
    }

    /**
     * @param list<CategorizationRule> $rules
     */
    private function isCoveredByAnotherRule(CategorizationRule $rule, array $rules): bool
    {
        if ($rule->getFingerprints() === []) {
            return false;
        }

        foreach ($rule->getFingerprints() as $fingerprint) {
            $tokens = NormalizedLabel::tokensFromFingerprint($fingerprint);
            $covered = false;
            foreach ($rules as $other) {
                if ($other === $rule
                    || $other->getTokens() === []
                    || $other->getCategory() !== $rule->getCategory()
                    || $other->getDirection() !== $rule->getDirection()
                    || ($other->getAmountCents() !== null && $other->getAmountCents() !== $rule->getAmountCents())) {
                    continue;
                }
                if (array_diff($other->getTokens(), $tokens) === []) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                return false;
            }
        }

        return true;
    }
}
