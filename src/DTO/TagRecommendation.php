<?php

namespace App\DTO;


use App\Entity\Tag;

/**
 * DTO représentant une recommandation de tag pour une transaction.
 */
readonly class TagRecommendation
{
    /**
     * @param Tag $tag Le tag recommandé
     * @param float $confidence Niveau de confiance de la recommandation (0-100%)
     * @param string $reason Raison de la recommandation (pour le debug/UI)
     */
    public function __construct(
        private Tag    $tag,
        private float  $confidence,
        private string $reason = ''
    )
    {
    }

    public function getTag(): Tag
    {
        return $this->tag;
    }

    public function getConfidence(): float
    {
        return $this->confidence;
    }

    public function getConfidencePercentage(): int
    {
        return (int)round($this->confidence);
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
