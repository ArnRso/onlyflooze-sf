<?php

namespace App\Service\Review;

use App\Dto\ClassifiedLookupResult;
use App\Repository\TransactionRepository;

/**
 * Aide au tri : « qu'ai-je déjà décidé pour CHRONO ? ». Recherche dans les
 * transactions déjà catégorisées, par sous-chaîne du libellé.
 */
class ClassifiedLookup
{
    private const int RECENT_LIMIT = 15;

    public function __construct(
        private readonly TransactionRepository $transactionRepository,
    ) {
    }

    public function lookup(string $query): ?ClassifiedLookupResult
    {
        $query = trim($query);
        if ($query === '') {
            return null;
        }

        $byCategory = $this->transactionRepository->countClassifiedByCategory($query);

        return new ClassifiedLookupResult(
            $query,
            array_sum(array_column($byCategory, 'count')),
            $byCategory,
            $this->transactionRepository->findClassifiedMatching($query, self::RECENT_LIMIT),
        );
    }
}
