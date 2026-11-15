<?php

namespace App\Security\Voter;

use App\Entity\Notification;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use App\Entity\User;

/**
 * A notification is only visible / editable by its owner. No OAuth scope: every logged-in user has an inbox.
 */
final class NotificationVoter extends Voter
{
    public const READ = 'NOTIFICATION_READ';
    public const EDIT = 'NOTIFICATION_EDIT';
    public const DELETE = 'NOTIFICATION_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        // Generic validation
        if (!$subject && self::READ === $attribute) {
            return true;
        }

        // Object validation
        return in_array($attribute, [self::READ, self::EDIT, self::DELETE], true)
            && $subject instanceof Notification;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            $vote?->addReason('The user must be logged in to access this resource.');

            return false;
        }

        if (!$subject instanceof Notification) {
            return true;
        }

        if (!$subject->getOwner()?->getId()?->equals($user->getId())) {
            $vote?->addReason('The user does not own this resource.');

            return false;
        }

        return true;
    }
}
