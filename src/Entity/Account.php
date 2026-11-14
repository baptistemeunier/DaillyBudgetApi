<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\UuidFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\QueryParameter;
use App\Entity\Traits\DeletableTrait;
use App\Entity\Traits\TimestampableTrait;
use App\Entity\Traits\UuidTrait;
use App\Enum\AccountStatus;
use App\Enum\AccountSyncStatus;
use App\Repository\AccountRepository;
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
        new Patch(
            denormalizationContext: ['groups' => ['account:update']],
            security: "is_granted('ACCOUNT_EDIT', object)",
        ),
    ],
    normalizationContext: ['groups' => ['account:read', 'timestamp:read']],
)]
#[UniqueEntity('externalId')]
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

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['account:read'])]
    private ?string $iban = null;

    #[ORM\Column(length: 255)]
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

    #[ORM\Column(enumType: AccountSyncStatus::class)]
    #[Groups(['account:read'])]
    private ?AccountSyncStatus $synchronizedStatus = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['account:read'])]
    private ?\DateTimeImmutable $synchronizedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
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

    public function setBankId(string $bankId): static
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
