<?php

namespace App\Validator;

use App\Entity\CategorizationRule;
use App\Entity\User;
use App\Enum\CategoryType;
use App\Enum\TransactionDirection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class ValidCategorizationRuleValidator extends ConstraintValidator
{
    public function __construct(private readonly Security $security)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidCategorizationRule) {
            throw new UnexpectedTypeException($constraint, ValidCategorizationRule::class);
        }

        if (null === $value) {
            return;
        }

        if (!$value instanceof CategorizationRule) {
            throw new UnexpectedValueException($value, CategorizationRule::class);
        }

        $category = $value->getCategory();
        if (null === $category) {
            return; // reported by NotNull
        }

        // The owner is only set by the processor (after the validation) on a creation
        $user = $value->getOwner() ?? $this->security->getUser();
        $categoryOwner = $category->getOwner();
        if (null !== $categoryOwner && (!$user instanceof User || !$categoryOwner->getId()?->equals($user->getId()))) {
            $this->addViolation($constraint->visibilityMessage);

            return;
        }

        if (null !== $category->getDeletedAt()) {
            $this->addViolation($constraint->deletedMessage);

            return;
        }

        $name = $category->getName() ?? $category->getCode() ?? '?';

        if (null === $category->getParent()) {
            $this->addViolation($constraint->rootCategoryMessage, ['{{ category }}' => $name]);

            return;
        }

        if (CategoryType::SAVING === $category->getType()) {
            $this->addViolation($constraint->savingMessage);

            return;
        }

        $direction = $value->getDirection();
        if (null !== $direction && !$category->getType()->allowsAmountSign(TransactionDirection::DEBIT === $direction ? -1 : 1)) {
            $this->addViolation($constraint->directionMessage, [
                '{{ category }}' => $name,
                '{{ type }}' => $category->getType()->value,
                '{{ direction }}' => $direction->value,
            ]);
        }
    }

    /**
     * @param array<string, string> $parameters
     */
    private function addViolation(string $message, array $parameters = []): void
    {
        $this->context->buildViolation($message)
            ->setParameters($parameters)
            ->atPath('category')
            ->addViolation();
    }
}
