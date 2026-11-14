<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Checks the splits of a Transaction against its amount: sub-categories only, same sign, category type matching
 * the direction, each category used once, and a total not exceeding the transaction amount.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidTransactionSplits extends Constraint
{
    public string $signMessage = 'Each split must be non-zero and have the same sign as the transaction amount.';
    public string $categoryTypeMessage = 'The category "{{ category }}" ({{ type }}) cannot be used on a {{ direction }}.';
    public string $rootCategoryMessage = 'The category "{{ category }}" is a root category: choose one of its sub-categories.';
    public string $duplicateCategoryMessage = 'A category can only be used once in the splits (saving categories excepted).';
    public string $counterpartCategoryMessage = 'Only a split with a saving category can have a counterpart account.';
    public string $counterpartAccountMessage = 'The counterpart account must be another active manual account (savings or investment).';
    public string $counterpartAmountWithoutAccountMessage = 'counterpartAmount can only be given with counterpartAccount.';
    public string $counterpartAmountSameCurrencyMessage = 'counterpartAmount is only for a counterpart account in another currency.';
    public string $counterpartAmountSignMessage = 'counterpartAmount must be non-zero and have the opposite sign of the split.';
    public string $totalMessage = 'The sum of the splits ({{ total }}) cannot exceed the transaction amount ({{ amount }}).';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
