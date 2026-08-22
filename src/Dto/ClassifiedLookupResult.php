<?php

namespace App\Dto;

use App\Entity\Transaction;

/**
 * Ce que l'utilisateur a déjà décidé pour un libellé donné : répartition
 * par catégorie et dernières occurrences triées.
 */
final readonly class ClassifiedLookupResult
{
    /**
     * @param list<array{category: string, count: int}> $byCategory
     * @param list<Transaction>                         $recent
     */
    public function __construct(
        public string $query,
        public int $total,
        public array $byCategory,
        public array $recent,
    ) {
    }
}
