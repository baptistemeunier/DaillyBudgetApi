<?php

namespace App\MessageHandler;

use App\Message\DeliverPush;
use App\Message\SendPushNotification;
use App\Repository\NotificationRepository;
use App\Repository\NotificationSettingsRepository;
use App\Repository\PushSubscriptionRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Fans out the push of a notification: one DeliverPush per device of the owner, unless the type is disabled
 * in their settings (the in-app notification is kept either way).
 */
#[AsMessageHandler]
final readonly class SendPushNotificationHandler
{
    public function __construct(
        private NotificationRepository $notificationRepository,
        private NotificationSettingsRepository $settingsRepository,
        private PushSubscriptionRepository $subscriptionRepository,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendPushNotification $message): void
    {
        $notification = $this->notificationRepository->find($message->getId());
        $owner = $notification?->getOwner();

        // Deleted by the user meanwhile, or already pushed (message replayed)
        if (!$notification || !$owner || null !== $notification->getPushedAt()) {
            return;
        }

        if (!$this->settingsRepository->findOrDefault($owner)->isPushEnabled($notification->getType())) {
            $this->logger->info('Push skipped: type disabled by the user.', [
                'notification_id' => $message->getId()->toRfc4122(),
                'type' => $notification->getType()->value,
            ]);

            return;
        }

        foreach ($this->subscriptionRepository->findBy(['owner' => $owner]) as $subscription) {
            $this->bus->dispatch(new DeliverPush($notification->getId(), $subscription->getId()));
        }
    }
}
