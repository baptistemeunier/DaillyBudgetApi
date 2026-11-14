<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use App\Entity\Traits\UuidTrait;
use App\Repository\BudgetLineRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Part of a budget allocated to a root expense category.
 * Only exposed embedded in Budget.
 */
#[ORM\Entity(repositoryClass: BudgetLineRepository::class)]
class BudgetLine
{
    use UuidTrait;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Budget $budget = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['budget:read', 'budget:write'])]
    #[ApiProperty(writableLink: false)] // IRI on input; output as a HAL link by the normalizer
    #[Assert\NotNull]
    private ?Category $category = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    #[Groups(['budget:read', 'budget:write'])]
    #[Assert\NotBlank]
    #[Assert\Regex('/^\d{1,13}(\.\d{1,2})?$/', message: 'The amount must be a positive decimal with at most 2 digits.')]
    #[Assert\Positive]
    private ?string $amount = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getBudget(): ?Budget
    {
        return $this->budget;
    }

    public function setBudget(?Budget $budget): static
    {
        $this->budget = $budget;

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

    public function getAmount(): ?string
    {
        return $this->amount;
    }

    public function setAmount(?string $amount): static
    {
        $this->amount = $amount;

        return $this;
    }
}
