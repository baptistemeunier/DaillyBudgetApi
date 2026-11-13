<?php

namespace App\Security\Voter;

use App\Entity\Transaction;
use App\Enum\Role;
use App\Repository\AccountRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

final class TransactionVoter extends Voter
{
    public const READ = 'TRANSACTION_READ';
    public const ACCOUNT_READ = 'TRANSACTION_ACCOUNT_READ';
    public const UPDATE = 'TRANSACTION_UPDATE';

    public function __construct(private Security $security, private AccountRepository $accountRepository)
    {

    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        // Generic validation
        if(!$subject && in_array($attribute, [self::READ])) {
            return true;
        }

        if(is_string($subject) && Uuid::isValid($subject) && in_array($attribute, [self::ACCOUNT_READ])) {
            return true;
        }

        // Object validation
        return in_array($attribute, [self::READ, self::UPDATE])
            && $subject instanceof Transaction;
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


        if (Uuid::isValid($subject) && $attribute === self::ACCOUNT_READ) {
            $account = $this->accountRepository->find($subject);
            if(!$account) {
                return false;
            }

            if(!$this->security->isGranted(AccountVoter::READ, $account)) {
                $vote?->addReason('The user cannot read this account to this resource.');
                return false;
            }

            return true;
        }

        if (!$subject instanceof Transaction) {
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
        }

        throw new \LogicException("Unsupported attribute: $attribute");
    }

    private function voteOnItem(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote): bool
    {
        if(!$this->security->isGranted(Role::ACCOUNT_READ->value)) {
            $vote?->addReason('The user does not have read access to this resource.');

            return false;
        }

        // Transactions have no owner: ownership is the one of their account
        if(!$this->security->isGranted(AccountVoter::READ, $subject->getAccount())) {
            $vote?->addReason('The user does not own this resource.');

            return false;
        }

        switch ($attribute) {
            case self::READ:
                return true;
            case self::UPDATE:
                if(!$this->security->isGranted(Role::ACCOUNT_WRITE->value)) {
                    $vote?->addReason('The user does not have write access to this resource.');

                    return false;
                }

                return true;
        }

        throw new \LogicException("Unsupported attribute: $attribute");
    }
}
