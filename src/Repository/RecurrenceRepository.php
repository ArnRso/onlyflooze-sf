<?php

namespace App\Repository;

use App\Entity\Recurrence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Recurrence>
 */
class RecurrenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Recurrence::class);
    }

    /**
     * @return list<Recurrence>
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.active = true')
            ->orderBy('r.expectedDayOfMonth', 'ASC')
            ->addOrderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Recurrence>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.expectedDayOfMonth', 'ASC')
            ->addOrderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
