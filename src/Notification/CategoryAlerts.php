<?php

namespace App\Notification;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\AccountType;
use App\Enum\CategoryType;
use App\Enum\NotificationType;
use App\Repository\BudgetRepository;
use App\Repository\NotificationRepository;
use App\Repository\TransactionRepository;
use App\Util\Money;

/**
 * Alerts based on the categories, on the transactions a rule just categorized after a sync
 * (AccountCategorizationRequestHandler with alerts):
 * - INCOME_RECEIVED for the new credits categorized as income;
 * - BUDGET_ALMOST_EXCEEDED (80 %) / BUDGET_EXCEEDED (100 %) for the budgeted root categories they touch.
 * A manual categorization sends nothing: the user just saw it; it is reported by the next sync if it still holds.
 */
final readonly class CategoryAlerts
{
    private const int BUDGET_ALMOST_PERCENT = 80;

    public function __construct(
        private TransactionNotifier $transactionNotifier,
        private Notifier $notifier,
        private BudgetRepository $budgetRepository,
        private TransactionRepository $transactionRepository,
        private NotificationRepository $notificationRepository,
    ) {
    }

    /**
     * @param Transaction[] $transactions transactions of the account categorized by this run
     */
    public function notify(User $owner, Account $account, array $transactions): void
    {
        if (!$transactions) {
            return;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->notifyIncome($owner, $account, $transactions, $now);
        $this->notifyBudget($owner, $account, $transactions, $now);
    }

    /**
     * @param Transaction[] $transactions
     */
    private function notifyIncome(User $owner, Account $account, array $transactions, \DateTimeImmutable $now): void
    {
        $since = $now->modify(sprintf('-%d days', TransactionNotifier::NEW_TRANSACTION_DAYS));
        $incomes = array_values(array_filter($transactions, fn (Transaction $transaction) => Money::toCents((string) $transaction->getAmount()) > 0
            && $transaction->getBookingAt() >= $since
            && null !== $this->incomeCategory($transaction)));

        $extra = function (Transaction $transaction) {
            $category = $this->incomeCategory($transaction);

            return ['categoryCode' => $category?->getCode(), 'categoryName' => $category?->getName()];
        };
        foreach ($this->notifier->recipients(NotificationType::INCOME_RECEIVED, $owner) as $recipient) {
            $this->transactionNotifier->notify($recipient, NotificationType::INCOME_RECEIVED, $account, $incomes, $now->format('YmdHis'), $extra);
        }
    }

    /**
     * Only the checking accounts count in the budget, and only the spending booked this month.
     *
     * @param Transaction[] $transactions
     */
    private function notifyBudget(User $owner, Account $account, array $transactions, \DateTimeImmutable $now): void
    {
        $budget = $this->budgetRepository->findShared();
        if (AccountType::CHECKING !== $account->getType() || null === $budget) {
            return;
        }

        $start = $now->modify('first day of this month')->setTime(0, 0);
        $end = $start->modify('+1 month');

        /** @var array<string, Category> $roots root expense categories touched this month */
        $roots = [];
        foreach ($transactions as $transaction) {
            if ($transaction->getBookingAt() < $start || $transaction->getBookingAt() >= $end) {
                continue;
            }
            foreach ($transaction->getSplits() as $split) {
                $root = $split->getCategory()?->getParent() ?? $split->getCategory();
                if (null !== $root && CategoryType::EXPENSE === $root->getType()) {
                    $roots[$root->getId()->toRfc4122()] = $root;
                }
            }
        }

        if (!$roots) {
            return;
        }

        // Same query as the statement (GET /statements/{period}): expenses are negative
        $spentByRoot = [];
        foreach ($this->transactionRepository->sumSplitsByRootCategory($budget->getCurrency(), $start, $end) as $row) {
            $spentByRoot[$row['root']->toRfc4122()] = -Money::toCents($row['amount']);
        }

        $month = $now->format('Y-m');
        foreach ($budget->getLines() as $line) {
            $root = $roots[$line->getCategory()?->getId()?->toRfc4122() ?? ''] ?? null;
            $budgetedCents = null !== $line->getAmount() ? Money::toCents($line->getAmount()) : 0;
            if (null === $root || $budgetedCents <= 0) {
                continue;
            }

            $spentCents = $spentByRoot[$root->getId()->toRfc4122()] ?? 0;
            $exceededKey = NotificationType::BUDGET_EXCEEDED->dedupKey($root->getId(), $month);

            $type = match (true) {
                $spentCents > $budgetedCents => NotificationType::BUDGET_EXCEEDED,
                // Already over 100 % this month (then a refund): the 80 % notice would come too late
                $spentCents * 100 >= $budgetedCents * self::BUDGET_ALMOST_PERCENT
                    && !$this->notificationRepository->existsByDedupKey($owner, $exceededKey) => NotificationType::BUDGET_ALMOST_EXCEEDED,
                default => null,
            };

            if (null === $type) {
                continue;
            }

            $this->notifier->notify($owner, $type, $root, [
                'month' => $month,
                'spent' => Money::fromCents($spentCents),
                'budgeted' => Money::fromCents($budgetedCents),
                'percent' => intdiv($spentCents * 100, $budgetedCents),
                'currency' => $budget->getCurrency()->value,
                'categoryCode' => $root->getCode(),
                'categoryName' => $root->getName(),
            ], $type->dedupKey($root->getId(), $month));
        }
    }

    /**
     * Category of the transaction whose type (or its root's) is income, if any.
     */
    private function incomeCategory(Transaction $transaction): ?Category
    {
        foreach ($transaction->getSplits() as $split) {
            $category = $split->getCategory();
            if (null !== $category && CategoryType::INCOME === ($category->getParent() ?? $category)->getType()) {
                return $category;
            }
        }

        return null;
    }
}
