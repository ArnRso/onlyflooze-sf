<?php

namespace App\Security\Voter;

use App\Entity\Transaction;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Transaction>
 */
class TransactionVoter extends Voter
{
    public const string VIEW = 'TRANSACTION_VIEW';
    public const string EDIT = 'TRANSACTION_EDIT';
    public const string DELETE = 'TRANSACTION_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE])
            && $subject instanceof Transaction;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Transaction $transaction */
        $transaction = $subject;

        return match ($attribute) {
            self::VIEW => $this->canView($transaction, $user),
            self::EDIT => $this->canEdit($transaction, $user),
            self::DELETE => $this->canDelete($transaction, $user),
            default => false,
        };
    }

    private function canView(Transaction $transaction, User $user): bool
    {
        return $transaction->getUser() === $user;
    }

    private function canEdit(Transaction $transaction, User $user): bool
    {
        return $transaction->getUser() === $user;
    }

    private function canDelete(Transaction $transaction, User $user): bool
    {
        return $transaction->getUser() === $user;
    }
}
