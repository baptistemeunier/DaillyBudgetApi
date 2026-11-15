<?php

namespace App\MessageHandler;

use App\Client\GoCardlessClient;
use App\Client\GoCardlessAccessExpiredException;
use App\Client\GoCardlessRateLimitException;
use App\Entity\Account;
use App\Entity\AccountSync;
use App\Entity\Transaction;
use App\Enum\AccountSyncError;
use App\Enum\AccountSyncStatus;
use App\Enum\Currency;
use App\Enum\RequisitionStatus;
use App\Message\AccountCategorizationRequest;
use App\Message\AccountSyncRequest;
use App\Repository\AccountSyncRepository;
use App\Repository\TransactionRepository;
use App\Util\BankDescription;
use App\Util\Money;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

#[AsMessageHandler]
final class AccountSyncRequestHandler
{
    // GoCardless allows 4 transaction calls per account per 24h
    private const int MAX_SYNCS_PER_DAY = 4;

    // Balance kept when the bank gives several: booked ones first, then the available ones
    private const array BALANCE_TYPE_PRIORITY = ['closingBooked', 'interimBooked', 'expected', 'interimAvailable', 'forwardAvailable'];

    public function __construct(
        private readonly AccountSyncRepository $accountSyncRepository,
        private readonly TransactionRepository $transactionRepository,
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $entityManager,
        private GoCardlessClient $goCardlessClient,
        private ManagerRegistry $doctrine,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function __invoke(AccountSyncRequest $message): void
    {
        $syncId = $message->getId();
        $sync = $this->accountSyncRepository->find($syncId);
        $account = $sync?->getAccount();

        if (!$sync || !$account || $account->getDeletedAt() !== null) {
            $this->logger->warning('Account synchronization skipped: synchronization or account not found or deleted.', [
                'account_sync_id' => $syncId,
            ]);

            return;
        }

        $accountId = $account->getId();

        if ($sync->getStatus() !== AccountSyncStatus::PENDING) {
            $this->logger->warning('Account synchronization skipped: synchronization already processed.', [
                'account_sync_id' => $syncId,
                'status' => $sync->getStatus()->value,
            ]);

            return;
        }

        if ($this->accountSyncRepository->countStartedSince($account, new DateTimeImmutable('-24 hours')) >= self::MAX_SYNCS_PER_DAY) {
            // The account keeps the status of its last real synchronization
            $sync->skip(AccountSyncError::DAILY_LIMIT_REACHED);
            $account->setSynchronizedStatus($this->accountSyncRepository->findLastRunStatus($account) ?? AccountSyncStatus::SKIPPED);
            $this->entityManager->flush();

            $this->logger->info('Account synchronization skipped: daily limit reached.', [
                'account_sync_id' => $syncId,
                'account_id' => $accountId,
            ]);

            return;
        }

        $this->logger->info('Starting account synchronization.', [
            'account_sync_id' => $syncId,
            'account_id' => $accountId,
        ]);

        $sync->start();
        $account->setSynchronizedStatus(AccountSyncStatus::IN_PROGRESS);
        $this->entityManager->flush();

        try {
            $this->syncTransactions($account);
            $this->syncBalance($account);

            $sync->succeed();
            $account
                ->setSynchronizedStatus(AccountSyncStatus::SUCCESS)
                ->setSynchronizedAt($sync->getFinishedAt());

            $this->entityManager->flush();

            $this->logger->info('Account synchronization completed successfully.', [
                'account_sync_id' => $syncId,
                'account_id' => $accountId,
            ]);

        } catch (\Throwable $exception) {
            $this->logger->error('Account synchronization failed: {exception}', [
                'account_sync_id' => $syncId,
                'account_id' => $accountId,
                'exception' => $exception,
            ]);

            // The entity manager may be closed or hold half-synced transactions: start from a clean one
            $em = $this->doctrine->resetManager();
            $sync = $em->getRepository(AccountSync::class)->find($syncId);

            if ($sync !== null) {
                $sync->fail($this->toError($exception));
                $failedAccount = $sync->getAccount()->setSynchronizedStatus(AccountSyncStatus::FAILED);

                // GoCardless keeps the requisition "LN" after the agreement expiry: flag it so the client knows it must reconnect
                if ($exception instanceof GoCardlessAccessExpiredException) {
                    $failedAccount->getAccountConnection()?->setExternalStatus(RequisitionStatus::EXPIRED);
                }

                $em->flush();
            }

            return;
        }

        // Outside the try: a dispatch failure must not mark the synchronization as failed
        $this->bus->dispatch(new AccountCategorizationRequest($accountId));
    }

    private function toError(\Throwable $exception): AccountSyncError
    {
        return match (true) {
            $exception instanceof GoCardlessRateLimitException => AccountSyncError::RATE_LIMITED,
            $exception instanceof GoCardlessAccessExpiredException => AccountSyncError::ACCESS_EXPIRED,
            $exception instanceof HttpExceptionInterface
                && in_array($exception->getResponse()->getStatusCode(), [401, 403], true) => AccountSyncError::ACCESS_EXPIRED,
            $exception instanceof \LogicException,
            $exception instanceof \ValueError,
            $exception instanceof \DateMalformedStringException => AccountSyncError::INVALID_DATA,
            default => AccountSyncError::UNKNOWN,
        };
    }

    private function syncTransactions(Account $account)
    {
        $dateFrom = $account->getSynchronizedAt()?->sub(new \DateInterval('P5D'))->format('Y-m-d');
        $transactions = $this->goCardlessClient->getAccountTransactions($account->getExternalId(), $dateFrom);

        $this->syncTransactionsType($account, $transactions['transactions']['booked'], true);
        #$this->syncTransactionsType($account, $transactions['transactions']['pending'], false);
    }

    /**
     * Stores the balance reported by the bank. The balances endpoint has its own (tight) GoCardless quota:
     * hitting it keeps the previous balance without failing the sync.
     */
    private function syncBalance(Account $account): void
    {
        try {
            $balances = $this->goCardlessClient->getAccountBalances($account->getExternalId())['balances'] ?? [];
        } catch (GoCardlessRateLimitException $exception) {
            $this->logger->warning('Balance rate limited, previous balance kept.', [
                'account_id' => $account->getId()?->toRfc4122(),
                'exception' => $exception,
            ]);

            return;
        }

        if (!$balances) {
            return;
        }

        usort($balances, static function (array $a, array $b): int {
            $rank = static fn (array $balance) => array_search($balance['balanceType'] ?? null, self::BALANCE_TYPE_PRIORITY, true);
            $rankA = $rank($a);
            $rankB = $rank($b);

            return ($rankA === false ? PHP_INT_MAX : $rankA) <=> ($rankB === false ? PHP_INT_MAX : $rankB);
        });
        $balance = $balances[0];

        $amount = (string) ($balance['balanceAmount']['amount'] ?? '');
        $currency = Currency::tryFrom($balance['balanceAmount']['currency'] ?? '');
        if (!Money::isDecimal($amount) || null === $currency) {
            // Unknown currency or format: better no balance than a wrong one
            $this->logger->warning('Unsupported balance, not stored.', [
                'account_id' => $account->getId()?->toRfc4122(),
                'balance' => $balance,
            ]);

            return;
        }

        $date = $balance['referenceDate'] ?? $balance['lastChangeDateTime'] ?? null;

        $account->setBalance(
            Money::fromCents(Money::toCents($amount)),
            $currency,
            (string) ($balance['balanceType'] ?? 'unknown'),
            $date ? new DateTimeImmutable($date) : new DateTimeImmutable(),
        );
    }

    /**
     * @throws \DateMalformedStringException
     */
    private function syncTransactionsType(Account $account, array $externalTransactions, bool $booked): void
    {
        foreach ($externalTransactions as $transaction) {
            foreach (['internalTransactionId', 'bookingDate', 'transactionAmount'] as $key) {
                if(empty($transaction[$key])) {
                    throw new \LogicException(sprintf('"%s" is required.', $key));
                }
            }

            foreach (['amount', 'currency'] as $key) {
                if(empty($transaction['transactionAmount'][$key])) {
                    throw new \LogicException(sprintf('"%s" in transactionAmount is required.', $key));
                }
            }
        }

        $transactions = $this->transactionRepository->findBy([
            'externalId' => array_map(fn ($transaction) => $transaction["internalTransactionId"], $externalTransactions),
        ]);

        foreach ($externalTransactions as $externalTransaction) {
            $transaction = array_find($transactions, fn ($transaction) => $transaction->getExternalId() === $externalTransaction['internalTransactionId']);

            if(!$transaction) {
                $transaction = new Transaction()
                ->setExternalId($externalTransaction['internalTransactionId'])
                ->setReference($externalTransaction['entryReference'] ?? null)
                ->setAmount($externalTransaction['transactionAmount']['amount'])
                ->setCurrency(Currency::from($externalTransaction['transactionAmount']['currency']))
                ->setValueAt(new DateTimeImmutable($externalTransaction['valueDate'] ?? $externalTransaction['bookingDate']))
                #->setExternalCreatedAt()
                #->setExternalTransactionType()
                ->setExternalTransactionCode($externalTransaction['bankTransactionCode'] ?? null)
                ->setAccount($account)
                ->setRawData($externalTransaction);

                BankDescription::apply($transaction, $externalTransaction);
                $this->entityManager->persist($transaction);
            }

            $transaction
                ->setBookingAt(!empty($externalTransaction['bookingDate']) ? new DateTimeImmutable($externalTransaction['bookingDate']) : null)
                ->setIsBooked($booked);
        }
    }
}
