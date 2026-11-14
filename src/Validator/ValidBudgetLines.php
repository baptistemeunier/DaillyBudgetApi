<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Checks the lines of a Budget: each one on a distinct, non-deleted root expense category,
 * and a total not exceeding the budget amount.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidBudgetLines extends Constraint
{
    public string $rootMessage = 'The category "{{ category }}" is not a root category.';
    public string $typeMessage = 'The category "{{ category }}" ({{ type }}) is not an expense category.';
    public string $deletedMessage = 'The category "{{ category }}" is deleted.';
    public string $duplicateCategoryMessage = 'A category can only be used once in the budget.';
    public string $totalMessage = 'The sum of the categories ({{ total }}) cannot exceed the budget amount ({{ amount }}).';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
