<?php

namespace App\Dto;

use App\Enum\TransactionType;

/**
 * Résultat de la normalisation d'un libellé bancaire brut.
 *
 * Le matching ne travaille jamais sur le libellé brut : uniquement sur le
 * type, les tokens du marchand et l'empreinte normalisée.
 */
final readonly class NormalizedLabel
{
    /**
     * @param list<string> $tokens
     */
    public function __construct(
        public TransactionType $type,
        public array $tokens,
        public ?\DateTimeImmutable $purchaseDate = null,
    ) {
    }

    public function getFingerprint(): string
    {
        return $this->type->value.'|'.implode(' ', $this->tokens);
    }

    /**
     * @return list<string>
     */
    public static function tokensFromFingerprint(string $fingerprint): array
    {
        $tokens = explode('|', $fingerprint, 2)[1] ?? '';

        return $tokens === '' ? [] : explode(' ', $tokens);
    }
}
