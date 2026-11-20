<?php

namespace App\Repository;

use App\Entity\CategorizationRule;
use App\Enum\TransactionDirection;
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
     * @return CategorizationRule[] all the rules (shared between the users), with their category
     */
    public function findAllWithCategory(): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('c')
            ->join('r.category', 'c')
            ->getQuery()
            ->getResult();
    }

    /**
     * Same pattern and direction (null included: the unique index lets several NULL through). Rules are shared between all the users.
     */
    public function findOneSame(string $pattern, ?TransactionDirection $direction): ?CategorizationRule
    {
        $qb = $this->createQueryBuilder('r')
            ->andWhere('r.pattern = :pattern')
            ->setParameter('pattern', $pattern)
            ->setMaxResults(1);

        null === $direction
            ? $qb->andWhere('r.direction IS NULL')
            : $qb->andWhere('r.direction = :direction')->setParameter('direction', $direction);

        return $qb->getQuery()->getOneOrNullResult();
    }
}
