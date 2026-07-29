<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class MoneyExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('money', $this->formatMoney(...)),
        ];
    }

    /**
     * Formate un montant en centimes : "1 234,56 €", avec espace insécable
     * fine pour les milliers et espace insécable avant le symbole.
     */
    public function formatMoney(int $amountCents, bool $withSign = false): string
    {
        $sign = match (true) {
            $amountCents < 0 => '−',
            $withSign && $amountCents > 0 => '+',
            default => '',
        };

        $formatted = number_format(abs($amountCents) / 100, 2, ',', "\u{202f}");

        return $sign.$formatted."\u{a0}€";
    }
}
