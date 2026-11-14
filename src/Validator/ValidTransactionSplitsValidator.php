<?php

namespace App\Validator;

use App\Entity\Transaction;
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
        if ($splits->isEmpty() || null === $value->getAmount() || !self::isDecimal($value->getAmount())) {
            return;
        }

        $amount = self::toCents($value->getAmount());
        $total = 0;
        $categories = [];
        foreach ($splits as $split) {
            if (null === $split->getAmount() || !self::isDecimal($split->getAmount())) {
                return; // reported by the TransactionSplit constraints
            }

            $cents = self::toCents($split->getAmount());
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
                ->setParameter('{{ total }}', number_format($total / 100, 2, '.', ''))
                ->setParameter('{{ amount }}', $value->getAmount())
                ->atPath('splits')
                ->addViolation();
        }
    }

    private static function isDecimal(string $amount): bool
    {
        return 1 === preg_match('/^-?\d+(\.\d{1,2})?$/', $amount);
    }

    /**
     * Exact conversion of a decimal string ("-12.3") to cents (-1230), without float rounding.
     */
    private static function toCents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        [$units, $decimals] = explode('.', ltrim($amount, '-') . '.', 3);
        $cents = (int) $units * 100 + (int) str_pad(substr($decimals, 0, 2), 2, '0');

        return $negative ? -$cents : $cents;
    }
}
