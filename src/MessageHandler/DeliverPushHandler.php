<?php

namespace App\MessageHandler;

use App\Message\DeliverPush;
use App\Notification\PushMessageFactory;
use App\Notification\WebPushSender;
use App\Repository\NotificationRepository;
use App\Repository\NotificationSettingsRepository;
use App\Repository\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Sends the push of a notification to one device.
 * - accepted: lastSuccessAt on the subscription, pushedAt on the notification (first delivery);
 * - 404 / 410: the subscription is dead (browser unsubscribed), it is deleted;
 * - 5xx, 429 or network error: an exception, retried by the "push" transport (max_retries);
 * - other 4xx: logged, not retried (the same request would fail again).
 */
#[AsMessageHandler]
final readonly class DeliverPushHandler
{
    public function __construct(
        private NotificationRepository $notificationRepository,
        private PushSubscriptionRepository $subscriptionRepository,
        private NotificationSettingsRepository $settingsRepository,
        private PushMessageFactory $messageFactory,
        private WebPushSender $sender,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DeliverPush $message): void
    {
        $notification = $this->notificationRepository->find($message->getNotificationId());
        $subscription = $this->subscriptionRepository->find($message->getSubscriptionId());
        $owner = $notification?->getOwner();

        // Notification deleted, device unsubscribed, or device given to another user meanwhile
        if (!$notification || !$subscription || !$owner || !$subscription->getOwner()?->getId()?->equals($owner->getId())) {
            return;
        }

        $context = [
            'notification_id' => $message->getNotificationId()->toRfc4122(),
            'push_subscription_id' => $message->getSubscriptionId()->toRfc4122(),
        ];

        $settings = $this->settingsRepository->findOrDefault($owner);
        $payload = $this->messageFactory->create($notification, (string) $subscription->getLocale(), $settings->isHideAmounts());
        $report = $this->sender->send($subscription, $payload, $this->messageFactory->topic($notification));

        if ($report->isSuccess()) {
            $now = new \DateTimeImmutable();
            $subscription->setLastSuccessAt($now);
            if (null === $notification->getPushedAt()) {
                $notification->setPushedAt($now);
            }
            $this->entityManager->flush();

            return;
        }

        $status = $report->getResponse()?->getStatusCode();
        $context += ['status' => $status, 'reason' => $report->getReason()];

        if ($report->isSubscriptionExpired()) {
            $this->entityManager->remove($subscription);
            $this->entityManager->flush();
            $this->logger->info('Push subscription expired, deleted.', $context);

            return;
        }

        if (null === $status || 429 === $status || $status >= 500) {
            // Retried by the "push" transport
            throw new \RuntimeException(sprintf('Push service error (%s): %s', $status ?? 'network', $report->getReason()));
        }

        $this->logger->warning('Push rejected by the push service, not retried.', $context);
    }
}
