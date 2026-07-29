<?php

namespace App\Enum;

enum CategorySource: string
{
    case Unclassified = 'unclassified';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Unclassified => 'À trier',
            self::Manual => 'Manuel',
        };
    }
}
