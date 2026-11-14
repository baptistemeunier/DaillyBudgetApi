<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Entity\Budget;
use App\Enum\Currency;
use App\State\StatementProvider;
use App\Util\Period;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Overview of the user's expenses and income over a period of whole months, compared to their budget.
 * Identified by its period: "2026-11" (one month) or "2026-10..2026-11" (both months included).
 * Targets are the monthly budget amounts multiplied by the number of months.
 * Only the transactions in the budget currency (EUR without budget) are taken into account.
 */
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/statements/{period}',
            requirements: ['period' => self::PERIOD_PATTERN],
            security: "is_granted('ACCOUNT_READ')",
            provider: StatementProvider::class,
        ),
    ],
    normalizationContext: ['groups' => ['statement:read'], 'skip_null_values' => true],
)]
final class Statement
{
    public const string PERIOD_PATTERN = Period::PATTERN;

    #[ApiProperty(identifier: true, example: '2026-10..2026-11')]
    #[Groups(['statement:read'])]
    public string $period;

    #[Groups(['statement:read'])]
    #[ApiProperty(example: '2026-10')]
    public string $from;

    #[Groups(['statement:read'])]
    #[ApiProperty(example: '2026-11')]
    public string $to;

    #[Groups(['statement:read'])]
    public int $months;

    #[Groups(['statement:read'])]
    public Currency $currency;

    /**
     * The user's budget, if any.
     */
    #[Groups(['statement:read'])]
    #[ApiProperty(readableLink: false)]
    public ?Budget $budget = null;

    /**
     * Budget amount × number of months (0.00 without budget).
     */
    #[Groups(['statement:read'])]
    public string $targetAmount;

    /**
     * Total expenses: sum of the lines + uncategorized.
     */
    #[Groups(['statement:read'])]
    public string $amount;

    /**
     * All root expense categories: budget lines first (prorated target),
     * then the others (target 0.00) by decreasing amount.
     * @var list<StatementLine>
     */
    #[Groups(['statement:read'])]
    public array $lines = [];

    /**
     * Amounts per category (sub-category, or root for the splits made directly on it), expenses and income,
     * to be grouped by parent under the lines / income. Only the categories with movements, by decreasing amount.
     * @var list<StatementDetail>
     */
    #[Groups(['statement:read'])]
    public array $details = [];

    /**
     * Expenses on categories without budget line.
     */
    #[Groups(['statement:read'])]
    public string $unbudgetedAmount;

    /**
     * Part of the debits not assigned to any category.
     */
    #[Groups(['statement:read'])]
    public string $uncategorizedAmount;

    /**
     * Number of debit transactions not fully categorized.
     */
    #[Groups(['statement:read'])]
    public int $uncategorizedCount = 0;

    /**
     * Total income: income categories + uncategorized part of the credits.
     */
    #[Groups(['statement:read'])]
    public string $incomeAmount;

    /**
     * Income per root income category with movements, by decreasing amount.
     * @var list<StatementLine>
     */
    #[Groups(['statement:read'])]
    public array $income = [];

    /**
     * Part of the credits not assigned to any category.
     */
    #[Groups(['statement:read'])]
    public string $uncategorizedIncomeAmount;

    /**
     * Number of credit transactions not fully categorized.
     */
    #[Groups(['statement:read'])]
    public int $uncategorizedIncomeCount = 0;
}
