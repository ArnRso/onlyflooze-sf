<?php

namespace App\Exception;

class CsvParseException extends \RuntimeException
{
    public static function forLine(int $lineNumber, string $reason): self
    {
        return new self(sprintf('Ligne %d : %s', $lineNumber, $reason));
    }
}
