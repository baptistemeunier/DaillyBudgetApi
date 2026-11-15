<?php

namespace App\Security\Voter;

use App\Entity\NotificationSettings;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Notification settings are only visible / editable by their owner. No OAuth scope, like the notifications.
 */
final class NotificationSettingsVoter extends Voter
{
    public const READ = 'NOTIFICATION_SETTINGS_READ';
    public const EDIT = 'NOTIFICATION_SETTINGS_EDIT';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::READ, self::EDIT], true)
            && (!$subject || $subject instanceof NotificationSettings);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            $vote?->addReason('The user must be logged in to access this resource.');

            return false;
        }

        if (!$subject instanceof NotificationSettings) {
            return true;
        }

        if (!$subject->getOwner()?->getId()?->equals($user->getId())) {
            $vote?->addReason('The user does not own this resource.');

            return false;
        }

        return true;
    }
}
