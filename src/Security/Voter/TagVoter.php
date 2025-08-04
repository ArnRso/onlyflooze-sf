<?php

namespace App\Security\Voter;

use App\Entity\Tag;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Tag>
 */
class TagVoter extends Voter
{
    public const string VIEW = 'TAG_VIEW';
    public const string EDIT = 'TAG_EDIT';
    public const string DELETE = 'TAG_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE])
            && $subject instanceof Tag;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Tag $tag */
        $tag = $subject;

        return match ($attribute) {
            self::VIEW => $this->canView($tag, $user),
            self::EDIT => $this->canEdit($tag, $user),
            self::DELETE => $this->canDelete($tag, $user),
            default => false,
        };
    }

    private function canView(Tag $tag, User $user): bool
    {
        return $tag->getUser() === $user;
    }

    private function canEdit(Tag $tag, User $user): bool
    {
        return $tag->getUser() === $user;
    }

    private function canDelete(Tag $tag, User $user): bool
    {
        return $tag->getUser() === $user;
    }
}
