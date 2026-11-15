<?php

namespace App\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;
use Symfony\Component\Uid\Uuid;

/**
 * Sends the push of one notification to one device. On its own "push" transport, which retries on a push service
 * error (5xx, 429, network): a device that already received the push does not get it twice.
 */
#[AsMessage('push')]
final readonly class DeliverPush
{
    public function __construct(
        private Uuid $notificationId,
        private Uuid $subscriptionId,
    ) {
    }

    public function getNotificationId(): Uuid
    {
        return $this->notificationId;
    }

    public function getSubscriptionId(): Uuid
    {
        return $this->subscriptionId;
    }
}
