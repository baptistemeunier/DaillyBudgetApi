<?php

namespace App\Validator;

use App\Entity\Budget;
use App\Entity\Category;
use App\Enum\CategoryType;
use App\Util\Money;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class ValidBudgetLinesValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidBudgetLines) {
            throw new UnexpectedTypeException($constraint, ValidBudgetLines::class);
        }

        if (null === $value) {
            return;
        }

        if (!$value instanceof Budget) {
            throw new UnexpectedValueException($value, Budget::class);
        }

        $categories = [];
        foreach ($value->getLines() as $line) {
            $category = $line->getCategory();
            if (null === $category) {
                continue; // reported by the BudgetLine constraints
            }

            if (null !== $category->getParent()) {
                $this->addCategoryViolation($constraint->rootMessage, $category);
            }

            if (CategoryType::EXPENSE !== $category->getType()) {
                $this->addCategoryViolation($constraint->typeMessage, $category);
            }

            if (null !== $category->getDeletedAt()) {
                $this->addCategoryViolation($constraint->deletedMessage, $category);
            }

            $id = $category->getId()?->toRfc4122();
            if (isset($categories[$id])) {
                $this->context->buildViolation($constraint->duplicateCategoryMessage)
                    ->atPath('lines')
                    ->addViolation();
            }
            $categories[$id] = true;
        }

        $amount = $value->getAmount();
        if (null === $amount || !Money::isDecimal($amount)) {
            return; // reported by the Budget constraints
        }

        $total = $value->getAllocatedCents();
        if ($total > Money::toCents($amount)) {
            $this->context->buildViolation($constraint->totalMessage)
                ->setParameter('{{ total }}', Money::fromCents($total))
                ->setParameter('{{ amount }}', $amount)
                ->atPath('lines')
                ->addViolation();
        }
    }

    private function addCategoryViolation(string $message, Category $category): void
    {
        $this->context->buildViolation($message)
            ->setParameter('{{ category }}', $category->getName() ?? $category->getCode() ?? '?')
            ->setParameter('{{ type }}', $category->getType()->value)
            ->atPath('lines')
            ->addViolation();
    }
}
