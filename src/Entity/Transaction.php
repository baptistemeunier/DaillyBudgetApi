<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use App\Entity\Traits\TimestampableTrait;
use App\Entity\Traits\UuidTrait;
use App\Enum\Currency;
use App\Repository\TransactionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TransactionRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_EXTERNAL_ID', columns: ['account_id', 'external_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new Get(
            security: "is_granted('TRANSACTION_READ', object)"
        ),
        new GetCollection(
            uriTemplate: '/accounts/{id}/transactions',
            uriVariables: [
                'id' => new Link(
                    toProperty: 'account',
                    fromClass: Account::class,
                ),
            ],
            security: "is_granted('TRANSACTION_ACCOUNT_READ', request.attributes.get('id'))",
        ),
    ],
    normalizationContext: ['groups' => ['transaction:read', 'timestamp:read']],
)]
#[UniqueEntity('externalId')]
class Transaction
{
    use UuidTrait;
    use TimestampableTrait;

    #[ORM\Column(length: 255)]
    private ?string $externalId = null;

    #[ORM\Column(length: 255)]
    #[Groups(['transaction:read'])]
    private ?string $reference = null;

    #[ORM\Column(length: 255)]
    #[Groups(['transaction:read'])]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['transaction:read'])]
    private ?string $detail = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    #[Groups(['transaction:read'])]
    private ?string $amount = null;

    #[ORM\Column(enumType: Currency::class)]
    #[Groups(['transaction:read'])]
    private ?Currency $currency = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['transaction:read'])]
    private ?\DateTimeImmutable $bookingAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $valueAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $externalCreatedAt = null;

    #[ORM\Column]
    #[Groups(['transaction:read'])]
    private ?bool $isBooked = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalTransactionType = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalTransactionCode = null;

    #[ORM\Column(nullable: true)]
    private ?array $rawData = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Account $account = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): static
    {
        $this->externalId = $externalId;

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getDetail(): ?string
    {
        return $this->detail;
    }

    public function setDetail(?string $detail): static
    {
        $this->detail = $detail;

        return $this;
    }

    public function getAmount(): ?string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    public function getCurrency(): ?Currency
    {
        return $this->currency;
    }

    public function setCurrency(Currency $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    public function getBookingAt(): ?\DateTimeImmutable
    {
        return $this->bookingAt;
    }

    public function setBookingAt(?\DateTimeImmutable $bookingAt): static
    {
        $this->bookingAt = $bookingAt;

        return $this;
    }

    public function getValueAt(): ?\DateTimeImmutable
    {
        return $this->valueAt;
    }

    public function setValueAt(\DateTimeImmutable $valueAt): static
    {
        $this->valueAt = $valueAt;

        return $this;
    }

    public function getExternalCreatedAt(): ?\DateTimeImmutable
    {
        return $this->externalCreatedAt;
    }

    public function setExternalCreatedAt(?\DateTimeImmutable $externalCreatedAt): static
    {
        $this->externalCreatedAt = $externalCreatedAt;

        return $this;
    }

    public function isBooked(): ?bool
    {
        return $this->isBooked;
    }

    public function setIsBooked(bool $isBooked): static
    {
        $this->isBooked = $isBooked;

        return $this;
    }

    public function getExternalTransactionType(): ?string
    {
        return $this->externalTransactionType;
    }

    public function setExternalTransactionType(?string $externalTransactionType): static
    {
        $this->externalTransactionType = $externalTransactionType;

        return $this;
    }

    public function getExternalTransactionCode(): ?string
    {
        return $this->externalTransactionCode;
    }

    public function setExternalTransactionCode(?string $externalTransactionCode): static
    {
        $this->externalTransactionCode = $externalTransactionCode;

        return $this;
    }

    public function getRawData(): ?array
    {
        return $this->rawData;
    }

    public function setRawData(?array $rawData): static
    {
        $this->rawData = $rawData;

        return $this;
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function setAccount(?Account $account): static
    {
        $this->account = $account;

        return $this;
    }
}
