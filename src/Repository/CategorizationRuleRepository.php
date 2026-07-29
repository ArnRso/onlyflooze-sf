<?php

namespace App\Repository;

use App\Entity\CategorizationRule;
use App\Entity\Category;
use App\Enum\Direction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CategorizationRule>
 */
class CategorizationRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CategorizationRule::class);
    }

    /**
     * @return list<CategorizationRule>
     */
    public function findByDirection(Direction $direction): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.direction = :direction')
            ->setParameter('direction', $direction)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<CategorizationRule>
     */
    public function findByCategoryAndDirection(Category $category, Direction $direction): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.category = :category')
            ->andWhere('r.direction = :direction')
            ->setParameter('category', $category)
            ->setParameter('direction', $direction)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<CategorizationRule>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
