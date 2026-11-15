<?php

namespace App\MessageHandler;

use App\Entity\AccountSync;
use App\Enum\AccountSyncStatus;
use App\Message\AccountSyncRequest;
use App\Message\SyncAllAccountsMessage;
use App\Repository\AccountRepository;
use App\Repository\AccountSyncRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues a synchronization for every syncable account (one AccountSyncRequest each, so that an account in error
 * does not block the others). The GoCardless daily quota is still enforced by AccountSyncRequestHandler.
 */
#[AsMessageHandler]
final readonly class SyncAllAccountsMessageHandler
{
    public function __construct(
        private AccountRepository $accountRepository,
        private AccountSyncRepository $accountSyncRepository,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncAllAccountsMessage $message): void
    {
        // A synchronization already queued or running is enough
        $ongoing = $this->accountSyncRepository->findAccountsWithOngoingSync();

        $syncs = [];
        foreach ($this->accountRepository->findSyncable() as $account) {
            if (in_array($account, $ongoing, true)) {
                continue;
            }

            $sync = new AccountSync($account);
            $this->entityManager->persist($sync);
            $account->setSynchronizedStatus(AccountSyncStatus::PENDING);
            $syncs[] = $sync;
        }

        // Flush before dispatching: the worker must find the synchronizations in database
        $this->entityManager->flush();

        foreach ($syncs as $sync) {
            $this->bus->dispatch(new AccountSyncRequest($sync->getId()));
        }

        $this->logger->info('Periodic synchronization queued.', ['accounts' => count($syncs)]);
    }
}
