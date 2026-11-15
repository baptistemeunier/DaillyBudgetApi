<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Traits\TimestampableTrait;
use App\Entity\Traits\UuidTrait;
use App\Enum\NotificationType;
use App\Repository\NotificationSettingsRepository;
use App\State\NotificationSettingsProcessor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Notification preferences of a user (one per user, created on the first change, like Budget).
 * Without settings, or for a type not set, the defaults of the catalog apply (NotificationType::defaultPush()).
 */
#[ORM\Entity(repositoryClass: NotificationSettingsRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_OWNER', columns: ['owner_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    // Rarely changes: the client keeps it and always revalidates it with If-None-Match (304 when unchanged)
    operations: [
        new Get(security: "is_granted('NOTIFICATION_SETTINGS_READ', object)"),
        // The settings of the user, if any: 0 or 1 item (scoped by CurrentUserExtension)
        new GetCollection(security: "is_granted('NOTIFICATION_SETTINGS_READ')"),
        // 422 when the user already has settings
        new Post(
            security: "is_granted('NOTIFICATION_SETTINGS_EDIT')",
            processor: NotificationSettingsProcessor::class,
        ),
        new Patch(security: "is_granted('NOTIFICATION_SETTINGS_EDIT', object)"),
    ],
    cacheHeaders: ['max_age' => 0, 'must_revalidate' => true],
    normalizationContext: ['groups' => ['notification-settings:read', 'timestamp:read']],
    denormalizationContext: ['groups' => ['notification-settings:write']],
)]
class NotificationSettings
{
    use UuidTrait;
    use TimestampableTrait;

    public const string DEFAULT_LARGE_TRANSACTION_THRESHOLD = '200.00';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    /**
     * Push preference chosen by the user, per type (only the types they changed).
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $push = [];

    /**
     * A debit above this amount is a large transaction (LARGE_TRANSACTION, coming with the alerts).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    #[Groups(['notification-settings:read', 'notification-settings:write'])]
    #[Assert\NotBlank]
    #[Assert\Regex('/^\d{1,13}(\.\d{1,2})?$/', message: 'The amount must be a positive decimal with at most 2 digits.')]
    #[Assert\Positive]
    private ?string $largeTransactionThreshold = self::DEFAULT_LARGE_TRANSACTION_THRESHOLD;

    /**
     * Pushes are shown on the lock screen: true keeps the amounts out of their title and body.
     */
    #[ORM\Column]
    #[Groups(['notification-settings:read', 'notification-settings:write'])]
    private bool $hideAmounts = false;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    /**
     * Push preference of every type: the user's choice, otherwise the default of the catalog.
     *
     * @return array<string, bool>
     */
    #[Groups(['notification-settings:read'])]
    #[ApiProperty(example: ['CONNECTION_EXPIRING' => true, 'CONNECTION_EXPIRED' => true, 'CONNECTION_TO_CONFIGURE' => true, 'MONTH_CLOSING' => false])]
    public function getPush(): array
    {
        $push = [];
        foreach (NotificationType::cases() as $type) {
            $push[$type->value] = $this->isPushEnabled($type);
        }

        return $push;
    }

    /**
     * Merged with the current preferences: only the types sent are changed.
     *
     * @param array<string, mixed> $push type => bool
     */
    #[Groups(['notification-settings:write'])]
    public function setPush(array $push): static
    {
        $this->push = array_merge($this->push, $push);

        return $this;
    }

    public function isPushEnabled(NotificationType $type): bool
    {
        $value = $this->push[$type->value] ?? null;

        return is_bool($value) ? $value : $type->defaultPush();
    }

    public function getLargeTransactionThreshold(): ?string
    {
        return $this->largeTransactionThreshold;
    }

    public function setLargeTransactionThreshold(?string $largeTransactionThreshold): static
    {
        $this->largeTransactionThreshold = $largeTransactionThreshold;

        return $this;
    }

    public function isHideAmounts(): bool
    {
        return $this->hideAmounts;
    }

    public function setHideAmounts(bool $hideAmounts): static
    {
        $this->hideAmounts = $hideAmounts;

        return $this;
    }

    #[Assert\Callback]
    public function validatePush(ExecutionContextInterface $context): void
    {
        foreach ($this->push as $type => $value) {
            if (null === NotificationType::tryFrom((string) $type)) {
                $context->buildViolation('Unknown notification type "{{ type }}".')
                    ->setParameter('{{ type }}', (string) $type)
                    ->atPath('push')
                    ->addViolation();
            } elseif (!is_bool($value)) {
                $context->buildViolation('The push preference of "{{ type }}" must be a boolean.')
                    ->setParameter('{{ type }}', (string) $type)
                    ->atPath('push')
                    ->addViolation();
            }
        }
    }
}
