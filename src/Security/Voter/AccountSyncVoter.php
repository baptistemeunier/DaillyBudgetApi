<?php

namespace App\Security\Voter;

use App\Entity\AccountSync;
use App\Repository\AccountRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Synchronizations have no owner: they are readable by whoever can read their account.
 */
final class AccountSyncVoter extends Voter
{
    public const READ = 'ACCOUNT_SYNC_READ';
    public const ACCOUNT_READ = 'ACCOUNT_SYNC_ACCOUNT_READ';

    public function __construct(private Security $security, private AccountRepository $accountRepository)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return ($attribute === self::ACCOUNT_READ && is_string($subject) && Uuid::isValid($subject))
            || ($attribute === self::READ && $subject instanceof AccountSync);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if (!$token->getUser() instanceof UserInterface) {
            $vote?->addReason('The user must be logged in to access this resource.');

            return false;
        }

        $account = $subject instanceof AccountSync
            ? $subject->getAccount()
            : $this->accountRepository->find($subject);
        if (!$account) {
            return false;
        }

        if (!$this->security->isGranted(AccountVoter::READ, $account)) {
            $vote?->addReason('The user cannot read this account.');

            return false;
        }

        return true;
    }
}
