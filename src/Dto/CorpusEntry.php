<?php

namespace App\Dto;

/**
 * Une transaction vue par l'analyse de sélectivité des tokens : ses tokens
 * et, si elle a été triée à la main, la catégorie retenue.
 */
final readonly class CorpusEntry
{
    /**
     * @param list<string> $tokens
     */
    public function __construct(
        public array $tokens,
        public ?string $categoryKey = null,
    ) {
    }
}
