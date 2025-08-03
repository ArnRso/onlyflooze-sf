<?php

namespace App\Repository;

use App\Entity\RecurringTransaction;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\UuidInterface;

/**
 * @extends ServiceEntityRepository<RecurringTransaction>
 */
class RecurringTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecurringTransaction::class);
    }

    /**
     * @return RecurringTransaction[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('rt')
            ->where('rt.user = :user')
            ->setParameter('user', $user)
            ->orderBy('rt.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByUserAndId(User $user, UuidInterface $id): ?RecurringTransaction
    {
        return $this->createQueryBuilder('rt')
            ->where('rt.user = :user')
            ->andWhere('rt.id = :id')
            ->setParameter('user', $user)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function getCountByUser(User $user): int
    {
        return (int)$this->createQueryBuilder('rt')
            ->select('COUNT(rt.id)')
            ->where('rt.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
