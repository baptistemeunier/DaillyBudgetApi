<?php

namespace App\MessageHandler;

use App\Enum\NotificationType;
use App\Message\DailyNotificationsMessage;
use App\Message\UserNotificationsRequest;
use App\Notification\Notifier;
use App\Repository\NotificationRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class DailyNotificationsMessageHandler
{
    // Read notifications are deleted after this delay; unread ones are kept
    private const int PURGE_READ_AFTER_DAYS = 90;

    // MONTH_CLOSING is sent when this many days or fewer are left in the month (a missed day is caught up the next one)
    private const int MONTH_CLOSING_DAYS = 5;

    public function __construct(
        private NotificationRepository $notificationRepository,
        private UserRepository $userRepository,
        private TransactionRepository $transactionRepository,
        private Notifier $notifier,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DailyNotificationsMessage $message): void
    {
        $purged = $this->notificationRepository->deleteReadBefore(
            new \DateTimeImmutable(sprintf('-%d days', self::PURGE_READ_AFTER_DAYS)),
        );

        // The data is shared: the month closing is computed once, for all the users
        $this->notifyMonthClosing(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        // Connection reminders: per owner
        $userIds = $this->userRepository->findIdsWithActiveBanking();
        foreach ($userIds as $userId) {
            $this->bus->dispatch(new UserNotificationsRequest($userId));
        }

        $this->logger->info('Daily notifications dispatched.', [
            'users' => count($userIds),
            'purged' => $purged,
        ]);
    }

    /**
     * Reminder to close the budget of the month, when some of its transactions are still to categorize.
     */
    private function notifyMonthClosing(\DateTimeImmutable $now): void
    {
        $daysLeft = (int) $now->format('t') - (int) $now->format('j');
        if ($daysLeft < 1 || $daysLeft > self::MONTH_CLOSING_DAYS) {
            return;
        }

        $start = $now->modify('first day of this month')->setTime(0, 0);
        $count = $this->transactionRepository->countUncategorized($start, $start->modify('+1 month'));
        if ($count === 0) {
            return;
        }

        $type = NotificationType::MONTH_CLOSING;
        $month = $now->format('Y-m');
        $this->notifier->notify(null, $type, null, [
            'count' => $count,
            'month' => $month,
            'daysLeft' => $daysLeft,
        ], $type->dedupKey(null, $month));
    }
}
