<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Checks the type of an Account: a manual account (no bank sync) is a savings or an investment account.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidAccountType extends Constraint
{
    public string $manualCheckingMessage = 'A manual account can only be a savings or an investment account.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
