<?php

namespace App\Repository;

use App\Entity\Transaction;
use App\Entity\TransactionSplit;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * @extends ServiceEntityRepository<Transaction>
 */
class TransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }

    /**
     * Transactions of the user not fully categorized: the sum of their splits differs from their amount
     * (no split at all included).
     */
    public function countUncategorized(User $owner): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.account', 'a')
            ->andWhere('a.owner = :owner')
            ->andWhere('a.deletedAt IS NULL')
            ->andWhere(sprintf(
                't.amount <> (SELECT COALESCE(SUM(s.amount), 0) FROM %s s WHERE s.transaction = t)',
                TransactionSplit::class,
            ))
            ->setParameter('owner', $owner->getId(), UuidType::NAME)
            ->getQuery()
            ->getSingleScalarResult();
    }

//    /**
//     * @return Transaction[] Returns an array of Transaction objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('t')
//            ->andWhere('t.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('t.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?Transaction
//    {
//        return $this->createQueryBuilder('t')
//            ->andWhere('t.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}
