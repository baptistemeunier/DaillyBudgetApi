<?php

namespace App\Repository;

use App\Entity\CategorizationRule;
use App\Entity\User;
use App\Enum\TransactionDirection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;

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
     * @return CategorizationRule[] the rules of the user, with their category
     */
    public function findByOwner(User $owner): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('c')
            ->join('r.category', 'c')
            ->andWhere('r.owner = :owner')
            ->setParameter('owner', $owner->getId(), UuidType::NAME)
            ->getQuery()
            ->getResult();
    }

    /**
     * Same owner, pattern and direction (null included: the unique index lets several NULL through).
     */
    public function findOneSame(User $owner, string $pattern, ?TransactionDirection $direction): ?CategorizationRule
    {
        $qb = $this->createQueryBuilder('r')
            ->andWhere('r.owner = :owner')
            ->andWhere('r.pattern = :pattern')
            ->setParameter('owner', $owner->getId(), UuidType::NAME)
            ->setParameter('pattern', $pattern)
            ->setMaxResults(1);

        null === $direction
            ? $qb->andWhere('r.direction IS NULL')
            : $qb->andWhere('r.direction = :direction')->setParameter('direction', $direction);

        return $qb->getQuery()->getOneOrNullResult();
    }
}
