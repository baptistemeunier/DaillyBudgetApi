<?php

namespace App\Notification;

use App\Entity\Account;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Util\Money;

/**
 * Notifies transactions of the same kind (LARGE_TRANSACTION, INCOME_RECEIVED) found together (one sync, one run):
 * up to MAX_SEPARATE, one notification each (subject: the transaction); beyond, a single notification grouping
 * them all (subject: the account, data.count).
 */
final readonly class TransactionNotifier
{
    private const int MAX_SEPARATE = 3;

    // A transaction brought by a sync but booked before this is old news (the sync goes back 5 days)
    public const int NEW_TRANSACTION_DAYS = 7;

    public function __construct(private Notifier $notifier)
    {
    }

    /**
     * @param Transaction[]                            $transactions of the account, same currency
     * @param string                                   $group        period of the grouped notification's key (the sync, the run)
     * @param callable(Transaction): array<string, mixed>|null $extra data added to each separate notification
     */
    public function notify(User $owner, NotificationType $type, Account $account, array $transactions, string $group, ?callable $extra = null): void
    {
        if (!$transactions) {
            return;
        }

        $accountData = [
            'accountId' => $account->getId()->toRfc4122(),
            'accountName' => $account->getDisplayName() ?? $account->getName(),
        ];

        if (count($transactions) <= self::MAX_SEPARATE) {
            foreach ($transactions as $transaction) {
                $this->notifier->notify($owner, $type, $transaction, $accountData + [
                    'amount' => $transaction->getAmount(),
                    'currency' => $transaction->getCurrency()?->value,
                    'description' => $transaction->getDescription(),
                    'bookingAt' => $transaction->getBookingAt()?->format('Y-m-d'),
                ] + ($extra ? $extra($transaction) : []), $type->dedupKey($transaction->getId()));
            }

            return;
        }

        $total = array_sum(array_map(static fn (Transaction $transaction) => Money::toCents((string) $transaction->getAmount()), $transactions));
        $this->notifier->notify($owner, $type, $account, $accountData + [
            'count' => count($transactions),
            'total' => Money::fromCents($total),
            'currency' => $transactions[array_key_first($transactions)]->getCurrency()?->value,
        ], $type->dedupKey($account->getId(), $group));
    }
}
