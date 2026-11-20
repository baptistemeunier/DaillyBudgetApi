<?php

namespace App\MessageHandler;

use App\Entity\Account;
use App\Entity\AccountSync;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\AccountSyncError;
use App\Enum\AccountSyncStatus;
use App\Enum\AccountType;
use App\Enum\NotificationType;
use App\Message\AccountAlertsRequest;
use App\Notification\Notifier;
use App\Notification\TransactionNotifier;
use App\Repository\AccountSyncRepository;
use App\Repository\NotificationSettingsRepository;
use App\Repository\TransactionRepository;
use App\Service\BankNameResolver;
use App\Util\Money;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Alerts that follow a synchronization and don't need the categories:
 * - CONNECTION_EXPIRED when the bank refused the sync;
 * - BALANCE_LOW when the balance of a checking account crossed its threshold downwards;
 * - LARGE_TRANSACTION for the new debits above the user's threshold.
 * Never sent for the first sync of an account (see AccountSyncRequestHandler).
 */
#[AsMessageHandler]
final readonly class AccountAlertsRequestHandler
{
    public function __construct(
        private AccountSyncRepository $accountSyncRepository,
        private TransactionRepository $transactionRepository,
        private NotificationSettingsRepository $settingsRepository,
        private Notifier $notifier,
        private TransactionNotifier $transactionNotifier,
        private BankNameResolver $bankNameResolver,
    ) {
    }

    public function __invoke(AccountAlertsRequest $message): void
    {
        $sync = $this->accountSyncRepository->find($message->getSyncId());
        $account = $sync?->getAccount();
        $owner = $account?->getOwner();

        if (!$sync || !$account || !$owner || null !== $account->getDeletedAt() || $account->isManual()) {
            return;
        }

        if (AccountSyncStatus::FAILED === $sync->getStatus() && AccountSyncError::ACCESS_EXPIRED === $sync->getError()) {
            $this->notifyConnectionExpired($owner, $account);

            return;
        }

        if (AccountSyncStatus::SUCCESS !== $sync->getStatus()) {
            return;
        }

        $this->notifyBalanceLow($owner, $account, $message->getBalanceBefore());
        $this->notifyLargeTransactions($owner, $account, $sync);
    }

    /**
     * Same key as the daily task: sent only once per connection, whichever comes first.
     */
    private function notifyConnectionExpired(User $owner, Account $account): void
    {
        $connection = $account->getAccountConnection();
        if (!$connection || null !== $connection->getDeletedAt()) {
            return;
        }

        // Only the owner of the connection can renew it
        $type = NotificationType::CONNECTION_EXPIRED;
        $this->notifier->notify($connection->getOwner() ?? $owner, $type, $connection, [
            'bankId' => $connection->getBankId(),
            'bankName' => $this->bankNameResolver->resolve($connection->getBankId()),
            'expiresAt' => $connection->getAccessExpiresAt()?->format(\DateTimeInterface::ATOM),
        ], $type->dedupKey($connection->getId()));
    }

    /**
     * On the crossing only (before ≥ threshold > after): a balance that stays low sends nothing. At most once a day,
     * if the balance swings around the threshold between two syncs.
     */
    private function notifyBalanceLow(User $owner, Account $account, ?string $balanceBefore): void
    {
        $threshold = $account->getLowBalanceThreshold();
        $balance = $account->getBalance();

        if (AccountType::CHECKING !== $account->getType() || null === $threshold || null === $balance || null === $balanceBefore) {
            return;
        }

        $thresholdCents = Money::toCents($threshold);
        if (!(Money::toCents($balanceBefore) >= $thresholdCents && $thresholdCents > Money::toCents($balance))) {
            return;
        }

        $type = NotificationType::BALANCE_LOW;
        $this->notifier->notify($owner, $type, $account, [
            'accountId' => $account->getId()->toRfc4122(),
            'accountName' => $account->getDisplayName() ?? $account->getName(),
            'balance' => $balance,
            'threshold' => $threshold,
            'currency' => $account->getBalanceCurrency()?->value,
        ], $type->dedupKey($account->getId(), new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format('Y-m-d')));
    }

    private function notifyLargeTransactions(User $owner, Account $account, AccountSync $sync): void
    {
        if (null === $sync->getStartedAt() || null === $sync->getFinishedAt()) {
            return;
        }

        $transactions = $this->transactionRepository->findCreatedDuring(
            $account,
            $sync->getStartedAt(),
            // finishedAt is set just before the flush that gives the transactions their createdAt
            $sync->getFinishedAt()->modify('+1 minute'),
            new \DateTimeImmutable(sprintf('-%d days', TransactionNotifier::NEW_TRANSACTION_DAYS)),
        );

        // Each recipient with their own threshold
        foreach ($this->notifier->recipients(NotificationType::LARGE_TRANSACTION, $owner) as $recipient) {
            $thresholdCents = Money::toCents((string) $this->settingsRepository->findOrDefault($recipient)->getLargeTransactionThreshold());
            $large = array_values(array_filter(
                $transactions,
                static fn (Transaction $transaction) => -Money::toCents((string) $transaction->getAmount()) >= $thresholdCents,
            ));

            $this->transactionNotifier->notify($recipient, NotificationType::LARGE_TRANSACTION, $account, $large, $sync->getId()->toRfc4122());
        }
    }
}
