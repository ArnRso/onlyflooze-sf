<?php

namespace App\Enum;

/**
 * Niveau de la cascade de matching ayant produit le résultat, du plus sûr
 * au moins sûr.
 */
enum MatchConfidence: string
{
    case Exact = 'exact';
    case Token = 'token';
    case Fuzzy = 'fuzzy';
    case Periodicity = 'periodicity';
    case Refund = 'refund';
    case None = 'none';
}
