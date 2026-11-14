<?php

namespace App\MessageHandler;

use App\Client\GoCardlessClient;
use App\Client\GoCardlessRateLimitException;
use App\Entity\Account;
use App\Entity\AccountSync;
use App\Entity\Transaction;
use App\Enum\AccountSyncError;
use App\Enum\AccountSyncStatus;
use App\Enum\Currency;
use App\Message\AccountSyncRequest;
use App\Repository\AccountSyncRepository;
use App\Repository\TransactionRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

#[AsMessageHandler]
final class AccountSyncRequestHandler
{
    // GoCardless allows 4 transaction calls per account per 24h
    private const int MAX_SYNCS_PER_DAY = 4;

    public function __construct(
        private readonly AccountSyncRepository $accountSyncRepository,
        private readonly TransactionRepository $transactionRepository,
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $entityManager,
        private GoCardlessClient $goCardlessClient,
        private ManagerRegistry $doctrine,
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
            $this->logger->error('Account synchronization failed.', [
                'account_sync_id' => $syncId,
                'account_id' => $accountId,
                'exception' => $exception,
            ]);

            // The entity manager may be closed or hold half-synced transactions: start from a clean one
            $em = $this->doctrine->resetManager();
            $sync = $em->getRepository(AccountSync::class)->find($syncId);

            if ($sync !== null) {
                $sync->fail($this->toError($exception));
                $sync->getAccount()->setSynchronizedStatus(AccountSyncStatus::FAILED);
                $em->flush();
            }
        }
    }

    private function toError(\Throwable $exception): AccountSyncError
    {
        return match (true) {
            $exception instanceof GoCardlessRateLimitException => AccountSyncError::RATE_LIMITED,
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
     * @throws \DateMalformedStringException
     */
    private function syncTransactionsType(Account $account, array $externalTransactions, bool $booked): void
    {
        foreach ($externalTransactions as $transaction) {
            foreach (['transactionId', 'valueDate', 'transactionAmount'] as $key) {
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

            $description = $this->getDescription($externalTransaction);
            if(!$transaction) {
                $transaction = new Transaction()
                ->setExternalId($externalTransaction['internalTransactionId'])
                ->setReference($externalTransaction['entryReference'] ?? null)
                ->setDescription($description)
                #->setDetail()
                ->setAmount($externalTransaction['transactionAmount']['amount'])
                ->setCurrency(Currency::from($externalTransaction['transactionAmount']['currency']))
                ->setValueAt($externalTransaction['valueDate'] ? new DateTimeImmutable($externalTransaction['valueDate']) : null)
                #->setExternalCreatedAt()
                #->setExternalTransactionType()
                ->setExternalTransactionCode($externalTransaction['bankTransactionCode'] ?? null)
                ->setAccount($account)
                ->setRawData($externalTransaction);

                $this->entityManager->persist($transaction);
            }

            $transaction
                ->setBookingAt(!empty($externalTransaction['bookingDate']) ? new DateTimeImmutable($externalTransaction['bookingDate']) : null)
                ->setIsBooked($booked);
        }
    }

    private function getDescription(array $transaction): string
    {
        if(!empty($transaction['remittanceInformationUnstructuredArray'])) {
            return reset($transaction['remittanceInformationUnstructuredArray']);
        }

        if(!empty($transaction['remittanceInformationUnstructured'])) {
            return $transaction['remittanceInformationUnstructured'];
        }

        throw new \LogicException('Unable to determine transaction description.');
    }
}
