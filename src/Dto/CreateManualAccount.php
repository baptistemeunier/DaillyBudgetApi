<?php

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use App\Enum\AccountType;
use App\Enum\Currency;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /accounts: manual account (no bank sync), starting with an initial transaction.
 */
class CreateManualAccount
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[ApiProperty(example: 'Livret A')]
    public ?string $name = null;

    #[Assert\NotNull]
    #[Assert\Choice(choices: [AccountType::SAVINGS, AccountType::INVESTMENT], message: 'A manual account can only be a savings or an investment account.')]
    #[ApiProperty(example: 'savings')]
    public ?AccountType $type = null;

    #[Assert\NotNull]
    #[ApiProperty(example: 'EUR')]
    public ?Currency $currency = null;

    #[Assert\NotBlank]
    #[Assert\Regex('/^-?\d{1,13}(\.\d{1,2})?$/', message: 'The amount must be a decimal with at most 2 digits.')]
    #[ApiProperty(example: '1000.00')]
    public ?string $initialBalance = null;

    #[Assert\NotNull]
    #[ApiProperty(example: '2026-11-01')]
    public ?\DateTimeImmutable $initialBalanceAt = null;
}
