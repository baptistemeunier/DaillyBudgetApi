<?php

namespace App\Repository;

use App\Entity\Transaction;
use App\Entity\TransactionSplit;
use App\Entity\User;
use App\Enum\AccountType;
use App\Enum\CategoryType;
use App\Enum\Currency;
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
     * (no split at all included).
     */
    public function countUncategorized(User $owner): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.account', 'a')
            ->andWhere('a.owner = :owner')
            ->andWhere('a.deletedAt IS NULL')
            ->andWhere(self::uncategorizedCondition('t'))
            ->setParameter('owner', $owner->getId(), UuidType::NAME)
            ->getQuery()
            ->getSingleScalarResult();
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
     * Sum of the categorized splits per category (no grouping on the root), over the period.
     *
     * @return list<array{category: Uuid, type: CategoryType, amount: string, count: int}> amounts are signed (debits negative)
     */
    public function sumSplitsByCategory(User $owner, Currency $currency, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(s.category) AS category', 'c.type AS type', 'SUM(s.amount) AS amount', 'COUNT(DISTINCT t.id) AS count')
            ->from(TransactionSplit::class, 's')
            ->join('s.transaction', 't')
            ->join('s.category', 'c')
            ->groupBy('s.category')
            ->addGroupBy('c.type');

        $rows = $this->filterPeriod($qb, $owner, $currency, $start, $end)->getQuery()->getScalarResult();

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
     * Transactions (alias "t") of the user's active checking accounts, in the currency, booked in [start, end[.
     * Savings and investment accounts stay out of the budget.
     */
    private function filterPeriod(QueryBuilder $qb, User $owner, Currency $currency, \DateTimeImmutable $start, \DateTimeImmutable $end): QueryBuilder
    {
        return $qb
            ->join('t.account', 'period_account')
            ->andWhere('period_account.owner = :period_owner')
            ->andWhere('period_account.deletedAt IS NULL')
            ->andWhere('period_account.type = :period_account_type')
            ->andWhere('t.currency = :period_currency')
            ->andWhere('t.bookingAt >= :period_start')
            ->andWhere('t.bookingAt < :period_end')
            ->setParameter('period_owner', $owner->getId(), UuidType::NAME)
            ->setParameter('period_account_type', AccountType::CHECKING)
            ->setParameter('period_currency', $currency)
            ->setParameter('period_start', $start)
            ->setParameter('period_end', $end);
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
