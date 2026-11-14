<?php

namespace App\Validator;

use App\Entity\Account;
use App\Enum\AccountType;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class ValidAccountTypeValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidAccountType) {
            throw new UnexpectedTypeException($constraint, ValidAccountType::class);
        }

        if (null === $value) {
            return;
        }

        if (!$value instanceof Account) {
            throw new UnexpectedValueException($value, Account::class);
        }

        if ($value->isManual() && AccountType::CHECKING === $value->getType()) {
            $this->context->buildViolation($constraint->manualCheckingMessage)
                ->atPath('type')
                ->addViolation();
        }
    }
}
