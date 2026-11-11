<?php

namespace App\State;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Client\GoCardlessClient;
use App\Dto\CreateAccountConnection;
use App\Entity\AccountConnection;
use App\Entity\User;
use App\Enum\RequisitionStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

readonly class AccountConnectionProcessor implements ProcessorInterface
{
    public function __construct(
        private GoCardlessClient $goCardlessClient,
        private EntityManagerInterface $entityManager,
        private Security $security,
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
}
