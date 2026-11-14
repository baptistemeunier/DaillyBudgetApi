<?php

namespace App\ApiResource;

use App\Entity\Category;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Amount of a root category over the period of a Statement.
 * Only exposed embedded in Statement.
 */
final class StatementLine
{
    public function __construct(
        #[Groups(['statement:read'])]
        public Category $category,

        /**
         * Amount spent (expenses) or received (income), positive for a normal flow.
         */
        #[Groups(['statement:read'])]
        public string $amount,

        /**
         * Prorated budget of the category: 0.00 for an expense category outside the budget, omitted for income.
         */
        #[Groups(['statement:read'])]
        public ?string $targetAmount = null,

        /**
         * Number of transactions with a split in the category (or one of its sub-categories).
         */
        #[Groups(['statement:read'])]
        public int $transactionCount = 0,
    ) {
    }
}
