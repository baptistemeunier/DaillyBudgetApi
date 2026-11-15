<?php

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use App\Entity\Account;
use App\Entity\Category;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A split of UpdateTransaction (see TransactionSplit).
 */
class UpdateTransactionSplit
{
    #[ApiProperty(example: '/categories/0196f0a2-7c1e-7d3a-9b6e-1f2a3b4c5d6e')]
    public ?Category $category = null;

    #[Assert\NotBlank]
    #[Assert\Regex('/^-?\d{1,13}(\.\d{1,2})?$/', message: 'The amount must be a decimal with at most 2 digits.')]
    #[ApiProperty(example: '-42.50')]
    public ?string $amount = null;

    /**
     * Saving split only: manual account (savings / investment) where the mirror transaction is kept.
     * Omitted on a later PATCH: the previous mirror of the category is kept.
     */
    public ?Account $counterpartAccount = null;

    /**
     * Amount received on counterpartAccount when its currency differs from the transaction's (opposite sign).
     */
    #[Assert\Regex('/^-?\d{1,13}(\.\d{1,2})?$/', message: 'The amount must be a decimal with at most 2 digits.')]
    public ?string $counterpartAmount = null;
}
