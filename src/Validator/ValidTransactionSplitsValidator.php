<?php

namespace App\Validator;

use App\Entity\Transaction;
use App\Util\Money;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class ValidTransactionSplitsValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidTransactionSplits) {
            throw new UnexpectedTypeException($constraint, ValidTransactionSplits::class);
        }

        if (null === $value) {
            return;
        }

        if (!$value instanceof Transaction) {
            throw new UnexpectedValueException($value, Transaction::class);
        }

        $splits = $value->getSplits();
        if ($splits->isEmpty() || null === $value->getAmount() || !Money::isDecimal($value->getAmount())) {
            return;
        }

        $amount = Money::toCents($value->getAmount());
        $total = 0;
        $categories = [];
        foreach ($splits as $split) {
            if (null === $split->getAmount() || !Money::isDecimal($split->getAmount())) {
                return; // reported by the TransactionSplit constraints
            }

            $cents = Money::toCents($split->getAmount());
            if (0 === $cents || ($cents < 0) !== ($amount < 0)) {
                $this->context->buildViolation($constraint->signMessage)
                    ->atPath('splits')
                    ->addViolation();
            }
            $total += $cents;

            // Credits take income / transfer categories, debits expense / transfer ones
            $splitCategory = $split->getCategory();
            if ($splitCategory && !$splitCategory->getType()->allowsAmountSign($amount)) {
                $this->context->buildViolation($constraint->categoryTypeMessage)
                    ->setParameter('{{ category }}', $splitCategory->getName() ?? $splitCategory->getCode() ?? '?')
                    ->setParameter('{{ type }}', $splitCategory->getType()->value)
                    ->setParameter('{{ direction }}', $amount > 0 ? 'credit' : 'debit')
                    ->atPath('splits')
                    ->addViolation();
            }

            $category = $splitCategory?->getId()?->toRfc4122();
            if (null !== $category && isset($categories[$category])) {
                $this->context->buildViolation($constraint->duplicateCategoryMessage)
                    ->atPath('splits')
                    ->addViolation();
            }
            $categories[$category] = true;
        }

        // A partial categorization is allowed, but not more than the transaction amount.
        if (abs($total) > abs($amount)) {
            $this->context->buildViolation($constraint->totalMessage)
                ->setParameter('{{ total }}', Money::fromCents($total))
                ->setParameter('{{ amount }}', $value->getAmount())
                ->atPath('splits')
                ->addViolation();
        }
    }
}
