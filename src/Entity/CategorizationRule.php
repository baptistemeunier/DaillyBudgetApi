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
use App\Enum\CategoryType;
use App\Enum\TransactionDirection;
use App\Repository\CategorizationRuleRepository;
use App\State\CategorizationRuleProcessor;
use App\Util\MerchantKey;
use App\Validator\ValidCategorizationRule;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * "Always categorize like this": the transactions (of all the users: rules are shared) whose merchant key contains the pattern (whole words),
 * in the direction if given, get a single split on the category (source "rule").
 * Applied to the new transactions of each sync, and to the existing ones when the rule is saved.
 * See docs/AUTO_CATEGORIZATION.md.
 */
#[ORM\Entity(repositoryClass: CategorizationRuleRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_RULE', columns: ['pattern', 'direction'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    // Rarely changes: the client keeps it and always revalidates it with If-None-Match (304 when unchanged)
    cacheHeaders: ['max_age' => 0, 'must_revalidate' => true],
    operations: [
        new Get(security: "is_granted('CATEGORIZATION_RULE_READ', object)"),
        new GetCollection(
            order: ['pattern' => 'ASC'],
            security: "is_granted('CATEGORIZATION_RULE_READ')",
        ),
        new Post(
            security: "is_granted('CATEGORIZATION_RULE_CREATE')",
            securityPostDenormalize: "is_granted('CATEGORIZATION_RULE_CREATE', object)",
            processor: CategorizationRuleProcessor::class,
        ),
        new Patch(
            security: "is_granted('CATEGORIZATION_RULE_EDIT', object)",
            processor: CategorizationRuleProcessor::class,
        ),
        // The splits already set by the rule are kept (their link to the rule is removed)
        new Delete(security: "is_granted('CATEGORIZATION_RULE_DELETE', object)"),
    ],
    normalizationContext: ['groups' => ['categorization_rule:read', 'timestamp:read']],
    denormalizationContext: ['groups' => ['categorization_rule:write']],
)]
#[ValidCategorizationRule]
class CategorizationRule
{
    use UuidTrait;
    use TimestampableTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    /**
     * Normalized like the merchant keys (uppercase words, no accent, no digit): "Carrefour" → "CARREFOUR".
     */
    #[ORM\Column(length: MerchantKey::MAX_LENGTH)]
    #[Groups(['categorization_rule:read', 'categorization_rule:write'])]
    #[Assert\NotBlank(message: 'The pattern must contain at least one word without digit.')]
    #[ApiProperty(example: 'CARREFOUR')]
    private ?string $pattern = null;

    /**
     * null: debits and credits (the category type still has to allow the direction).
     */
    #[ORM\Column(length: 255, nullable: true, enumType: TransactionDirection::class)]
    #[Groups(['categorization_rule:read', 'categorization_rule:write'])]
    private ?TransactionDirection $direction = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['categorization_rule:read', 'categorization_rule:write'])]
    #[ApiProperty(readableLink: false, writableLink: false)]
    #[Assert\NotNull]
    private ?Category $category = null;

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

    public function getPattern(): ?string
    {
        return $this->pattern;
    }

    public function setPattern(?string $pattern): static
    {
        $this->pattern = MerchantKey::normalizePattern($pattern);

        return $this;
    }

    public function getDirection(): ?TransactionDirection
    {
        return $this->direction;
    }

    public function setDirection(?TransactionDirection $direction): static
    {
        $this->direction = $direction;

        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): static
    {
        $this->category = $category;

        return $this;
    }

    /**
     * The rule can categorize a transaction of this amount: its merchant key contains the pattern,
     * the direction and the category type allow the sign, and the category can still be used.
     */
    public function matches(?string $merchantKey, int $cents): bool
    {
        $category = $this->category;

        return 0 !== $cents
            && MerchantKey::matches($merchantKey, $this->pattern)
            && ($this->direction?->allowsCents($cents) ?? true)
            && null !== $category
            && null === $category->getDeletedAt()
            && null !== $category->getParent()
            && CategoryType::SAVING !== $category->getType()
            && $category->getType()->allowsAmountSign($cents);
    }
}
