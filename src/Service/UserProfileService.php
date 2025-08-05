<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

readonly class UserProfileService
{
    public function __construct(
        private EntityManagerInterface      $entityManager,
        private UserPasswordHasherInterface $passwordHasher
    )
    {
    }

    public function updateProfile(User $user, string $firstName, string $lastName): void
    {
        $user->setFirstName($firstName);
        $user->setLastName($lastName);

        $this->entityManager->flush();
    }

    public function changePassword(User $user, string $newPassword): void
    {
        $hashedPassword = $this->passwordHasher->hashPassword($user, $newPassword);
        $user->setPassword($hashedPassword);

        $this->entityManager->flush();
    }

    public function verifyCurrentPassword(User $user, string $currentPassword): bool
    {
        return $this->passwordHasher->isPasswordValid($user, $currentPassword);
    }
}
