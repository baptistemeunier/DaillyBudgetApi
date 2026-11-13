<?php

namespace App\Security\Voter;

use App\Entity\Category;
use App\Enum\Role;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * System categories (owner = null) are readable by everyone and never editable.
 * User categories are only visible / editable by their owner.
 */
final class CategoryVoter extends Voter
{
    public const CREATE = 'CATEGORY_CREATE';
    public const READ = 'CATEGORY_READ';
    public const EDIT = 'CATEGORY_EDIT';
    public const DELETE = 'CATEGORY_DELETE';

    public function __construct(private Security $security)
    {

    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        // Generic validation
        if(!$subject && in_array($attribute, [self::CREATE, self::READ])) {
            return true;
        }

        // Object validation
        return in_array($attribute, [self::CREATE, self::READ, self::EDIT, self::DELETE])
            && $subject instanceof Category;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        // if the user is anonymous, do not grant access
        if (!$token->getUser() instanceof UserInterface) {
            $vote?->addReason('The user must be logged in to access this resource.');

            return false;
        }

        if(empty($subject)) {
            return $this->voteWithoutItem($attribute, $vote);
        }

        if (!$subject instanceof Category) {
            throw new \LogicException("subject must be instance of Category");
        }

        return $this->voteOnItem($attribute, $subject, $token, $vote);
    }

    private function voteWithoutItem(string $attribute, ?Vote $vote): bool
    {
        switch ($attribute) {
            case self::READ:
                return $this->hasRole(Role::CATEGORY_READ, $vote);
            case self::CREATE:
                return $this->hasRole(Role::CATEGORY_WRITE, $vote);
        }

        throw new \LogicException("Unsupported attribute: $attribute");
    }

    private function voteOnItem(string $attribute, Category $subject, TokenInterface $token, ?Vote $vote): bool
    {
        $user = $token->getUser();
        $owner = $subject->getOwner();
        $isOwner = $owner && $owner->getId()?->equals($user->getId());

        switch ($attribute) {
            case self::READ:
                if(!$this->hasRole(Role::CATEGORY_READ, $vote)) {
                    return false;
                }

                // System category, or the user's own one
                if($owner && !$isOwner) {
                    $vote?->addReason('The user does not own this resource.');

                    return false;
                }

                return true;
            case self::CREATE:
                // Called after denormalization: owner is set by the processor, so it can only be empty or the user
                if(!$this->hasRole(Role::CATEGORY_WRITE, $vote)) {
                    return false;
                }

                if($subject->getCode() || ($owner && !$isOwner)) {
                    $vote?->addReason('The user can only create their own categories.');

                    return false;
                }

                return true;
            case self::EDIT:
            case self::DELETE:
                if(!$this->hasRole(Role::CATEGORY_WRITE, $vote)) {
                    return false;
                }

                if(!$isOwner) {
                    $vote?->addReason('Only the owner can modify this category; system categories are read-only.');

                    return false;
                }

                if($subject->getDeletedAt()) {
                    $vote?->addReason('This category is deleted.');

                    return false;
                }

                return true;
        }

        throw new \LogicException("Unsupported attribute: $attribute");
    }

    private function hasRole(Role $role, ?Vote $vote): bool
    {
        if(!$this->security->isGranted($role->value)) {
            $vote?->addReason(sprintf('The user does not have the "%s" role.', $role->value));

            return false;
        }

        return true;
    }
}
