<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Checks the category of a CategorizationRule: a sub-category visible to the user, not deleted, not a saving one
 * (its mirror needs a manual account), and allowing the direction of the rule.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidCategorizationRule extends Constraint
{
    public string $visibilityMessage = 'This category does not exist.';
    public string $deletedMessage = 'This category is deleted.';
    public string $rootCategoryMessage = 'The category "{{ category }}" is a root category: choose one of its sub-categories.';
    public string $savingMessage = 'A rule cannot use a saving category: categorize these transactions by hand.';
    public string $directionMessage = 'The category "{{ category }}" ({{ type }}) cannot be used on a {{ direction }}.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
