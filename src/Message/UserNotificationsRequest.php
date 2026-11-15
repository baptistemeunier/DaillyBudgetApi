<?php

namespace App\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;
use Symfony\Component\Uid\Uuid;

/**
 * Creates the time-based notifications of a user (bank access expiring / expired / to configure, month closing).
 */
#[AsMessage('async')]
final readonly class UserNotificationsRequest
{
    /**
     * @param Uuid $id id of the User
     */
    public function __construct(private Uuid $id)
    {
    }

    public function getId(): Uuid
    {
        return $this->id;
    }
}
