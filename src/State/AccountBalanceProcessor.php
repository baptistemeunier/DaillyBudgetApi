<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\UpdateManualBalance;
use App\Entity\Account;
use App\Entity\Transaction;
use App\Repository\AccountRepository;
use App\Repository\TransactionRepository;
use App\Util\Money;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /accounts/{id}/balance (UpdateManualBalance): sets the real balance of a manual account at a date.
 * The difference with the sum of its transactions up to that date is booked as an "Ajustement de solde" transaction
 * (none when there is no difference), so the balance always stays the sum of the transactions.
 */
final readonly class AccountBalanceProcessor implements ProcessorInterface
{
    public function __construct(
        private AccountRepository $accountRepository,
        private TransactionRepository $transactionRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Account
    {
        if (!$data instanceof UpdateManualBalance) {
            throw new \LogicException('data must be an instance of UpdateManualBalance');
        }

        // Already loaded (and checked) by the provider: same managed instance
        $account = $this->accountRepository->find($uriVariables['id'] ?? null);
        if (!$account || null !== $account->getDeletedAt()) {
            throw new NotFoundHttpException('Account not found.');
        }

        if (!$account->isManual()) {
            throw new UnprocessableEntityHttpException('Only a manual account balance can be updated.');
        }

        $currency = $account->getBalanceCurrency()
            ?? throw new \LogicException('A manual account always has a balance currency.');
        $at = ($data->balanceAt ?? new \DateTimeImmutable())->setTime(0, 0);

        $diff = Money::toCents($data->balance)
            - Money::toCents($this->transactionRepository->sumByAccount($account, $at));

        if (0 !== $diff) {
            $adjustment = new Transaction()
                ->setAccount($account)
                ->setDescription('Ajustement de solde')
                ->setAmount(Money::fromCents($diff))
                ->setCurrency($currency)
                ->setBookingAt($at)
                ->setValueAt($at)
                ->setIsBooked(true);

            $this->entityManager->persist($adjustment);
            $this->entityManager->flush();
        }

        $account->setBalance(
            $this->transactionRepository->sumByAccount($account),
            $currency,
            'computed',
            new \DateTimeImmutable(),
        );
        $this->entityManager->flush();

        return $account;
    }
}
