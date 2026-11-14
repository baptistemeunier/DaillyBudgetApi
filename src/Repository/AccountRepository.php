<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Account>
 */
class AccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
    }

    /**
     * Accounts of the user matching the given GoCardless account ids, whatever their connection.
     *
     * @param Uuid[] $externalIds
     * @return Account[]
     */
    public function findByOwnerAndExternalIds(User $owner, array $externalIds): array
    {
        if (!$externalIds) {
            return [];
        }

        $queryBuilder = $this->createQueryBuilder('a')
            ->andWhere('a.owner = :owner')
            ->setParameter('owner', $owner->getId(), UuidType::NAME);

        // One typed parameter per id: arrays of Uuid are not converted to the database format by Doctrine
        $conditions = [];
        foreach (array_values($externalIds) as $i => $externalId) {
            $conditions[] = "a.externalId = :externalId$i";
            $queryBuilder->setParameter("externalId$i", $externalId, UuidType::NAME);
        }

        return $queryBuilder
            ->andWhere($queryBuilder->expr()->orX(...$conditions))
            ->getQuery()
            ->getResult();
    }

//    /**
//     * @return Account[] Returns an array of Account objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('a')
//            ->andWhere('a.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('a.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?Account
//    {
//        return $this->createQueryBuilder('a')
//            ->andWhere('a.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}
