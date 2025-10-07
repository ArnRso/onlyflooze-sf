<?php

namespace App\DTO;


use App\Entity\RecurringTransaction;

/**
 * DTO représentant une recommandation de transaction récurrente pour une transaction.
 */
readonly class RecurringTransactionRecommendation
{
    /**
     * @param RecurringTransaction $recurringTransaction La transaction récurrente recommandée
     * @param float $confidence Niveau de confiance de la recommandation (0-100%)
     * @param string $reason Raison de la recommandation (pour le debug/UI)
     */
    public function __construct(
        private RecurringTransaction $recurringTransaction,
        private float                $confidence,
        private string               $reason = ''
    )
    {
    }

    public function getRecurringTransaction(): RecurringTransaction
    {
        return $this->recurringTransaction;
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
