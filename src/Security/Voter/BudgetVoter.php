<?php

namespace App\Security\Voter;

use App\Entity\Budget;
use App\Enum\Role;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The budget is shared between all the users (a single one); only its owner (creator) can delete it.
 */
final class BudgetVoter extends Voter
{
    use OwnerCheckTrait;

    public const CREATE = 'BUDGET_CREATE';
    public const READ = 'BUDGET_READ';
    public const EDIT = 'BUDGET_EDIT';
    public const DELETE = 'BUDGET_DELETE';

    public function __construct(private Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        // Generic validation
        if (!$subject && in_array($attribute, [self::CREATE, self::READ])) {
            return true;
        }

        // Object validation
        return in_array($attribute, [self::CREATE, self::READ, self::EDIT, self::DELETE])
            && $subject instanceof Budget;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        // if the user is anonymous, do not grant access
        if (!$token->getUser() instanceof UserInterface) {
            $vote?->addReason('The user must be logged in to access this resource.');

            return false;
        }

        if (empty($subject)) {
            return $this->voteWithoutItem($attribute, $vote);
        }

        if (!$subject instanceof Budget) {
            throw new \LogicException('subject must be instance of Budget');
        }

        return $this->voteOnItem($attribute, $subject, $token, $vote);
    }

    private function voteWithoutItem(string $attribute, ?Vote $vote): bool
    {
        return match ($attribute) {
            self::READ => $this->hasRole(Role::BUDGET_READ, $vote),
            self::CREATE => $this->hasRole(Role::BUDGET_WRITE, $vote),
            default => throw new \LogicException("Unsupported attribute: $attribute"),
        };
    }

    private function voteOnItem(string $attribute, Budget $subject, TokenInterface $token, ?Vote $vote): bool
    {
        if (!$this->hasRole($attribute === self::READ ? Role::BUDGET_READ : Role::BUDGET_WRITE, $vote)) {
            return false;
        }

        if ($attribute === self::DELETE && !$this->isOwner($subject, $token->getUser())) {
            $vote?->addReason('Only the owner of the budget can delete it.');

            return false;
        }

        if (in_array($attribute, [self::CREATE, self::EDIT])) {
            // After denormalization: the lines can only use categories visible to the user
            foreach ($subject->getLines() as $line) {
                if ($line->getCategory() && !$this->security->isGranted(CategoryVoter::READ, $line->getCategory())) {
                    $vote?->addReason('The user cannot use this category.');

                    return false;
                }
            }
        }

        return true;
    }

    private function hasRole(Role $role, ?Vote $vote): bool
    {
        if (!$this->security->isGranted($role->value)) {
            $vote?->addReason(sprintf('The user does not have the "%s" role.', $role->value));

            return false;
        }

        return true;
    }
}
