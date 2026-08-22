<?php

namespace App\Service\Matching;

use App\Repository\TransactionRepository;

/**
 * Point d'entrée de la sélectivité des tokens : quels tokens du corpus sont
 * génériques, et comment en débarrasser une liste de tokens.
 *
 * La liste est recalculée à chaque appel sur le corpus courant : elle suit
 * donc l'évolution des données sans état à maintenir.
 */
class TokenSelectivity
{
    public function __construct(
        private readonly TransactionRepository $transactionRepository,
        private readonly GenericTokenDetector $detector,
    ) {
    }

    /**
     * @return list<string>
     */
    public function genericTokens(): array
    {
        return $this->detector->detect($this->transactionRepository->findCorpusEntries());
    }

    /**
     * @param list<string> $tokens
     * @param list<string> $generic
     *
     * @return list<string>
     */
    public static function discriminant(array $tokens, array $generic): array
    {
        return array_values(array_unique(array_diff($tokens, $generic)));
    }
}
