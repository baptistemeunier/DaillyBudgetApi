<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Traits\TimestampableTrait;
use App\Entity\Traits\UuidTrait;
use App\Enum\Currency;
use App\Repository\BudgetRepository;
use App\State\BudgetProcessor;
use App\Util\Money;
use App\Validator\ValidBudgetLines;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Monthly budget of a user (one per user), split across root expense categories.
 */
#[ORM\Entity(repositoryClass: BudgetRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_OWNER', columns: ['owner_id'])]
#[ORM\HasLifecycleCallbacks]
#[ValidBudgetLines]
#[ApiResource(
    // Rarely changes: the client keeps it and always revalidates it with If-None-Match (304 when unchanged)
    cacheHeaders: ['max_age' => 0, 'must_revalidate' => true],
    operations: [
        new Get(security: "is_granted('BUDGET_READ', object)"),
        // The budget of the user, if any (scoped by CurrentUserExtension)
        new GetCollection(security: "is_granted('BUDGET_READ')"),
        new Post(
            denormalizationContext: ['groups' => ['budget:write', 'budget:create']],
            security: "is_granted('BUDGET_CREATE')",
            securityPostDenormalize: "is_granted('BUDGET_CREATE', object)",
            processor: BudgetProcessor::class,
        ),
        new Patch(
            denormalizationContext: ['groups' => ['budget:write']],
            security: "is_granted('BUDGET_EDIT', object)",
            securityPostDenormalize: "is_granted('BUDGET_EDIT', object)",
        ),
        new Delete(security: "is_granted('BUDGET_DELETE', object)"),
    ],
    normalizationContext: ['groups' => ['budget:read', 'timestamp:read']],
)]
class Budget
{
    use UuidTrait;
    use TimestampableTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $owner = null;

    /**
     * Total amount available each month.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    #[Groups(['budget:read', 'budget:write'])]
    #[Assert\NotBlank]
    #[Assert\Regex('/^\d{1,13}(\.\d{1,2})?$/', message: 'The amount must be a positive decimal with at most 2 digits.')]
    #[Assert\Positive]
    private ?string $amount = null;

    #[ORM\Column(enumType: Currency::class)]
    #[Groups(['budget:read', 'budget:create'])]
    private Currency $currency = Currency::EUR;

    /**
     * Amount allocated to each root expense category; their sum cannot exceed the budget amount.
     * @var Collection<int, BudgetLine>
     */
    #[ORM\OneToMany(targetEntity: BudgetLine::class, mappedBy: 'budget', cascade: ['persist'], orphanRemoval: true)]
    #[Groups(['budget:read', 'budget:write'])]
    #[Assert\Valid]
    private Collection $lines;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->lines = new ArrayCollection();
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

    public function getAmount(): ?string
    {
        return $this->amount;
    }

    public function setAmount(?string $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    public function getCurrency(): Currency
    {
        return $this->currency;
    }

    public function setCurrency(Currency $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    /**
     * @return Collection<int, BudgetLine>
     */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(BudgetLine $line): static
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setBudget($this);
        }

        return $this;
    }

    public function removeLine(BudgetLine $line): static
    {
        if ($this->lines->removeElement($line)) {
            // set the owning side to null (unless already changed)
            if ($line->getBudget() === $this) {
                $line->setBudget(null);
            }
        }

        return $this;
    }

    /**
     * Sum of the lines, in cents. Lines with an invalid amount are ignored (reported by validation).
     */
    public function getAllocatedCents(): int
    {
        $total = 0;
        foreach ($this->lines as $line) {
            if (null !== $line->getAmount() && Money::isDecimal($line->getAmount())) {
                $total += Money::toCents($line->getAmount());
            }
        }

        return $total;
    }

    #[Groups(['budget:read'])]
    #[ApiProperty(description: 'Sum of the amounts allocated to the categories.')]
    public function getAllocatedAmount(): string
    {
        return Money::fromCents($this->getAllocatedCents());
    }

    #[Groups(['budget:read'])]
    #[ApiProperty(description: 'Part of the budget not allocated to any category.')]
    public function getUnallocatedAmount(): ?string
    {
        if (null === $this->amount || !Money::isDecimal($this->amount)) {
            return null;
        }

        return Money::fromCents(Money::toCents($this->amount) - $this->getAllocatedCents());
    }
}
