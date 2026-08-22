<?php

namespace App\Dto;

use App\Enum\RuleChangeKind;

/**
 * Compte rendu d'une consolidation des règles : ce qui a été jugé générique,
 * ce qui a changé, combien de suggestions ont bougé derrière.
 */
final class ConsolidationReport
{
    /** @var list<RuleChange> */
    public array $changes = [];

    public int $suggestionsUpdated = 0;

    /**
     * @param list<string> $genericTokens
     */
    public function __construct(
        public readonly array $genericTokens,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->changes === [] && $this->suggestionsUpdated === 0;
    }

    public function count(RuleChangeKind $kind): int
    {
        return \count(array_filter($this->changes, static fn (RuleChange $change): bool => $change->kind === $kind));
    }

    public function summary(): string
    {
        $parts = [];
        foreach (RuleChangeKind::cases() as $kind) {
            $count = $this->count($kind);
            if ($count > 0) {
                $parts[] = sprintf('%d règle(s) : %s', $count, $kind->label());
            }
        }
        $parts[] = sprintf('%d suggestion(s) mise(s) à jour', $this->suggestionsUpdated);

        return implode(', ', $parts).'.';
    }
}
