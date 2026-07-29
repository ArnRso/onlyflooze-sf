<?php

namespace App\Dto;

use Symfony\UX\Chartjs\Model\Chart;

/**
 * Données du dashboard mensuel.
 */
final readonly class MonthOverview
{
    /**
     * @param list<CategoryMonthTotal>    $categoryTotals
     * @param list<RecurrenceMonthStatus> $recurrenceStatuses
     */
    public function __construct(
        public \DateTimeImmutable $month,
        public array $categoryTotals,
        public int $expenseCents,
        public int $incomeCents,
        public int $netCents,
        public int $toReviewCount,
        public array $recurrenceStatuses,
        public int $remainingRecurrencesCents,
        public Chart $historyChart,
    ) {
    }

    public function getEstimatedEndOfMonthCents(): int
    {
        return $this->netCents + $this->remainingRecurrencesCents;
    }
}
