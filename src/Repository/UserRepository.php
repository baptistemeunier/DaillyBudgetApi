<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\AccountConnection;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Ids of the users with at least one bank connection or account not deleted: the only ones who can get
     * the daily notifications.
     *
     * @return Uuid[]
     */
    public function findIdsWithActiveBanking(): array
    {
        $rows = $this->createQueryBuilder('u')
            ->select('u.id')
            ->andWhere(sprintf(
                '(EXISTS (SELECT c.id FROM %s c WHERE c.owner = u AND c.deletedAt IS NULL) OR EXISTS (SELECT a.id FROM %s a WHERE a.owner = u AND a.deletedAt IS NULL))',
                AccountConnection::class,
                Account::class,
            ))
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row) => $row['id'] instanceof Uuid ? $row['id'] : Uuid::fromString($row['id']), $rows);
    }

//    /**
//     * @return User[] Returns an array of User objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('u')
//            ->andWhere('u.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('u.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?User
//    {
//        return $this->createQueryBuilder('u')
//            ->andWhere('u.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}
