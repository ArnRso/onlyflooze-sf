<?php

namespace App\Service\Recurrence;

use App\Entity\Recurrence;
use Doctrine\ORM\EntityManagerInterface;

class RecurrenceManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(Recurrence $recurrence): void
    {
        $this->entityManager->persist($recurrence);
        $this->entityManager->flush();
    }

    public function delete(Recurrence $recurrence): void
    {
        $this->entityManager->remove($recurrence);
        $this->entityManager->flush();
    }
}
