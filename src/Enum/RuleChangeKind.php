<?php

namespace App\Enum;

/**
 * Ce que la consolidation a fait d'une règle.
 */
enum RuleChangeKind: string
{
    case Cleaned = 'cleaned';
    case Rebuilt = 'rebuilt';
    case Split = 'split';
    case Trimmed = 'trimmed';
    case Demoted = 'demoted';
    case Dropped = 'dropped';

    public function label(): string
    {
        return match ($this) {
            self::Cleaned => 'tokens génériques retirés',
            self::Rebuilt => 'reconstruite depuis ses empreintes',
            self::Split => 'empreinte étrangère séparée en règle propre',
            self::Trimmed => 'empreintes retirées (couvertes par une autre règle)',
            self::Demoted => 'rétrogradée en empreintes seules',
            self::Dropped => 'supprimée (plus rien à couvrir)',
        };
    }
}
