<?php

namespace App\Dto;

use App\Entity\CategorizationRule;
use App\Entity\Category;
use App\Enum\MatchConfidence;

/**
 * Résultat de la cascade de matching pour une transaction.
 *
 * Un match ne produit JAMAIS une catégorisation automatique : uniquement une
 * suggestion pré-remplie, à valider en un clic.
 */
final readonly class MatchResult
{
    private function __construct(
        public MatchConfidence $confidence,
        public ?Category $category,
        public ?CategorizationRule $rule,
    ) {
    }

    public static function none(): self
    {
        return new self(MatchConfidence::None, null, null);
    }

    public static function fromRule(MatchConfidence $confidence, CategorizationRule $rule): self
    {
        return new self($confidence, $rule->getCategory(), $rule);
    }

    public static function fromPeriodicity(Category $category): self
    {
        return new self(MatchConfidence::Periodicity, $category, null);
    }

    public static function fromRefundOrigin(Category $category): self
    {
        return new self(MatchConfidence::Refund, $category, null);
    }

    public function isMatch(): bool
    {
        return $this->confidence !== MatchConfidence::None;
    }
}
