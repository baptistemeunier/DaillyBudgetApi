<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\ExactFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\QueryParameter;
use App\Doctrine\Filter\AutoCategorizedFilter;
use App\Dto\UpdateTransaction;
use App\Doctrine\Filter\SplitCategoryFilter;
use App\Doctrine\Filter\UncategorizedFilter;
use App\Entity\Traits\TimestampableTrait;
use App\Entity\Traits\UuidTrait;
use App\Enum\Currency;
use App\Enum\SplitSource;
use App\Repository\TransactionRepository;
use App\State\TransactionProcessor;
use App\Util\MerchantKey;
use App\Validator\ValidTransactionSplits;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TransactionRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_EXTERNAL_ID', columns: ['account_id', 'external_id'])]
#[ORM\Index(name: 'IDX_MERCHANT_KEY', columns: ['merchant_key'])]
#[ORM\HasLifecycleCallbacks]
#[ValidTransactionSplits]
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
                    security: "is_granted('ACCOUNT_READ', account)",
                    securityObjectName: 'account',
                ),
            ],
            order: ['bookingAt' => 'DESC', 'id' => 'DESC'],
        ),
        // Every transaction of the user, all accounts together (scoped by CurrentUserExtension)
        new GetCollection(
            uriTemplate: '/transactions',
            order: ['bookingAt' => 'DESC', 'id' => 'DESC'],
            security: "is_granted('ACCOUNT_READ')",
            parameters: [
                'uncategorized' => new QueryParameter(
                    schema: ['type' => 'boolean'],
                    filter: new UncategorizedFilter(),
                    description: 'true: only the transactions not fully categorized (sum of the splits ≠ amount)',
                ),
                'autoCategorized' => new QueryParameter(
                    schema: ['type' => 'boolean'],
                    filter: new AutoCategorizedFilter(),
                    description: 'true: only the transactions categorized by a rule, to be reviewed by the user',
                ),
                'category' => new QueryParameter(
                    schema: ['type' => 'string'],
                    filter: new SplitCategoryFilter(),
                    description: 'Category UUID or IRI: only the transactions with a split on exactly this category',
                ),
                // ?bookingAt[after]=2026-10-01&bookingAt[strictly_before]=2026-12-01
                // (whole months of a statement: strictly_before the 1st of the month after "to")
                'bookingAt' => new QueryParameter(
                    filter: new DateFilter(),
                    property: 'bookingAt',
                    description: 'Booking date bounds: bookingAt[after|before|strictly_after|strictly_before]=<date>',
                ),
                'hidden' => new QueryParameter(
                    filter: new ExactFilter(),
                    property: 'hidden',
                    description: 'true: only the hidden transactions (left out of the budget), false: only the visible ones',
                ),
                // ?accountType[]=checking or ?accountType[]=savings&accountType[]=investment
                'accountType' => new QueryParameter(
                    filter: new ExactFilter(),
                    property: 'account.type',
                ),
            ],
        ),
        // The processor maps the input on the transaction, then checks the new splits (voter + validation)
        new Patch(
            input: UpdateTransaction::class,
            security: "is_granted('TRANSACTION_UPDATE', object)",
            processor: TransactionProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['transaction:read', 'timestamp:read']],
)]
#[UniqueEntity('externalId')]
class Transaction
{
    use UuidTrait;
    use TimestampableTrait;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['transaction:read'])]
    private ?string $reference = null;

    #[ORM\Column(length: 255)]
    #[Groups(['transaction:read'])]
    private ?string $description = null;

    #[ORM\Column(length: MerchantKey::MAX_LENGTH, nullable: true)]
    #[Groups(['transaction:read'])]
    private ?string $merchantKey = null;

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

    /**
     * Hidden by the user: kept in the transaction lists and in the account balance, but left out of the
     * budget (statements, saving statements, budget alerts) and of the "to categorize" counters.
     */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['transaction:read'])]
    private bool $hidden = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalTransactionType = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalTransactionCode = null;

    #[ORM\Column(nullable: true)]
    private ?array $rawData = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Account $account = null;

    /**
     * Categorized when the sum of the splits equals the transaction amount;
     * any difference (including no split at all) is the uncategorized part.
     * @var Collection<int, TransactionSplit>
     */
    #[ORM\OneToMany(targetEntity: TransactionSplit::class, mappedBy: 'transaction', cascade: ['persist'], orphanRemoval: true)]
    #[Groups(['transaction:read'])]
    #[Assert\Valid]
    private Collection $splits;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->splits = new ArrayCollection();
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
        $this->merchantKey = MerchantKey::fromDescription($description);

        return $this;
    }

    public function getMerchantKey(): ?string
    {
        return $this->merchantKey;
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

    public function isHidden(): bool
    {
        return $this->hidden;
    }

    public function setHidden(bool $hidden): static
    {
        $this->hidden = $hidden;

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

    /**
     * @return Collection<int, TransactionSplit>
     */
    public function getSplits(): Collection
    {
        return $this->splits;
    }

    public function addSplit(TransactionSplit $split): static
    {
        if (!$this->splits->contains($split)) {
            $this->splits->add($split);
            $split->setTransaction($this);
        }

        return $this;
    }

    public function removeSplit(TransactionSplit $split): static
    {
        $this->splits->removeElement($split);

        return $this;
    }

    /**
     * A split set or confirmed by the user: the categorization rules leave the transaction alone.
     */
    public function hasUserSplit(): bool
    {
        return $this->splits->exists(static fn (int $key, TransactionSplit $split) => in_array($split->getSource(), SplitSource::userSet(), true));
    }
}
