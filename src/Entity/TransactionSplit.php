<?php

namespace App\Entity;

use App\Entity\Traits\UuidTrait;
use App\Enum\SplitSource;
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
    #[Groups(['transaction:read'])] // output as a HAL link by the normalizer
    private ?Category $category = null;

    /**
     * Saving splits only: the mirror transaction on the manual account (and, on the mirror, the origin).
     * Set by SavingMirrorLinker, output as a HAL link by the normalizer.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    #[Groups(['transaction:read'])]
    private ?Transaction $counterpartTransaction = null;

    /**
     * Not stored, set from the PATCH input (UpdateTransactionSplit): manual account (savings / investment) where
     * SavingMirrorLinker keeps the mirror transaction. Saving splits only; omitted on a later PATCH, the previous mirror of the category is kept.
     */
    private ?Account $counterpartAccount = null;

    /**
     * Not stored, set from the PATCH input: amount received on the manual counterpartAccount when its currency differs
     * from the transaction's (opposite sign). Same currency: the mirror is always the opposite of the split.
     */
    #[Assert\Regex('/^-?\d{1,13}(\.\d{1,2})?$/', message: 'The amount must be a decimal with at most 2 digits.')]
    private ?string $counterpartAmount = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    #[Groups(['transaction:read'])]
    #[Assert\NotBlank]
    #[Assert\Regex('/^-?\d{1,13}(\.\d{1,2})?$/', message: 'The amount must be a decimal with at most 2 digits.')]
    private ?string $amount = null;

    /**
     * Read only: every split sent through the API is "manual"; "rule" when set by a categorization rule,
     * until the user confirms it ("confirmed", PATCH with confirm: true) or changes it ("manual").
     */
    #[ORM\Column(length: 255, enumType: SplitSource::class, options: ['default' => 'manual'])]
    #[Groups(['transaction:read'])]
    private SplitSource $source = SplitSource::MANUAL;

    /**
     * Rule that set the split (source "rule"); output as a HAL link by the normalizer.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    #[Groups(['transaction:read'])]
    private ?CategorizationRule $rule = null;

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

    public function getCounterpartTransaction(): ?Transaction
    {
        return $this->counterpartTransaction;
    }

    public function setCounterpartTransaction(?Transaction $counterpartTransaction): static
    {
        $this->counterpartTransaction = $counterpartTransaction;

        return $this;
    }

    public function getCounterpartAccount(): ?Account
    {
        return $this->counterpartAccount;
    }

    public function setCounterpartAccount(?Account $counterpartAccount): static
    {
        $this->counterpartAccount = $counterpartAccount;

        return $this;
    }

    public function getCounterpartAmount(): ?string
    {
        return $this->counterpartAmount;
    }

    public function setCounterpartAmount(?string $counterpartAmount): static
    {
        $this->counterpartAmount = $counterpartAmount;

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

    public function getSource(): SplitSource
    {
        return $this->source;
    }

    public function setSource(SplitSource $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getRule(): ?CategorizationRule
    {
        return $this->rule;
    }

    public function setRule(?CategorizationRule $rule): static
    {
        $this->rule = $rule;

        return $this;
    }
}
