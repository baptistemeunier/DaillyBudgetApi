<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\AccountConnection;
use App\Enum\UserActionType;
use App\State\UserActionProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Something the user has to deal with (expired bank, transactions to categorize...), ordered by priority.
 * Related resources are exposed as HAL links.
 */
#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/user_actions',
            paginationEnabled: false,
            security: "is_granted('ACCOUNT_CONNECTION_READ') or is_granted('ACCOUNT_READ')",
        ),
    ],
    normalizationContext: ['groups' => ['user-action:read']],
    provider: UserActionProvider::class,
)]
class UserAction
{
    #[ApiProperty(identifier: true, example: 'CONNECTION_EXPIRED-0193a1b2-7c3d-7e4f-8a9b-0c1d2e3f4a5b')]
    #[Groups(['user-action:read'])]
    public string $id;

    #[Groups(['user-action:read'])]
    public UserActionType $type;

    #[Groups(['user-action:read'])]
    public int $priority;

    #[Groups(['user-action:read'])]
    public ?AccountConnection $accountConnection = null;

    #[Groups(['user-action:read'])]
    public ?Bank $bank = null;

    /**
     * End of the bank access (connection actions).
     */
    #[Groups(['user-action:read'])]
    public ?\DateTimeImmutable $expiresAt = null;

    /**
     * Number of items concerned (transactions to categorize).
     */
    #[Groups(['user-action:read'])]
    public ?int $count = null;

    public function __construct(UserActionType $type, string $id)
    {
        $this->type = $type;
        $this->priority = $type->priority();
        $this->id = $id;
    }

    public static function forConnection(UserActionType $type, AccountConnection $connection): self
    {
        $action = new self($type, sprintf('%s-%s', $type->value, $connection->getId()));
        $action->accountConnection = $connection;
        $action->bank = new Bank()->setId($connection->getBankId());
        $action->expiresAt = $connection->getAccessExpiresAt();

        return $action;
    }
}
