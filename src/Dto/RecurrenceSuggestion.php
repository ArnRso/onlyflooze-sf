<?php

namespace App\Dto;

use App\Entity\CategorizationRule;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Enum\Direction;

/**
 * Proposition de promotion en récurrence : « EDF, ~84 € vers le 21 — suivre
 * comme dépense prévue ? ». Jamais créée automatiquement : un clic pour
 * confirmer. Les occurrences observées sont exposées pour que l'utilisateur
 * puisse vérifier sur quoi repose la proposition.
 */
final readonly class RecurrenceSuggestion
{
    /**
     * @param list<Transaction> $transactions occurrences observées, ordre chronologique
     */
    public function __construct(
        public CategorizationRule $rule,
        public ?Category $category,
        public Direction $direction,
        public int $expectedDayOfMonth,
        public int $expectedAmountCents,
        public \DateTimeImmutable $lastOccurrenceDate,
        public array $transactions,
    ) {
    }

    public function getOccurrenceCount(): int
    {
        return \count($this->transactions);
    }
}
