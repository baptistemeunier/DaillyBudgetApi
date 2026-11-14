<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use App\Entity\Traits\UuidTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Part of a transaction amount assigned to a category ("ventilation").
 * Only exposed embedded in Transaction.
 */
#[ORM\Entity]
class TransactionSplit
{
    use UuidTrait;

    #[ORM\ManyToOne(inversedBy: 'splits')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Transaction $transaction = null;

    // Deleting a category makes this part uncategorized, the sum stays valid
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    #[Groups(['transaction:read', 'transaction:update'])]
    #[ApiProperty(readableLink: false, writableLink: false)]
    private ?Category $category = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    #[Groups(['transaction:read', 'transaction:update'])]
    #[Assert\NotBlank]
    #[Assert\Regex('/^-?\d{1,13}(\.\d{1,2})?$/', message: 'The amount must be a decimal with at most 2 digits.')]
    private ?string $amount = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getTransaction(): ?Transaction
    {
        return $this->transaction;
    }

    public function setTransaction(?Transaction $transaction): static
    {
        $this->transaction = $transaction;

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
