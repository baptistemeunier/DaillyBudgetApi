<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\AccountConnection;
use App\Entity\AccountSync;
use App\Enum\AccountSyncStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccountSync>
 */
/**
 * Entity parameters are bound by id with UuidType: otherwise Doctrine binds the Uuid as its
 * 36 chars string, which never matches the BINARY(16) column.
 */
class AccountSyncRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountSync::class);
    }

    /**
     * Accounts (of the connection, or of every connection) that have a synchronization queued or running.
     *
     * @return Account[]
     */
    public function findAccountsWithOngoingSync(?AccountConnection $accountConnection = null): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('a')
            ->from(Account::class, 'a')
            ->andWhere(sprintf(
                'EXISTS (SELECT s.id FROM %s s WHERE s.account = a AND s.status IN (:statuses))',
                AccountSync::class,
            ))
            ->setParameter('statuses', [AccountSyncStatus::PENDING, AccountSyncStatus::IN_PROGRESS]);

        if (null !== $accountConnection) {
            $qb
                ->andWhere('a.accountConnection = :accountConnection')
                ->setParameter('accountConnection', $accountConnection->getId(), UuidType::NAME);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Number of synchronizations that actually ran (called the bank) since $since.
     * Skipped ones are never started, so they are not counted.
     */
    public function countStartedSince(Account $account, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.account = :account')
            ->andWhere('s.startedAt >= :since')
            ->setParameter('account', $account->getId(), UuidType::NAME)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Status of the last synchronization that actually ran, null if none did.
     */
    public function findLastRunStatus(Account $account): ?AccountSyncStatus
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.account = :account')
            ->andWhere('s.startedAt IS NOT NULL')
            ->setParameter('account', $account->getId(), UuidType::NAME)
            ->orderBy('s.startedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
            ?->getStatus();
    }
}
