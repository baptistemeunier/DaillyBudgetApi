<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Checks the splits of a Transaction against its amount: same sign, category type matching
 * the direction, each category used once, and a total not exceeding the transaction amount.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidTransactionSplits extends Constraint
{
    public string $signMessage = 'Each split must be non-zero and have the same sign as the transaction amount.';
    public string $categoryTypeMessage = 'The category "{{ category }}" ({{ type }}) cannot be used on a {{ direction }}.';
    public string $duplicateCategoryMessage = 'A category can only be used once in the splits.';
    public string $totalMessage = 'The sum of the splits ({{ total }}) cannot exceed the transaction amount ({{ amount }}).';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
