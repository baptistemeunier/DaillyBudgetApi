<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\User;
use App\Enum\AccountStatus;
use App\Enum\RequisitionStatus;
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

    /**
     * Active accounts of the user synced with a bank (not manual).
     *
     * @return Account[]
     */
    public function findSyncedByOwner(User $owner): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.owner = :owner')
            ->andWhere('a.deletedAt IS NULL')
            ->andWhere('a.externalId IS NOT NULL')
            ->setParameter('owner', $owner->getId(), UuidType::NAME)
            ->getQuery()
            ->getResult();
    }

    /**
     * Accounts the periodic sync can refresh: synced with a bank (not manual), not deleted, not expired at the bank,
     * on a non-deleted connection that is linked and whose access has not expired.
     * Accounts with a synchronization queued or running are left to it (see AccountSyncRepository).
     *
     * @return Account[]
     */
    public function findSyncable(\DateTimeImmutable $now = new \DateTimeImmutable()): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.accountConnection', 'c')
            ->andWhere('a.deletedAt IS NULL')
            ->andWhere('a.externalId IS NOT NULL')
            ->andWhere('a.externalStatus IS NULL OR a.externalStatus <> :expired_account')
            ->andWhere('c.deletedAt IS NULL')
            ->andWhere('c.externalStatus = :linked')
            ->andWhere('c.accessExpiresAt IS NULL OR c.accessExpiresAt > :now')
            ->setParameter('expired_account', AccountStatus::EXPIRED)
            ->setParameter('linked', RequisitionStatus::LINKED)
            ->setParameter('now', $now)
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
