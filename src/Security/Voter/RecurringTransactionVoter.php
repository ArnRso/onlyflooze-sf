<?php

namespace App\Security\Voter;

use App\Entity\RecurringTransaction;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, RecurringTransaction>
 */
class RecurringTransactionVoter extends Voter
{
    public const string VIEW = 'RECURRING_TRANSACTION_VIEW';
    public const string EDIT = 'RECURRING_TRANSACTION_EDIT';
    public const string DELETE = 'RECURRING_TRANSACTION_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE])
            && $subject instanceof RecurringTransaction;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var RecurringTransaction $recurringTransaction */
        $recurringTransaction = $subject;

        return match ($attribute) {
            self::VIEW => $this->canView($recurringTransaction, $user),
            self::EDIT => $this->canEdit($recurringTransaction, $user),
            self::DELETE => $this->canDelete($recurringTransaction, $user),
            default => false,
        };
    }

    private function canView(RecurringTransaction $recurringTransaction, User $user): bool
    {
        return $recurringTransaction->getUser() === $user;
    }

    private function canEdit(RecurringTransaction $recurringTransaction, User $user): bool
    {
        return $recurringTransaction->getUser() === $user;
    }

    private function canDelete(RecurringTransaction $recurringTransaction, User $user): bool
    {
        return $recurringTransaction->getUser() === $user;
    }
}