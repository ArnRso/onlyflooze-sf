<?php

namespace App\Dto;

/**
 * Précision des suggestions sur une période : couverture (part des tris où
 * une suggestion existait) et justesse (part des suggestions acceptées).
 */
final readonly class SuggestionPrecision
{
    public function __construct(
        public ?string $month,
        public int $accepted,
        public int $corrected,
        public int $none,
    ) {
    }

    public function total(): int
    {
        return $this->accepted + $this->corrected + $this->none;
    }

    public function coverageRate(): ?float
    {
        return $this->total() > 0 ? ($this->accepted + $this->corrected) / $this->total() : null;
    }

    public function precisionRate(): ?float
    {
        $suggested = $this->accepted + $this->corrected;

        return $suggested > 0 ? $this->accepted / $suggested : null;
    }
}
