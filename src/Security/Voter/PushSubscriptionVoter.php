<?php

namespace App\Security\Voter;

use App\Entity\PushSubscription;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * A push subscription is only visible / deletable by its owner. No OAuth scope, like the notifications.
 * Subscribing (PUT) only needs a logged-in user: the processor gives the subscription to the current user.
 */
final class PushSubscriptionVoter extends Voter
{
    public const READ = 'PUSH_SUBSCRIPTION_READ';
    public const WRITE = 'PUSH_SUBSCRIPTION_WRITE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::READ, self::WRITE], true)
            && (!$subject || $subject instanceof PushSubscription);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            $vote?->addReason('The user must be logged in to access this resource.');

            return false;
        }

        if (!$subject instanceof PushSubscription) {
            return true;
        }

        if (!$subject->getOwner()?->getId()?->equals($user->getId())) {
            $vote?->addReason('The user does not own this resource.');

            return false;
        }

        return true;
    }
}
