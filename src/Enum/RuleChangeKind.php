<?php

namespace App\Enum;

/**
 * Ce que la consolidation a fait d'une règle.
 */
enum RuleChangeKind: string
{
    case Cleaned = 'cleaned';
    case Rebuilt = 'rebuilt';
    case Demoted = 'demoted';
    case Dropped = 'dropped';

    public function label(): string
    {
        return match ($this) {
            self::Cleaned => 'tokens génériques retirés',
            self::Rebuilt => 'reconstruite depuis ses empreintes',
            self::Demoted => 'rétrogradée en empreintes seules',
            self::Dropped => 'supprimée (empreintes couvertes par une autre règle)',
        };
    }
}
