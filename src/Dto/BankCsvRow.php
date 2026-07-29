<?php

namespace App\Dto;

/**
 * Une ligne brute du relevé bancaire, après parsing et normalisation des
 * montants/dates. Le montant est signé, en centimes (négatif = débit).
 */
final readonly class BankCsvRow
{
    public function __construct(
        public \DateTimeImmutable $operationDate,
        public \DateTimeImmutable $valueDate,
        public string $label,
        public int $amountCents,
    ) {
    }
}
