<?php

namespace App\Enum;

/**
 * Sort réservé à la suggestion au moment où l'utilisateur a tranché :
 * c'est la mesure de précision du moteur dans le temps.
 */
enum SuggestionOutcome: string
{
    case Accepted = 'accepted';
    case Corrected = 'corrected';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Accepted => 'Suggestion acceptée',
            self::Corrected => 'Suggestion corrigée',
            self::None => 'Sans suggestion',
        };
    }
}
