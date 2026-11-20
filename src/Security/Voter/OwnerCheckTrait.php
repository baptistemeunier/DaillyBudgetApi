<?php

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Data is shared between all the users: the owner (the user who created the resource) is only
 * checked for the operations reserved to them (deletion, synchronization).
 */
trait OwnerCheckTrait
{
    /**
     * @param object{getOwner(): ?User} $subject
     */
    private function isOwner(object $subject, UserInterface $user): bool
    {
        $owner = $subject->getOwner();

        return $owner && $user instanceof User && $owner->getId()?->equals($user->getId());
    }
}
