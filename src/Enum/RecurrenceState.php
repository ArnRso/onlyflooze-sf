<?php

namespace App\Enum;

/**
 * État d'une récurrence pour le mois considéré.
 */
enum RecurrenceState: string
{
    case Passed = 'passed';
    case Upcoming = 'upcoming';
    case Late = 'late';
    case Ended = 'ended';
    case NotStarted = 'not_started';

    public function label(): string
    {
        return match ($this) {
            self::Passed => 'Passée',
            self::Upcoming => 'À venir',
            self::Late => 'En retard',
            self::Ended => 'Terminée',
            self::NotStarted => 'Pas encore commencée',
        };
    }

    /**
     * La récurrence est-elle attendue sur le mois considéré ?
     */
    public function isExpectedForMonth(): bool
    {
        return match ($this) {
            self::Ended, self::NotStarted => false,
            default => true,
        };
    }
}
