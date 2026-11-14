<?php

namespace App\Validator;

use App\Entity\Transaction;
use App\Entity\TransactionSplit;
use App\Enum\CategoryType;
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

            // Only sub-categories: a root only groups them
            $splitCategory = $split->getCategory();
            if ($splitCategory && null === $splitCategory->getParent()) {
                $this->context->buildViolation($constraint->rootCategoryMessage)
                    ->setParameter('{{ category }}', $splitCategory->getName() ?? $splitCategory->getCode() ?? '?')
                    ->atPath('splits')
                    ->addViolation();
            }

            // Credits take income / transfer categories, debits expense / transfer ones
            if ($splitCategory && !$splitCategory->getType()->allowsAmountSign($amount)) {
                $this->context->buildViolation($constraint->categoryTypeMessage)
                    ->setParameter('{{ category }}', $splitCategory->getName() ?? $splitCategory->getCode() ?? '?')
                    ->setParameter('{{ type }}', $splitCategory->getType()->value)
                    ->setParameter('{{ direction }}', $amount > 0 ? 'credit' : 'debit')
                    ->atPath('splits')
                    ->addViolation();
            }

            $this->validateCounterpart($value, $split, $constraint);

            // Saving categories may be reused (one split per manual account)
            $category = $splitCategory?->getType() === CategoryType::SAVING ? null : $splitCategory?->getId()?->toRfc4122();
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

    /**
     * counterpartAccount only on a saving split; counterpartAccount must be
     * another active manual account; counterpartAmount only towards a manual account in another currency,
     * with the opposite sign. The rest is checked by SavingMirrorLinker.
     */
    private function validateCounterpart(Transaction $transaction, TransactionSplit $split, ValidTransactionSplits $constraint): void
    {
        $account = $split->getCounterpartAccount();

        if ($account && $split->getCategory()?->getType() !== CategoryType::SAVING) {
            $this->context->buildViolation($constraint->counterpartCategoryMessage)
                ->atPath('splits')
                ->addViolation();

            return;
        }

        $counterpartAmount = $split->getCounterpartAmount();
        if (null !== $counterpartAmount && !$account) {
            $this->context->buildViolation($constraint->counterpartAmountWithoutAccountMessage)
                ->atPath('splits')
                ->addViolation();

            return;
        }

        if (!$account) {
            return;
        }

        if (!$account->isManual() || $account->getDeletedAt() || $account === $transaction->getAccount()) {
            $this->context->buildViolation($constraint->counterpartAccountMessage)
                ->atPath('splits')
                ->addViolation();

            return;
        }

        if (null === $counterpartAmount || !Money::isDecimal($counterpartAmount)) {
            return; // required between currencies: checked by SavingMirrorLinker (a previous mirror can be kept)
        }

        // Same currency: the mirror is always the opposite of the split
        if ($account->getBalanceCurrency() === $transaction->getCurrency()) {
            $this->context->buildViolation($constraint->counterpartAmountSameCurrencyMessage)
                ->atPath('splits')
                ->addViolation();
        } elseif (0 === Money::toCents($counterpartAmount)
            || (Money::toCents($counterpartAmount) < 0) === (Money::toCents($split->getAmount()) < 0)) {
            $this->context->buildViolation($constraint->counterpartAmountSignMessage)
                ->atPath('splits')
                ->addViolation();
        }
    }
}
