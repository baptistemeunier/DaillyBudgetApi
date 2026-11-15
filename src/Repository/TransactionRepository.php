<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\Transaction;
use App\Entity\TransactionSplit;
use App\Entity\User;
use App\Enum\AccountType;
use App\Enum\CategoryType;
use App\Enum\Currency;
use App\Enum\SplitSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

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
     * DQL condition: the sum of the splits differs from the amount (no split at all included).
     */
    public static function uncategorizedCondition(string $alias): string
    {
        return sprintf(
            '%1$s.amount <> (SELECT COALESCE(SUM(uncategorized_split.amount), 0) FROM %2$s uncategorized_split WHERE uncategorized_split.transaction = %1$s)',
            $alias,
            TransactionSplit::class,
        );
    }

    /**
     * Transactions of the user not fully categorized: the sum of their splits differs from their amount
     * (no split at all included). Optionally limited to the ones booked in [start, end[.
     */
    public function countUncategorized(User $owner, ?\DateTimeImmutable $start = null, ?\DateTimeImmutable $end = null): int
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.account', 'a')
            ->andWhere('a.owner = :owner')
            ->andWhere('a.deletedAt IS NULL')
            ->andWhere(self::uncategorizedCondition('t'))
            ->setParameter('owner', $owner->getId(), UuidType::NAME);

        if (null !== $start) {
            $qb->andWhere('t.bookingAt >= :start')->setParameter('start', $start);
        }

        if (null !== $end) {
            $qb->andWhere('t.bookingAt < :end')->setParameter('end', $end);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Transactions of the user categorized by a rule (at least one split with source "rule"), not yet reviewed.
     */
    public function countAutoCategorized(User $owner): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.account', 'a')
            ->andWhere('a.owner = :owner')
            ->andWhere('a.deletedAt IS NULL')
            ->andWhere(self::autoCategorizedCondition('t', 'auto_source'))
            ->setParameter('owner', $owner->getId(), UuidType::NAME)
            ->setParameter('auto_source', SplitSource::RULE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * DQL condition: the transaction has a split set by a rule. $parameter must be bound to SplitSource::RULE.
     */
    public static function autoCategorizedCondition(string $alias, string $parameter): string
    {
        return sprintf(
            'EXISTS (SELECT auto_split.id FROM %2$s auto_split WHERE auto_split.transaction = %1$s AND auto_split.source = :%3$s)',
            $alias,
            TransactionSplit::class,
            $parameter,
        );
    }

    /**
     * Transactions of the account with a merchant key and without split set or confirmed by the user, with their splits.
     *
     * @return Transaction[]
     */
    public function findCategorizableByAccount(Account $account): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('s')
            ->leftJoin('t.splits', 's')
            ->andWhere('t.account = :account')
            ->andWhere('t.merchantKey IS NOT NULL')
            ->andWhere(sprintf(
                'NOT EXISTS (SELECT user_split.id FROM %s user_split WHERE user_split.transaction = t AND user_split.source IN (:user_sources))',
                TransactionSplit::class,
            ))
            ->setParameter('account', $account->getId(), UuidType::NAME)
            ->setParameter('user_sources', array_map(static fn (SplitSource $source) => $source->value, SplitSource::userSet()))
            ->getQuery()
            ->getResult();
    }

    /**
     * Sum of the categorized splits per root category, over the period.
     * Sub-categories are counted in their root; splits without category are ignored.
     *
     * @return list<array{root: Uuid, type: CategoryType, amount: string, count: int}> amounts are signed (debits negative),
     *         count = number of transactions (a transaction split across sub-categories of a root counts once)
     */
    public function sumSplitsByRootCategory(User $owner, Currency $currency, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('COALESCE(IDENTITY(c.parent), c.id) AS root', 'c.type AS type', 'SUM(s.amount) AS amount', 'COUNT(DISTINCT t.id) AS count')
            ->from(TransactionSplit::class, 's')
            ->join('s.transaction', 't')
            ->join('s.category', 'c')
            ->groupBy('root')
            ->addGroupBy('c.type');

        $rows = $this->filterPeriod($qb, $owner, $currency, $start, $end)->getQuery()->getScalarResult();

        return array_map(static fn (array $row) => [
            // Raw value (binary on MySQL) inside COALESCE: not converted by Doctrine
            'root' => $row['root'] instanceof Uuid ? $row['root'] : Uuid::fromString($row['root']),
            'type' => $row['type'] instanceof CategoryType ? $row['type'] : CategoryType::from($row['type']),
            'amount' => (string) $row['amount'],
            'count' => (int) $row['count'],
        ], $rows);
    }

    /**
     * Sum of the categorized splits per category (no grouping on the root), over the period
     * (no $start: from the beginning), on the accounts of the given type.
     *
     * @return list<array{category: Uuid, type: CategoryType, amount: string, count: int}> amounts are signed (debits negative)
     */
    public function sumSplitsByCategory(User $owner, Currency $currency, ?\DateTimeImmutable $start, \DateTimeImmutable $end, AccountType $accountType = AccountType::CHECKING): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(s.category) AS category', 'c.type AS type', 'SUM(s.amount) AS amount', 'COUNT(DISTINCT t.id) AS count')
            ->from(TransactionSplit::class, 's')
            ->join('s.transaction', 't')
            ->join('s.category', 'c')
            ->groupBy('s.category')
            ->addGroupBy('c.type');

        $rows = $this->filterPeriod($qb, $owner, $currency, $start, $end, $accountType)->getQuery()->getScalarResult();

        return array_map(static fn (array $row) => [
            // Raw value (binary on MySQL): not converted by Doctrine
            'category' => $row['category'] instanceof Uuid ? $row['category'] : Uuid::fromString($row['category']),
            'type' => $row['type'] instanceof CategoryType ? $row['type'] : CategoryType::from($row['type']),
            'amount' => (string) $row['amount'],
            'count' => (int) $row['count'],
        ], $rows);
    }

    /**
     * Number of transactions not fully categorized over the period, debits and credits apart.
     *
     * @return array{debits: int, credits: int}
     */
    public function countUncategorizedBySign(User $owner, Currency $currency, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $counts = $this->filterPeriod(
            $this->createQueryBuilder('t')
                ->select(
                    'COALESCE(SUM(CASE WHEN t.amount < 0 THEN 1 ELSE 0 END), 0) AS debits',
                    'COALESCE(SUM(CASE WHEN t.amount > 0 THEN 1 ELSE 0 END), 0) AS credits',
                )
                ->andWhere(self::uncategorizedCondition('t')),
            $owner, $currency, $start, $end,
        )->getQuery()->getSingleResult();

        return ['debits' => (int) $counts['debits'], 'credits' => (int) $counts['credits']];
    }

    /**
     * Totals over the period: debits / credits, and the part of them assigned to a category.
     *
     * @return array{debits: string, credits: string, categorizedDebits: string, categorizedCredits: string} signed amounts
     */
    public function sumBySign(User $owner, Currency $currency, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $totals = $this->filterPeriod(
            $this->createQueryBuilder('t')->select(
                'COALESCE(SUM(CASE WHEN t.amount < 0 THEN t.amount ELSE 0 END), 0) AS debits',
                'COALESCE(SUM(CASE WHEN t.amount > 0 THEN t.amount ELSE 0 END), 0) AS credits',
            ),
            $owner, $currency, $start, $end,
        )->getQuery()->getSingleResult();

        $categorized = $this->filterPeriod(
            $this->getEntityManager()->createQueryBuilder()
                ->select(
                    'COALESCE(SUM(CASE WHEN t.amount < 0 THEN s.amount ELSE 0 END), 0) AS categorizedDebits',
                    'COALESCE(SUM(CASE WHEN t.amount > 0 THEN s.amount ELSE 0 END), 0) AS categorizedCredits',
                )
                ->from(TransactionSplit::class, 's')
                ->join('s.transaction', 't')
                ->andWhere('s.category IS NOT NULL'),
            $owner, $currency, $start, $end,
        )->getQuery()->getSingleResult();

        return array_map(static fn ($value) => (string) $value, $totals + $categorized);
    }

    /**
     * Sum of the transactions of the account (balance of a manual account).
     */
    public function sumByAccount(Account $account): string
    {
        return (string) ($this->createQueryBuilder('t')
            ->select('COALESCE(SUM(t.amount), 0)')
            ->andWhere('t.account = :account')
            ->setParameter('account', $account->getId(), UuidType::NAME)
            ->getQuery()
            ->getSingleScalarResult());
    }

    /**
     * Transactions of the user's savings accounts over the period, with the categorized part of each one
     * (sum of its splits), for the savings dashboard. Closed accounts are left out.
     *
     * @return list<array{bookingAt: \DateTimeImmutable, amount: string, categorized: string}>
     */
    public function findSavingsMovements(User $owner, Currency $currency, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('t.bookingAt AS bookingAt', 't.amount AS amount')
            ->addSelect(sprintf(
                '(SELECT COALESCE(SUM(movement_split.amount), 0) FROM %s movement_split WHERE movement_split.transaction = t) AS categorized',
                TransactionSplit::class,
            ));

        return array_map(static fn (array $row) => [
            'bookingAt' => $row['bookingAt'],
            'amount' => (string) $row['amount'],
            'categorized' => (string) $row['categorized'],
        ], $this->filterPeriod($qb, $owner, $currency, $start, $end, AccountType::SAVINGS)->getQuery()->getResult());
    }

    /**
     * Transactions (alias "t") of the user's active accounts of the given type (checking by default: savings
     * and investment accounts stay out of the budget), in the currency, booked in [start, end[ (no start: since the beginning).
     */
    private function filterPeriod(QueryBuilder $qb, User $owner, Currency $currency, ?\DateTimeImmutable $start, \DateTimeImmutable $end, AccountType $accountType = AccountType::CHECKING): QueryBuilder
    {
        $qb
            ->join('t.account', 'period_account')
            ->andWhere('period_account.owner = :period_owner')
            ->andWhere('period_account.deletedAt IS NULL')
            ->andWhere('period_account.type = :period_account_type')
            ->andWhere('t.currency = :period_currency')
            ->andWhere('t.bookingAt < :period_end')
            ->setParameter('period_owner', $owner->getId(), UuidType::NAME)
            ->setParameter('period_account_type', $accountType)
            ->setParameter('period_currency', $currency)
            ->setParameter('period_end', $end);

        if (null !== $start) {
            $qb
                ->andWhere('t.bookingAt >= :period_start')
                ->setParameter('period_start', $start);
        }

        return $qb;
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
