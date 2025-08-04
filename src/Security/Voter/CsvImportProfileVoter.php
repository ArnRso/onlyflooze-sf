<?php

namespace App\Security\Voter;

use App\Entity\CsvImportProfile;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class CsvImportProfileVoter extends Voter
{
    public const string VIEW = 'CSV_IMPORT_PROFILE_VIEW';
    public const string EDIT = 'CSV_IMPORT_PROFILE_EDIT';
    public const string DELETE = 'CSV_IMPORT_PROFILE_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE])
            && $subject instanceof CsvImportProfile;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var CsvImportProfile $profile */
        $profile = $subject;

        return match ($attribute) {
            self::VIEW => $this->canView($profile, $user),
            self::EDIT => $this->canEdit($profile, $user),
            self::DELETE => $this->canDelete($profile, $user),
            default => false,
        };
    }

    private function canView(CsvImportProfile $profile, User $user): bool
    {
        return $profile->getUser() === $user;
    }

    private function canEdit(CsvImportProfile $profile, User $user): bool
    {
        return $profile->getUser() === $user;
    }

    private function canDelete(CsvImportProfile $profile, User $user): bool
    {
        return $profile->getUser() === $user;
    }
}