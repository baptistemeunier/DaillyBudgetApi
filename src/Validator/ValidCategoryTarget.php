<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Checks the target (envelope goal) of a Category: only on a saving category, a non-zero amount,
 * and no date without amount.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidCategoryTarget extends Constraint
{
    public string $typeMessage = 'Only a saving category can have a target.';
    public string $zeroMessage = 'The target amount must be greater than 0.';
    public string $dateWithoutAmountMessage = 'A target date requires a target amount.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
