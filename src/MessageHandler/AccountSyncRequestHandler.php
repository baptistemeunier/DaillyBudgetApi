<?php

namespace App\MessageHandler;

use App\Client\GoCardlessClient;
use App\Entity\Account;
use App\Entity\Transaction;
use App\Enum\AccountSyncStatus;
use App\Enum\Currency;
use App\Message\AccountSyncRequest;
use App\Repository\AccountRepository;
use App\Repository\TransactionRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class AccountSyncRequestHandler
{
    public function __construct(
        private readonly AccountRepository $accountRepository,
        private readonly TransactionRepository $transactionRepository,
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $entityManager,
        private GoCardlessClient $goCardlessClient,
        private ManagerRegistry $doctrine,
    ) {
    }

    public function __invoke(AccountSyncRequest $message): void
    {
        $accountId = $message->getId();

        $this->logger->info('Starting account synchronization.', [
            'account_id' => $accountId,
        ]);

        $account = $this->accountRepository->find($accountId);

        if (!$account || $account->getDeletedAt() !== null) {
            $this->logger->warning('Account synchronization skipped: account not found or deleted.', [
                'account_id' => $accountId,
            ]);

            return;
        }

        try {
            $this->syncTransactions($account);

            $account
                ->setSynchronizedStatus(AccountSyncStatus::SUCCESS)
                ->setSynchronizedAt(new DateTimeImmutable());

            $this->entityManager->flush();

            $this->logger->info('Account synchronization completed successfully.', [
                'account_id' => $accountId,
            ]);

        } catch (\Throwable $exception) {
            $this->logger->error('Account synchronization failed.', [
                'account_id' => $accountId,
                'exception' => $exception,
            ]);

            dump($exception);
            $em = $this->doctrine->resetManager();
            $account = $em->getRepository(Account::class)->find($accountId);

            if ($account !== null) {
                $account->setSynchronizedStatus(AccountSyncStatus::FAILED);
                $em->flush();
            }
        }
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
            foreach (['bankTransactionCode', 'transactionId', 'entryReference', 'valueDate', 'remittanceInformationUnstructured', 'transactionAmount'] as $key) {
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
                ->setReference($externalTransaction['entryReference'])
                ->setDescription($externalTransaction['remittanceInformationUnstructured'])
                #->setDetail()
                ->setAmount($externalTransaction['transactionAmount']['amount'])
                ->setCurrency(Currency::from($externalTransaction['transactionAmount']['currency']))
                ->setValueAt($externalTransaction['valueDate'] ? new DateTimeImmutable($externalTransaction['valueDate']) : null)
                #->setExternalCreatedAt()
                #->setExternalTransactionType()
                ->setExternalTransactionCode($externalTransaction['bankTransactionCode'])
                ->setAccount($account)
                ->setRawData($externalTransaction);

                $this->entityManager->persist($transaction);
            }

            $transaction
                ->setBookingAt(!empty($externalTransaction['bookingDate']) ? new DateTimeImmutable($externalTransaction['bookingDate']) : null)
                ->setIsBooked($booked);
        }
    }
}
