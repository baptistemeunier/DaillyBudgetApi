<?php

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /accounts/{id}/balance: real balance of a manual account at a date; the difference with the sum of its
 * transactions up to that date is booked as an adjustment transaction.
 */
class UpdateManualBalance
{
    #[Assert\NotBlank]
    #[Assert\Regex('/^-?\d{1,13}(\.\d{1,2})?$/', message: 'The amount must be a decimal with at most 2 digits.')]
    #[ApiProperty(example: '1050.00')]
    public ?string $balance = null;

    /**
     * Date of the balance, today when not given. The transactions booked after it are added to it.
     */
    #[Assert\LessThanOrEqual('today', message: 'The balance date cannot be in the future.')]
    #[ApiProperty(example: '2026-11-20')]
    public ?\DateTimeImmutable $balanceAt = null;
}
