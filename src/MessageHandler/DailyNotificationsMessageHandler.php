<?php

namespace App\MessageHandler;

use App\Message\DailyNotificationsMessage;
use App\Message\UserNotificationsRequest;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class DailyNotificationsMessageHandler
{
    // Read notifications are deleted after this delay; unread ones are kept
    private const int PURGE_READ_AFTER_DAYS = 90;

    public function __construct(
        private NotificationRepository $notificationRepository,
        private UserRepository $userRepository,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DailyNotificationsMessage $message): void
    {
        $purged = $this->notificationRepository->deleteReadBefore(
            new \DateTimeImmutable(sprintf('-%d days', self::PURGE_READ_AFTER_DAYS)),
        );

        $userIds = $this->userRepository->findIdsWithActiveBanking();
        foreach ($userIds as $userId) {
            $this->bus->dispatch(new UserNotificationsRequest($userId));
        }

        $this->logger->info('Daily notifications dispatched.', [
            'users' => count($userIds),
            'purged' => $purged,
        ]);
    }
}
