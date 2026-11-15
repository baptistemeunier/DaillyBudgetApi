<?php

namespace App\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;
use Symfony\Component\Uid\Uuid;

/**
 * Pushes a notification just created by the Notifier to the devices of its owner, if the type is enabled
 * in their settings. Fans out one DeliverPush per device.
 */
#[AsMessage('async')]
final readonly class SendPushNotification
{
    /**
     * @param Uuid $id id of the Notification
     */
    public function __construct(private Uuid $id)
    {
    }

    public function getId(): Uuid
    {
        return $this->id;
    }
}
