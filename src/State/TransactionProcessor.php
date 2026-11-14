<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Transaction;
use App\Repository\TransactionRepository;
use App\Service\SavingMirrorLinker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * PATCH /transactions/{id}: keeps the mirrors of the saving splits (see SavingMirrorLinker), then recomputes
 * the balance of the manual accounts whose mirror transactions changed.
 */
final readonly class TransactionProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private SavingMirrorLinker $savingMirrorLinker,
        private TransactionRepository $transactionRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Transaction
    {
        if (!$data instanceof Transaction) {
            throw new \LogicException('data must be an instance of Transaction');
        }

        $accounts = $this->savingMirrorLinker->link($data);

        $result = $this->persistProcessor->process($data, $operation, $uriVariables, $context);

        if ($accounts) {
            foreach ($accounts as $account) {
                $account->setBalance(
                    $this->transactionRepository->sumByAccount($account),
                    $account->getBalanceCurrency() ?? $data->getCurrency(),
                    'computed',
                    new \DateTimeImmutable(),
                );
            }
            $this->entityManager->flush();
        }

        return $result;
    }
}
