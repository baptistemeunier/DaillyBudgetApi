<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Enum\Currency;
use App\State\SavingStatementProvider;
use App\Util\Period;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Savings dashboard over a period of whole months: money in and out of the user's savings accounts,
 * the part not assigned to an envelope, and the envelopes (saving categories).
 * Identified by its period, like Statement: "2026-11" or "2026-06..2026-11".
 * Only the transactions in the budget currency (EUR without budget) are taken into account.
 */
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/savings/{period}',
            requirements: ['period' => Period::PATTERN],
            security: "is_granted('ACCOUNT_READ')",
            provider: SavingStatementProvider::class,
        ),
    ],
    normalizationContext: ['groups' => ['saving:read'], 'skip_null_values' => true],
)]
final class SavingStatement
{
    #[ApiProperty(identifier: true, example: '2026-06..2026-11')]
    #[Groups(['saving:read'])]
    public string $period;

    #[Groups(['saving:read'])]
    #[ApiProperty(example: '2026-06')]
    public string $from;

    #[Groups(['saving:read'])]
    #[ApiProperty(example: '2026-11')]
    public string $to;

    #[Groups(['saving:read'])]
    public int $months;

    #[Groups(['saving:read'])]
    public Currency $currency;

    /**
     * Money in (credits) on the savings accounts over the period.
     */
    #[Groups(['saving:read'])]
    public string $creditAmount;

    /**
     * Money out (debits) of the savings accounts over the period, positive.
     */
    #[Groups(['saving:read'])]
    public string $debitAmount;

    /**
     * Credits and debits per month, every month of the period (0.00 without movement).
     * @var list<SavingMonth>
     */
    #[Groups(['saving:read'])]
    public array $monthly = [];

    /**
     * Net part of the movements not assigned to any category ("reste à répartir").
     */
    #[Groups(['saving:read'])]
    public string $uncategorizedAmount;

    /**
     * Number of transactions not fully categorized.
     */
    #[Groups(['saving:read'])]
    public int $uncategorizedCount = 0;

    /**
     * Saving categories of the user, by decreasing amount.
     * @var list<SavingEnvelope>
     */
    #[Groups(['saving:read'])]
    public array $envelopes = [];
}
