<?php

namespace App\Enum;

enum TransactionNature: string
{
    case Expense = 'expense';
    case Income = 'income';
    case InternalTransfer = 'internal_transfer';
    case Reimbursement = 'reimbursement';

    public static function defaultForAmountCents(int $amountCents): self
    {
        return $amountCents < 0 ? self::Expense : self::Income;
    }

    public function isBudgeted(): bool
    {
        return $this !== self::InternalTransfer;
    }

    public function label(): string
    {
        return match ($this) {
            self::Expense => 'Dépense',
            self::Income => 'Revenu',
            self::InternalTransfer => 'Transfert interne',
            self::Reimbursement => 'Remboursement',
        };
    }
}
