<?php

namespace App\ApiResource;

use App\Entity\Category;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Amount of a single category (sub-category or root) over the period of a Statement.
 * Grouped by parent, the details of a root add up to its line.
 * Only exposed embedded in Statement.
 */
final class StatementDetail
{
    public function __construct(
        #[Groups(['statement:read'])]
        public Category $category,

        /**
         * Root category of the line this detail belongs to (the category itself for a root).
         */
        #[Groups(['statement:read'])]
        public Category $parent,

        /**
         * Amount spent (expenses) or received (income), positive for a normal flow.
         */
        #[Groups(['statement:read'])]
        public string $amount,

        /**
         * Number of transactions with a split in the category.
         */
        #[Groups(['statement:read'])]
        public int $transactionCount = 0,
    ) {
    }
}
