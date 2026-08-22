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
 * - Une empreinte étrangère à sa règle (aucun token discriminant commun :
 *   « REGIE EAU » validée sous la règle GAZ) est séparée en règle propre,
 *   même catégorie — ce que l'apprentissage ferait aujourd'hui.
 * - Une empreinte couverte par une autre règle de la même catégorie est
 *   retirée ; une règle qui n'a plus rien à couvrir est supprimée.
 * - Une règle plus corrigée que confirmée est rétrogradée : elle ne matche
 *   plus qu'à l'empreinte exacte.
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
            foreach ($this->planRule($rule, $generic, $rules) as $change) {
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

        $report->suggestionsUpdated = \count($this->ruleReapplier->reapply());

        return $report;
    }

    /**
     * @param list<string>             $generic
     * @param list<CategorizationRule> $rules
     *
     * @return list<RuleChange>
     */
    private function planRule(CategorizationRule $rule, array $generic, array $rules): array
    {
        $before = $rule->getTokens();

        // Une règle qui se trompe plus qu'elle n'a raison n'est pas une
        // source de vérité : rétrogradée, et on ne sépare rien à partir d'elle.
        if ($rule->getCorrections() > $rule->getConfirmations()) {
            return $before !== [] ? [new RuleChange($rule, RuleChangeKind::Demoted, $before, [])] : [];
        }

        $specific = TokenSelectivity::discriminant($before, $generic);
        $target = $specific;
        if ($target === [] && $before !== []) {
            $target = $this->commonDiscriminantTokens($rule, $generic);
        }

        $changes = [];
        if ($target !== $before) {
            $kind = match (true) {
                $target === [] => RuleChangeKind::Demoted,
                $specific !== [] => RuleChangeKind::Cleaned,
                default => RuleChangeKind::Rebuilt,
            };
            $changes[] = new RuleChange($rule, $kind, $before, $target);
        }

        // Empreintes qui n'ont rien à faire là : étrangères aux tokens cibles
        // (ou toutes, pour une règle sans token) → règle propre, sauf si une
        // autre règle de la catégorie les couvre déjà → simplement retirées.
        $covered = [];
        $foreign = [];
        $remaining = 0;
        foreach ($rule->getFingerprints() as $fingerprint) {
            $tokens = TokenSelectivity::discriminant(NormalizedLabel::tokensFromFingerprint($fingerprint), $generic);
            $isForeign = $tokens !== [] && array_intersect($target, $tokens) === [];
            if (($target === [] || $isForeign) && $this->isCoveredByAnotherRule($rule, $tokens, $rules)) {
                $covered[] = $fingerprint;
            } elseif ($isForeign) {
                $foreign[implode(' ', $tokens)][] = $fingerprint;
            } else {
                ++$remaining;
            }
        }

        if ($covered !== []) {
            $changes[] = new RuleChange($rule, RuleChangeKind::Trimmed, $target, $target, $covered);
        }
        foreach ($foreign as $tokens => $fingerprints) {
            $changes[] = new RuleChange($rule, RuleChangeKind::Split, $target, explode(' ', $tokens), $fingerprints);
        }

        // Plus de token ni d'empreinte : la règle ne couvre plus rien.
        if ($target === [] && $remaining === 0) {
            $changes = array_values(array_filter($changes, static fn (RuleChange $change): bool => $change->kind !== RuleChangeKind::Demoted));
            $changes[] = new RuleChange($rule, RuleChangeKind::Dropped, $before, []);
        }

        return $changes;
    }

    private function apply(RuleChange $change): void
    {
        switch ($change->kind) {
            case RuleChangeKind::Cleaned:
            case RuleChangeKind::Rebuilt:
                $change->rule->setTokens($change->after)->setName(implode(' ', $change->after));
                break;
            case RuleChangeKind::Demoted:
                $change->rule->setTokens([]);
                break;
            case RuleChangeKind::Trimmed:
                foreach ($change->fingerprints as $fingerprint) {
                    $change->rule->removeFingerprint($fingerprint);
                }
                break;
            case RuleChangeKind::Split:
                $split = new CategorizationRule(implode(' ', $change->after), $change->rule->getCategory(), $change->rule->getDirection());
                $split->setTokens($change->after)
                    ->setNature($change->rule->getNature())
                    ->setAmountCents($change->rule->getAmountCents())
                    ->recordConfirmation();
                foreach ($change->fingerprints as $fingerprint) {
                    $split->addFingerprint($fingerprint);
                    $change->rule->removeFingerprint($fingerprint);
                }
                $this->entityManager->persist($split);
                break;
            case RuleChangeKind::Dropped:
                $this->entityManager->remove($change->rule);
                break;
        }
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
     * Une autre règle de la même catégorie (et du même sens, et compatible
     * en montant) matche-t-elle déjà ces tokens ?
     *
     * @param list<string>             $fingerprintTokens
     * @param list<CategorizationRule> $rules
     */
    private function isCoveredByAnotherRule(CategorizationRule $rule, array $fingerprintTokens, array $rules): bool
    {
        foreach ($rules as $other) {
            if ($other === $rule
                || $other->getTokens() === []
                || $other->getCategory() !== $rule->getCategory()
                || $other->getDirection() !== $rule->getDirection()
                || ($other->getAmountCents() !== null && $other->getAmountCents() !== $rule->getAmountCents())) {
                continue;
            }
            if (array_diff($other->getTokens(), $fingerprintTokens) === []) {
                return true;
            }
        }

        return false;
    }
}
