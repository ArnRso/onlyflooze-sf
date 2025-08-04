<?php

namespace App\Repository;

use App\Entity\CsvImportSession;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CsvImportSession>
 */
class CsvImportSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CsvImportSession::class);
    }

    /**
     * @return CsvImportSession[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.user = :user')
            ->setParameter('user', $user)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByUserAndId(User $user, string $id): ?CsvImportSession
    {
        return $this->createQueryBuilder('s')
            ->where('s.user = :user')
            ->andWhere('s.id = :id')
            ->setParameter('user', $user)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return CsvImportSession[]
     */
    public function findRecentByUser(User $user, int $limit = 10): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.user = :user')
            ->setParameter('user', $user)
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array{total_imports: int, total_rows_processed: int, total_successful: int, total_duplicates: int, total_errors: int}
     */
    public function getImportStats(User $user): array
    {
        $result = $this->createQueryBuilder('s')
            ->select('
                COUNT(s.id) as total_imports,
                SUM(s.totalRows) as total_rows_processed,
                SUM(s.successfulImports) as total_successful,
                SUM(s.duplicates) as total_duplicates,
                SUM(s.errors) as total_errors
            ')
            ->where('s.user = :user')
            ->andWhere('s.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', 'completed')
            ->getQuery()
            ->getSingleResult();

        return [
            'total_imports' => (int)($result['total_imports'] ?? 0),
            'total_rows_processed' => (int)($result['total_rows_processed'] ?? 0),
            'total_successful' => (int)($result['total_successful'] ?? 0),
            'total_duplicates' => (int)($result['total_duplicates'] ?? 0),
            'total_errors' => (int)($result['total_errors'] ?? 0),
        ];
    }

    /**
     * @return CsvImportSession[]
     */
    public function findByStatus(string $status): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.status = :status')
            ->setParameter('status', $status)
            ->orderBy('s.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
