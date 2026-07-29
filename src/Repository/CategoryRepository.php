<?php

namespace App\Repository;

use App\Entity\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /**
     * @return list<Category>
     */
    public function findRootCategories(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.parent IS NULL')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Ordre alphabétique hiérarchique : chaque poste racine suivi de ses
     * sous-catégories triées, pour tous les selects de catégories.
     *
     * @return list<Category>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.parent', 'p')
            ->addSelect('p')
            ->addSelect('COALESCE(p.name, c.name) AS HIDDEN sortName')
            ->addSelect('CASE WHEN c.parent IS NULL THEN 0 ELSE 1 END AS HIDDEN depthRank')
            ->orderBy('sortName', 'ASC')
            ->addOrderBy('depthRank', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
