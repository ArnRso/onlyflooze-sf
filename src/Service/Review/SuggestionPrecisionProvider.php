<?php

namespace App\Service\Review;

use App\Dto\SuggestionPrecision;
use App\Enum\SuggestionOutcome;
use App\Repository\TransactionRepository;

/**
 * Agrège le sort réservé aux suggestions, mois par mois : c'est le tableau
 * de bord qui dit si le moteur s'améliore.
 */
class SuggestionPrecisionProvider
{
    public function __construct(
        private readonly TransactionRepository $transactionRepository,
    ) {
    }

    /**
     * @return array{overall: SuggestionPrecision, months: list<SuggestionPrecision>}
     */
    public function summary(int $months = 6): array
    {
        $byMonth = [];
        $totals = [SuggestionOutcome::Accepted->value => 0, SuggestionOutcome::Corrected->value => 0, SuggestionOutcome::None->value => 0];

        foreach ($this->transactionRepository->countReviewOutcomesByMonth() as $row) {
            $byMonth[$row['month']][$row['outcome']] = $row['cnt'];
            $totals[$row['outcome']] += $row['cnt'];
        }

        krsort($byMonth);
        $recent = [];
        foreach (\array_slice($byMonth, 0, $months, true) as $month => $counts) {
            $recent[] = new SuggestionPrecision(
                $month,
                $counts[SuggestionOutcome::Accepted->value] ?? 0,
                $counts[SuggestionOutcome::Corrected->value] ?? 0,
                $counts[SuggestionOutcome::None->value] ?? 0,
            );
        }

        return [
            'overall' => new SuggestionPrecision(
                null,
                $totals[SuggestionOutcome::Accepted->value],
                $totals[SuggestionOutcome::Corrected->value],
                $totals[SuggestionOutcome::None->value],
            ),
            'months' => $recent,
        ];
    }
}
