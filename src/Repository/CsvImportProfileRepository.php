<?php

namespace App\Repository;

use App\Entity\CsvImportProfile;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CsvImportProfile>
 */
class CsvImportProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CsvImportProfile::class);
    }

    /**
     * @return CsvImportProfile[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.user = :user')
            ->setParameter('user', $user)
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByUserAndId(User $user, string $id): ?CsvImportProfile
    {
        return $this->createQueryBuilder('p')
            ->where('p.user = :user')
            ->andWhere('p.id = :id')
            ->setParameter('user', $user)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function getCountByUser(User $user): int
    {
        return (int)$this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return CsvImportProfile[]
     */
    public function findRecentlyUsed(User $user, int $limit = 5): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.importSessions', 's')
            ->where('p.user = :user')
            ->setParameter('user', $user)
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
