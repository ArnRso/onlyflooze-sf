<?php

namespace App\Dto;

use App\Entity\Category;

/**
 * Total d'un poste de budget (catégorie racine) pour le mois affiché,
 * comparé au mois précédent.
 */
final readonly class CategoryMonthTotal
{
    public function __construct(
        public ?Category $category,
        public int $amountCents,
        public int $previousAmountCents,
    ) {
    }

    public function getDeltaCents(): int
    {
        return $this->amountCents - $this->previousAmountCents;
    }
}
