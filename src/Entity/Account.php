<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\UuidFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use App\Entity\Traits\DeletableTrait;
use App\Entity\Traits\TimestampableTrait;
use App\Entity\Traits\UuidTrait;
use App\Enum\AccountStatus;
use App\Enum\AccountSyncStatus;
use App\Enum\AccountType;
use App\Dto\CreateManualAccount;
use App\Enum\Currency;
use App\State\AccountProcessor;
use App\Validator\ValidAccountType;
use App\Repository\AccountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_EXTERNAL_ID', columns: ['external_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new Get(security: "is_granted('ACCOUNT_READ', object)"),
        new GetCollection(security: "is_granted('ACCOUNT_READ')",
            parameters: [
                'accountConnection' => new QueryParameter(
                    filter: new UuidFilter(),
                    property: 'accountConnection',
                    description: 'Filter accounts by account connection UUID',
                    castToArray: false,
                ),
            ],
        ),
        // Manual account (savings / investment): no sync, starts with an initial transaction
        new Post(
            security: "is_granted('ACCOUNT_CREATE')",
            input: CreateManualAccount::class,
            processor: AccountProcessor::class,
        ),
        new Patch(
            denormalizationContext: ['groups' => ['account:update']],
            security: "is_granted('ACCOUNT_EDIT', object)",
        ),
    ],
    normalizationContext: ['groups' => ['account:read', 'timestamp:read']],
)]
#[UniqueEntity('externalId')]
#[ValidAccountType]
class Account
{
    use UuidTrait;
    use TimestampableTrait;
    use DeletableTrait;

    #[ORM\Column(nullable: true)]
    #[Groups(['account:read'])]
    private ?\DateTimeImmutable $externalUpdatedAt = null;

    #[ORM\Column(length: 255)]
    #[Groups(['account:read'])]
    private ?string $name = null;

    /**
     * Name chosen by the user, displayed instead of the bank's name when set.
     */
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['account:read', 'account:update'])]
    #[Assert\Length(max: 255)]
    private ?string $displayName = null;

    /**
     * Only checking accounts count in the budget (GET /statements/{period}).
     */
    #[ORM\Column(length: 20, enumType: AccountType::class, options: ['default' => AccountType::CHECKING->value])]
    #[Groups(['account:read', 'account:update'])]
    private AccountType $type = AccountType::CHECKING;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['account:read'])]
    private ?string $iban = null;

    // Null for a manual account
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $bankId = null;

    #[ORM\Column(type: 'uuid', nullable: true)]
    #[Groups(['account:read'])]
    private ?Uuid $externalId = null;

    #[ORM\Column(nullable: true, enumType: AccountStatus::class)]
    #[Groups(['account:read'])]
    private ?AccountStatus $externalStatus = null;

    #[ORM\ManyToOne(inversedBy: 'accounts')]
    private ?AccountConnection $accountConnection = null;

    #[ORM\ManyToOne(inversedBy: 'accounts')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $owner = null;

    // Null for a manual account
    #[ORM\Column(nullable: true, enumType: AccountSyncStatus::class)]
    #[Groups(['account:read'])]
    private ?AccountSyncStatus $synchronizedStatus = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['account:read'])]
    private ?\DateTimeImmutable $synchronizedAt = null;

    /**
     * Balance reported by the bank at the last sync (see balanceType for its kind).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2, nullable: true)]
    #[Groups(['account:read'])]
    private ?string $balance = null;

    #[ORM\Column(nullable: true, enumType: Currency::class)]
    #[Groups(['account:read'])]
    private ?Currency $balanceCurrency = null;

    /**
     * GoCardless balance type kept: closingBooked, interimBooked, expected, interimAvailable...
     */
    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['account:read'])]
    private ?string $balanceType = null;

    /**
     * Date the bank gives for the balance.
     */
    #[ORM\Column(nullable: true)]
    #[Groups(['account:read'])]
    private ?\DateTimeImmutable $balanceAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    /**
     * Manual account (savings / investment created through POST /accounts): never synced,
     * its transactions are the initial one and the mirrors of the transfers.
     */
    #[Groups(['account:read'])]
    public function isManual(): bool
    {
        return null === $this->externalId;
    }

    public function getBalance(): ?string
    {
        return $this->balance;
    }

    public function getBalanceCurrency(): ?Currency
    {
        return $this->balanceCurrency;
    }

    public function getBalanceType(): ?string
    {
        return $this->balanceType;
    }

    public function getBalanceAt(): ?\DateTimeImmutable
    {
        return $this->balanceAt;
    }

    public function setBalance(string $balance, Currency $currency, string $type, \DateTimeImmutable $at): static
    {
        $this->balance = $balance;
        $this->balanceCurrency = $currency;
        $this->balanceType = $type;
        $this->balanceAt = $at;

        return $this;
    }

    public function getSynchronizedAt(): ?\DateTimeImmutable
    {
        return $this->synchronizedAt;
    }

    public function setSynchronizedAt(?\DateTimeImmutable $synchronizedAt): static
    {
        $this->synchronizedAt = $synchronizedAt;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): static
    {
        // An empty name falls back to the bank's name
        $displayName = $displayName !== null ? trim($displayName) : null;
        $this->displayName = $displayName !== '' ? $displayName : null;

        return $this;
    }

    public function getType(): AccountType
    {
        return $this->type;
    }

    public function setType(AccountType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getIban(): ?string
    {
        return $this->iban;
    }

    public function setIban(?string $iban): static
    {
        $this->iban = $iban;

        return $this;
    }

    public function getBankId(): ?string
    {
        return $this->bankId;
    }

    public function setBankId(?string $bankId): static
    {
        $this->bankId = $bankId;

        return $this;
    }

    public function getExternalId(): ?Uuid
    {
        return $this->externalId;
    }

    public function setExternalId(?Uuid $externalId): static
    {
        $this->externalId = $externalId;

        return $this;
    }

    public function getExternalStatus(): ?AccountStatus
    {
        return $this->externalStatus;
    }

    public function setExternalStatus(?AccountStatus $externalStatus): static
    {
        $this->externalStatus = $externalStatus;

        return $this;
    }

    public function getAccountConnection(): ?AccountConnection
    {
        return $this->accountConnection;
    }

    public function setAccountConnection(?AccountConnection $accountConnection): static
    {
        $this->accountConnection = $accountConnection;

        return $this;
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

    public function getSynchronizedStatus(): ?AccountSyncStatus
    {
        return $this->synchronizedStatus;
    }

    public function setSynchronizedStatus(AccountSyncStatus $synchronizedStatus): static
    {
        $this->synchronizedStatus = $synchronizedStatus;

        return $this;
    }

    public function getExternalUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->externalUpdatedAt;
    }

    public function setExternalUpdatedAt(?\DateTimeImmutable $externalUpdatedAt): static
    {
        $this->externalUpdatedAt = $externalUpdatedAt;

        return $this;
    }
}
