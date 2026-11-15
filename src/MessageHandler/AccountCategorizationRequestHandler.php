<?php

namespace App\MessageHandler;

use App\Message\AccountCategorizationRequest;
use App\Repository\AccountRepository;
use App\Repository\CategorizationRuleRepository;
use App\Repository\TransactionRepository;
use App\Service\TransactionCategorizer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Categorizes, with the rules of the owner, the transactions of a synced account that the user has not categorized
 * (no "manual" split). Idempotent: a transaction already categorized by its best rule is left as is.
 */
#[AsMessageHandler]
final readonly class AccountCategorizationRequestHandler
{
    public function __construct(
        private AccountRepository $accountRepository,
        private CategorizationRuleRepository $ruleRepository,
        private TransactionRepository $transactionRepository,
        private TransactionCategorizer $categorizer,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(AccountCategorizationRequest $message): void
    {
        $account = $this->accountRepository->find($message->getId());
        $owner = $account?->getOwner();

        if (!$account || !$owner || $account->getDeletedAt() !== null || $account->isManual()) {
            $this->logger->warning('Account categorization skipped: account not found, deleted or manual.', [
                'account_id' => $message->getId()->toRfc4122(),
            ]);

            return;
        }

        $rules = $this->ruleRepository->findByOwner($owner);
        if (!$rules) {
            return;
        }

        $count = 0;
        foreach ($this->transactionRepository->findCategorizableByAccount($account) as $transaction) {
            if ($this->categorizer->categorize($transaction, $rules)) {
                ++$count;
            }
        }

        $this->entityManager->flush();

        $this->logger->info('Account categorization completed.', [
            'account_id' => $message->getId()->toRfc4122(),
            'categorized' => $count,
        ]);
    }
}
