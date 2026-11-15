<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\QueryParameter;
use App\Doctrine\Filter\ReadFilter;
use App\Entity\Traits\TimestampableTrait;
use App\Entity\Traits\UuidTrait;
use App\Enum\NotificationType;
use App\Repository\NotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Something that happened and the user should know (event), unlike a UserAction (current state).
 * Only created by the server, through App\Notification\Notifier. The subject (at most one of account,
 * accountConnection, transaction, category) is exposed as a HAL link; the client composes the text from type + data.
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_NOTIFICATION_DEDUP_KEY', columns: ['owner_id', 'dedup_key'])]
#[ORM\Index(name: 'IDX_NOTIFICATION_OWNER_CREATED_AT', columns: ['owner_id', 'created_at'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new Get(security: "is_granted('NOTIFICATION_READ', object)"),
        // Inbox of the user, latest first (scoped by CurrentUserExtension)
        // Unread badge: ?read=false&itemsPerPage=1, then totalItems
        new GetCollection(
            order: ['createdAt' => 'DESC', 'id' => 'DESC'],
            security: "is_granted('NOTIFICATION_READ')",
            parameters: [
                'read' => new QueryParameter(
                    schema: ['type' => 'boolean'],
                    filter: new ReadFilter(),
                    description: 'true: only the read notifications, false: only the unread ones',
                ),
            ],
        ),
        // { "read": true } marks it as read, { "read": false } as unread
        new Patch(
            denormalizationContext: ['groups' => ['notification:update']],
            security: "is_granted('NOTIFICATION_EDIT', object)",
        ),
        new Delete(security: "is_granted('NOTIFICATION_DELETE', object)"),
    ],
    normalizationContext: ['groups' => ['notification:read', 'timestamp:read']],
)]
class Notification
{
    use UuidTrait;
    use TimestampableTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(length: 50, enumType: NotificationType::class)]
    #[Groups(['notification:read'])]
    private ?NotificationType $type = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[Groups(['notification:read'])]
    private ?Account $account = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[Groups(['notification:read'])]
    private ?AccountConnection $accountConnection = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[Groups(['notification:read'])]
    private ?Transaction $transaction = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[Groups(['notification:read'])]
    private ?Category $category = null;

    /**
     * Parameters of the text, depending on the type: count, month, daysLeft, expiresAt, bankId...
     */
    #[ORM\Column(type: Types::JSON)]
    #[Groups(['notification:read'])]
    #[ApiProperty(example: ['count' => 8, 'month' => '2026-11', 'daysLeft' => 5])]
    private array $data = [];

    /**
     * "{TYPE}_{subject uuid}[_{period}]", unique per owner: prevents sending the same notification twice.
     */
    #[ORM\Column(length: 255)]
    private ?string $dedupKey = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['notification:read'])]
    private ?\DateTimeImmutable $readAt = null;

    /**
     * Null until the push is sent, or when there is none.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $pushedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function getType(): ?NotificationType
    {
        return $this->type;
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function getAccountConnection(): ?AccountConnection
    {
        return $this->accountConnection;
    }

    public function getTransaction(): ?Transaction
    {
        return $this->transaction;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getDedupKey(): ?string
    {
        return $this->dedupKey;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    #[Groups(['notification:read'])]
    public function isRead(): bool
    {
        return null !== $this->readAt;
    }

    /**
     * Marking as read keeps the first read date; marking as unread clears it.
     */
    #[Groups(['notification:update'])]
    public function setRead(bool $read): static
    {
        if (!$read) {
            $this->readAt = null;
        } elseif (null === $this->readAt) {
            $this->readAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getPushedAt(): ?\DateTimeImmutable
    {
        return $this->pushedAt;
    }

    public function setPushedAt(?\DateTimeImmutable $pushedAt): static
    {
        $this->pushedAt = $pushedAt;

        return $this;
    }
}
