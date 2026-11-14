<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SortFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\QueryParameter;
use App\Entity\Traits\TimestampableTrait;
use App\Entity\Traits\UuidTrait;
use App\Enum\AccountSyncError;
use App\Enum\AccountSyncStatus;
use App\Repository\AccountSyncRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * One synchronization attempt of an account (history).
 * createdAt is the date the synchronization was requested.
 */
#[ORM\Entity(repositoryClass: AccountSyncRepository::class)]
#[ORM\Index(name: 'IDX_ACCOUNT_SYNC_ACCOUNT_CREATED_AT', columns: ['account_id', 'created_at'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new Get(security: "is_granted('ACCOUNT_READ', object.getAccount())"),
        new GetCollection(
            uriTemplate: '/accounts/{id}/syncs',
            uriVariables: [
                'id' => new Link(
                    toProperty: 'account',
                    fromClass: Account::class,
                    security: "is_granted('ACCOUNT_READ', account)",
                    securityObjectName: 'account',
                ),
            ],
            order: ['createdAt' => 'DESC', 'id' => 'DESC'],
            // Latest sync: ?order[createdAt]=desc&itemsPerPage=1
            parameters: [
                'order[createdAt]' => new QueryParameter(
                    filter: new SortFilter(),
                    property: 'createdAt',
                    description: 'Sort by creation date (asc or desc)',
                ),
            ],
        ),
    ],
    normalizationContext: ['groups' => ['account-sync:read', 'timestamp:read']],
)]
class AccountSync
{
    use UuidTrait;
    use TimestampableTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Account $account = null;

    #[ORM\Column(enumType: AccountSyncStatus::class)]
    #[Groups(['account-sync:read'])]
    private AccountSyncStatus $status = AccountSyncStatus::PENDING;

    #[ORM\Column(nullable: true, enumType: AccountSyncError::class)]
    #[Groups(['account-sync:read'])]
    private ?AccountSyncError $error = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['account-sync:read'])]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['account-sync:read'])]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(Account $account)
    {
        $this->id = Uuid::v7();
        $this->account = $account;
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function getStatus(): AccountSyncStatus
    {
        return $this->status;
    }

    public function getError(): ?AccountSyncError
    {
        return $this->error;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function skip(AccountSyncError $error): static
    {
        $this->status = AccountSyncStatus::SKIPPED;
        $this->error = $error;
        $this->finishedAt = new \DateTimeImmutable();

        return $this;
    }

    public function start(): static
    {
        $this->status = AccountSyncStatus::IN_PROGRESS;
        $this->startedAt = new \DateTimeImmutable();

        return $this;
    }

    public function succeed(): static
    {
        $this->status = AccountSyncStatus::SUCCESS;
        $this->error = null;
        $this->finishedAt = new \DateTimeImmutable();

        return $this;
    }

    public function fail(AccountSyncError $error): static
    {
        $this->status = AccountSyncStatus::FAILED;
        $this->error = $error;
        $this->finishedAt = new \DateTimeImmutable();

        return $this;
    }
}
