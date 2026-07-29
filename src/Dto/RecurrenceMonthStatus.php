<?php

namespace App\Dto;

use App\Entity\Recurrence;
use App\Entity\Transaction;
use App\Enum\RecurrenceState;

/**
 * État d'une récurrence pour un mois donné : passée (transaction matchée),
 * à venir (date attendue non atteinte) ou en retard (date dépassée sans
 * match — signal utile : prélèvement raté).
 */
final readonly class RecurrenceMonthStatus
{
    public function __construct(
        public Recurrence $recurrence,
        public RecurrenceState $state,
        public \DateTimeImmutable $expectedDate,
        public ?Transaction $transaction = null,
        public bool $probablyEnded = false,
    ) {
    }
}
