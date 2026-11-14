<?php

namespace App\Security\Voter;

use App\Entity\Account;
use App\Enum\Role;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

final class AccountVoter extends Voter
{
    public const READ = 'ACCOUNT_READ';
    public const EDIT = 'ACCOUNT_EDIT';
    public const CREATE = 'ACCOUNT_CREATE';

    public function __construct(private Security $security)
    {

    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        // Generic validation
        if(!$subject && in_array($attribute, [self::READ, self::CREATE])) {
            return true;
        }

        // Object validation
        return in_array($attribute, [self::READ, self::EDIT])
            && $subject instanceof Account;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        // if the user is anonymous, do not grant access
        if (!$token->getUser() instanceof UserInterface) {
            $vote?->addReason('The user must be logged in to access this resource.');

            return false;
        }

        if(empty($subject)) {
            return $this->voteWithoutItem($attribute, $token, $vote);
        }

        if (!$subject instanceof Account) {
            throw new \LogicException("subject must be instance of Account");
        }

        return $this->voteOnItem($attribute, $subject, $token, $vote);
}

    private function voteWithoutItem(string $attribute, TokenInterface $token, ?Vote $vote): bool
    {
        switch ($attribute) {
            case self::READ:
                if(!$this->security->isGranted(Role::ACCOUNT_READ->value)) {
                    $vote?->addReason('The user does not have read access to this kind of resource.');

                    return false;
                }

                return true;
            case self::CREATE:
                if(!$this->security->isGranted(Role::ACCOUNT_WRITE->value)) {
                    $vote?->addReason('The user does not have write access to this kind of resource.');

                    return false;
                }

                return true;
        }

        throw new \LogicException("Unsupported attribute: $attribute");
    }

    private function voteOnItem(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote): bool
    {
        $user = $token->getUser();

        if(!$this->security->isGranted(Role::ACCOUNT_READ->value)) {
            $vote?->addReason('The user does not have read access to this resource.');

            return false;
        }

        if($subject->getOwner()->getId() !== $user->getId()) {
            $vote?->addReason('The user does not own this resource.');

            return false;
        }

        switch ($attribute) {
            case self::READ:
                return true;
            case self::EDIT:
                if(!$this->security->isGranted(Role::ACCOUNT_WRITE->value)) {
                    $vote?->addReason('The user does not have write access to this resource.');

                    return false;
                }

                return true;
        }

        throw new \LogicException("Unsupported attribute: $attribute");
    }
}
