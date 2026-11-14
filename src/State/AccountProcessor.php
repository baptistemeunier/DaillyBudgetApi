<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\CreateManualAccount;
use App\Entity\Account;
use App\Entity\Transaction;
use App\Entity\User;
use App\Util\Money;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * POST /accounts: creates a manual account (no bank, no sync) and its initial transaction.
 */
final readonly class AccountProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private EntityManagerInterface $entityManager,
        private Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Account
    {
        if (!$data instanceof CreateManualAccount) {
            throw new \LogicException('data must be an instance of CreateManualAccount');
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('A user is required to create an account.');
        }

        $balance = Money::fromCents(Money::toCents($data->initialBalance));
        $at = $data->initialBalanceAt->setTime(0, 0);

        $account = new Account()
            ->setName(trim($data->name))
            ->setType($data->type)
            ->setOwner($user)
            ->setBalance($balance, $data->currency, 'computed', $at);

        $initial = new Transaction()
            ->setAccount($account)
            ->setDescription('Solde initial')
            ->setAmount($balance)
            ->setCurrency($data->currency)
            ->setBookingAt($at)
            ->setValueAt($at)
            ->setIsBooked(true);

        $this->entityManager->persist($initial);

        return $this->persistProcessor->process($account, $operation, $uriVariables, $context);
    }
}
