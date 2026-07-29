<?php

namespace App\Service\Catalog;

use App\Entity\CategorizationRule;
use Doctrine\ORM\EntityManagerInterface;

class RuleManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(CategorizationRule $rule): void
    {
        $this->entityManager->persist($rule);
        $this->entityManager->flush();
    }

    public function delete(CategorizationRule $rule): void
    {
        $this->entityManager->remove($rule);
        $this->entityManager->flush();
    }
}
