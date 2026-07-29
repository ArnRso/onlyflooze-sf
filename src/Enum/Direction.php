<?php

namespace App\Enum;

enum Direction: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    public static function fromAmountCents(int $amountCents): self
    {
        return $amountCents < 0 ? self::Debit : self::Credit;
    }
}
