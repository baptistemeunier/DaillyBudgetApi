<?php

namespace App\Security\Voter;

use App\Entity\CategorizationRule;
use App\Enum\Role;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Categorization rules are only visible / editable by their owner, with the category roles.
 */
final class CategorizationRuleVoter extends Voter
{
    public const CREATE = 'CATEGORIZATION_RULE_CREATE';
    public const READ = 'CATEGORIZATION_RULE_READ';
    public const EDIT = 'CATEGORIZATION_RULE_EDIT';

    public function __construct(private Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        // Generic validation
        if (!$subject && in_array($attribute, [self::CREATE, self::READ], true)) {
            return true;
        }

        // Object validation
        return in_array($attribute, [self::CREATE, self::READ, self::EDIT], true)
            && $subject instanceof CategorizationRule;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof UserInterface) {
            $vote?->addReason('The user must be logged in to access this resource.');

            return false;
        }

        $role = self::READ === $attribute ? Role::CATEGORY_READ : Role::CATEGORY_WRITE;
        if (!$this->security->isGranted($role->value)) {
            $vote?->addReason(sprintf('The user does not have the "%s" role.', $role->value));

            return false;
        }

        if (!$subject instanceof CategorizationRule) {
            return true;
        }

        // Creation, called after denormalization: the owner is set by the processor, so it can only be empty or the user
        $owner = $subject->getOwner();
        if (self::CREATE === $attribute && null === $owner) {
            return true;
        }

        if (!$owner?->getId()?->equals($user->getId())) {
            $vote?->addReason('The user does not own this resource.');

            return false;
        }

        return true;
    }
}
