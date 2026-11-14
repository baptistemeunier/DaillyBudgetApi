<?php

namespace App\Validator;

use App\Entity\Category;
use App\Enum\CategoryType;
use App\Util\Money;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class ValidCategoryTargetValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidCategoryTarget) {
            throw new UnexpectedTypeException($constraint, ValidCategoryTarget::class);
        }

        if (null === $value) {
            return;
        }

        if (!$value instanceof Category) {
            throw new UnexpectedValueException($value, Category::class);
        }

        $amount = $value->getTargetAmount();
        if (null === $amount && null === $value->getTargetDate()) {
            return;
        }

        if (CategoryType::SAVING !== $value->getType()) {
            $this->context->buildViolation($constraint->typeMessage)
                ->atPath('targetAmount')
                ->addViolation();

            return;
        }

        if (null === $amount) {
            $this->context->buildViolation($constraint->dateWithoutAmountMessage)
                ->atPath('targetDate')
                ->addViolation();
        } elseif (Money::isDecimal($amount) && 0 === Money::toCents($amount)) {
            $this->context->buildViolation($constraint->zeroMessage)
                ->atPath('targetAmount')
                ->addViolation();
        }
    }
}
