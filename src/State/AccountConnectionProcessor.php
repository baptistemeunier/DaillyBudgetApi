<?php

namespace App\State;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Client\GoCardlessClient;
use App\Dto\CreateAccountConnection;
use App\Entity\Account;
use App\Entity\AccountConnection;
use App\Entity\User;
use App\Enum\AccountStatus;
use App\Enum\AccountSyncStatus;
use App\Enum\RequisitionStatus;
use App\Message\AccountSyncRequest;
use App\Repository\AccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

readonly class AccountConnectionProcessor implements ProcessorInterface
{
    public function __construct(
        private GoCardlessClient $goCardlessClient,
        private AccountRepository $accountRepository,
        private EntityManagerInterface $entityManager,
        private Security $security,
        private MessageBusInterface $bus
    )
    {

    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?AccountConnection
    {
        if($operation instanceof Post) {
            if (!$data instanceof CreateAccountConnection) {
                throw new \LogicException("data must be an instance of CreateAccountConnection");
            }

            return $this->createConnection($data);
        }

        if (!$data instanceof AccountConnection) {
            throw new \LogicException("data must be an instance of AccountConnection");
        }

        if($operation instanceof Patch) {
            return $this->syncConnection($data);
        }

        if($operation instanceof Delete) {
            return $this->deleteConnection($data);
        }

        throw new \LogicException("operation must be an instance of Patch, Post or Delete");
    }

    /**
     * @param mixed $data
     * @return AccountConnection
     */
    public function createConnection(CreateAccountConnection $data): AccountConnection
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \LogicException("User not found");
        }

        $accountConnection = new AccountConnection();
        $requisition = $this->goCardlessClient->createRequisition([
            "redirect" => $data->redirectUri,
            "institution_id" => $data->bankId,
            "reference" => $accountConnection->getId()->toString(),
            "user_language" => "EN"
        ]);

        $accountConnection
            ->setBankId($data->bankId)
            ->setOwner($user)
            ->setExternalId(Uuid::fromString($requisition["id"]))
            ->setExternalLink($requisition['link'])
            ->setExternalStatus(RequisitionStatus::tryFrom($requisition['status']));

        $this->entityManager->persist($accountConnection);
        $this->entityManager->flush();

        return $accountConnection;
    }

    /**
     * @param mixed $accountConnection
     * @return AccountConnection
     */
    public function syncConnection(AccountConnection $accountConnection): AccountConnection
    {
        $requisition = $this->goCardlessClient->getRequisition($accountConnection->getExternalId());

        $accounts = $this->accountRepository->findBy([
            'accountConnection' => $accountConnection
        ]);

        foreach ($requisition['accounts'] as $accountId) {
            $account = array_find($accounts, function (Account $account) use ($accountId) {
                return $account->getExternalId()->equals(Uuid::fromString($accountId));
            });

            $externalAccount = $this->goCardlessClient->getAccount($accountId);

            if(!$account) {
                $account = new Account()
                    ->setName($externalAccount['name'])
                    ->setIban($externalAccount['iban'])
                    ->setOwner($accountConnection->getOwner())
                    ->setExternalId(Uuid::fromString($externalAccount['id']))
                    ->setBankId($externalAccount['institution_id'])
                    ->setAccountConnection($accountConnection);

                $this->entityManager->persist($account);
            }

            $account
                ->setExternalUpdatedAt(new \DateTimeImmutable($externalAccount['last_accessed']))
                ->setExternalStatus(AccountStatus::tryFrom($externalAccount['status']));

            if($this->needAccountSync($account)) {
                $account->setSynchronizedStatus(AccountSyncStatus::PENDING);
                $this->bus->dispatch(new AccountSyncRequest($account->getId()));
            }
        }

        $accountConnection
            ->setExternalStatus(RequisitionStatus::tryFrom($requisition['status']));

        $this->entityManager->flush();

        return $accountConnection;
    }

    /**
     * @param mixed $accountConnection
     * @return null
     */
    public function deleteConnection(AccountConnection $accountConnection): null
    {
        if ($accountConnection->getDeletedAt()) {
            throw new NotFoundHttpException("Account connection was not found");
        }

        $this->goCardlessClient->deleteRequisition($accountConnection->getExternalId());

        $accountConnection->setDeletedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return null;
    }

    private function needAccountSync(mixed $account): bool
    {
        return true;
    }
}
