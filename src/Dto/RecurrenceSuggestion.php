<?php

namespace App\Dto;

use App\Entity\CategorizationRule;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Enum\Direction;
use App\Enum\TransactionType;

/**
 * Proposition de promotion en récurrence : « EDF, ~84 € vers le 21 — suivre
 * comme dépense prévue ? ». Jamais créée automatiquement : un clic pour
 * confirmer. Les occurrences observées sont exposées pour que l'utilisateur
 * puisse vérifier sur quoi repose la proposition.
 *
 * La clé identifie la proposition entre l'affichage et le clic ; elle est
 * recalculée à chaque détection, une proposition périmée est simplement
 * introuvable.
 */
final readonly class RecurrenceSuggestion
{
    /**
     * @param list<string>      $tokens       tokens discriminants communs aux occurrences
     * @param list<Transaction> $transactions occurrences observées, ordre chronologique
     */
    public function __construct(
        public string $key,
        public string $name,
        public Direction $direction,
        public TransactionType $type,
        public string $headToken,
        public array $tokens,
        public ?CategorizationRule $rule,
        public ?Category $category,
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
