<?php

namespace App\Command;

use App\Entity\Transaction;
use App\Repository\CategorizationRuleRepository;
use App\Service\TransactionCategorizer;
use App\Util\BankDescription;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Recomputes the description (synced transactions, from their raw data) and the merchant key of every transaction.
 * Idempotent: run it after a change of BankDescription or MerchantKey. --apply-rules then categorizes them by the rules.
 */
#[AsCommand(
    name: 'app:transactions:merchant-keys',
    description: 'Rebuild the description and the merchant key of the transactions (optionally apply the categorization rules)',
)]
final readonly class TransactionMerchantKeyCommand
{
    private const int BATCH_SIZE = 200;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CategorizationRuleRepository $ruleRepository,
        private TransactionCategorizer $categorizer,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Categorize the transactions with the rules of their owner')]
        bool $applyRules = false,
    ): int {
        $query = $this->entityManager->createQueryBuilder()
            ->select('t', 'a')
            ->from(Transaction::class, 't')
            ->join('t.account', 'a')
            ->orderBy('t.id')
            ->getQuery();

        $rulesByOwner = [];
        $count = $categorized = $i = 0;

        /** @var Transaction $transaction */
        foreach ($query->toIterable() as $transaction) {
            $raw = $transaction->getRawData();

            try {
                $raw && !$transaction->getAccount()->isManual()
                    ? BankDescription::apply($transaction, $raw)
                    : $transaction->setDescription($transaction->getDescription()); // recomputes the key
            } catch (\LogicException $exception) {
                $io->warning(sprintf('Transaction %s: %s', $transaction->getId(), $exception->getMessage()));
            }

            if ($applyRules && $owner = $transaction->getAccount()->getOwner()) {
                $rules = $rulesByOwner[$owner->getId()->toRfc4122()] ??= $this->ruleRepository->findByOwner($owner);
                $categorized += (int) $this->categorizer->categorize($transaction, $rules);
            }

            ++$count;
            if (0 === ++$i % self::BATCH_SIZE) {
                $this->entityManager->flush();
                $this->entityManager->clear();
                $rulesByOwner = [];
            }
        }

        $this->entityManager->flush();

        $io->success(sprintf('%d transactions updated%s.', $count, $applyRules ? sprintf(', %d categorized by a rule', $categorized) : ''));

        return 0;
    }
}
