<?php

namespace App\Dto;

use App\Enum\RecurrenceState;

/**
 * Ce que les récurrences du mois ont déjà fait passer et ce qu'il reste à
 * sortir / à rentrer (à venir ou en retard), en centimes signés.
 */
final readonly class RecurrenceForecast
{
    public function __construct(
        public int $passedDebitCents,
        public int $passedCreditCents,
        public int $remainingDebitCents,
        public int $remainingCreditCents,
        public int $remainingDebitCount,
        public int $remainingCreditCount,
    ) {
    }

    /**
     * @param list<RecurrenceMonthStatus> $statuses
     */
    public static function fromStatuses(array $statuses): self
    {
        $passedDebit = $passedCredit = $remainingDebit = $remainingCredit = 0;
        $remainingDebitCount = $remainingCreditCount = 0;

        foreach ($statuses as $status) {
            if ($status->state === RecurrenceState::Passed && $status->transaction !== null) {
                $amount = $status->transaction->getAmountCents();
                $amount < 0 ? $passedDebit += $amount : $passedCredit += $amount;
                continue;
            }
            if ($status->state !== RecurrenceState::Upcoming && $status->state !== RecurrenceState::Late) {
                continue;
            }
            $amount = $status->recurrence->getExpectedAmountCents();
            if ($amount < 0) {
                $remainingDebit += $amount;
                ++$remainingDebitCount;
            } else {
                $remainingCredit += $amount;
                ++$remainingCreditCount;
            }
        }

        return new self($passedDebit, $passedCredit, $remainingDebit, $remainingCredit, $remainingDebitCount, $remainingCreditCount);
    }

    public function getRemainingCents(): int
    {
        return $this->remainingDebitCents + $this->remainingCreditCents;
    }
}
