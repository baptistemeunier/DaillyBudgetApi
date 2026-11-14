<?php

namespace App\Repository;

use App\Entity\BudgetLine;
use App\Entity\Category;
use App\Enum\CategoryType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BudgetLine>
 */
class BudgetLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BudgetLine::class);
    }

    /**
     * Budgets only take active root expense categories: removes the lines on any other category
     * (deleted, turned into a sub-category or changed type by a catalog sync).
     *
     * @return int number of removed lines
     */
    public function deleteInvalidLines(): int
    {
        return $this->createQueryBuilder('l')
            ->delete()
            ->where(sprintf(
                'l.category IN (SELECT c.id FROM %s c WHERE c.deletedAt IS NOT NULL OR c.parent IS NOT NULL OR c.type <> :expense)',
                Category::class,
            ))
            ->setParameter('expense', CategoryType::EXPENSE)
            ->getQuery()
            ->execute();
    }
}
