<?php

namespace App\MessageHandler;

use App\Entity\AccountConnection;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Enum\UserActionType;
use App\Message\UserNotificationsRequest;
use App\Notification\Notifier;
use App\Repository\AccountConnectionRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use App\Service\ConnectionStatusResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Time-based notifications of a user, computed every day (UTC). Replaying it is harmless: the Notifier
 * never creates a notification whose deduplication key already exists.
 */
#[AsMessageHandler]
final readonly class UserNotificationsRequestHandler
{
    // A connection still waiting for the user's consent after this delay is notified
    private const int TO_CONFIGURE_AFTER_HOURS = 24;

    // MONTH_CLOSING is sent when this many days or fewer are left in the month (a missed day is caught up the next one)
    private const int MONTH_CLOSING_DAYS = 5;

    public function __construct(
        private UserRepository $userRepository,
        private AccountConnectionRepository $accountConnectionRepository,
        private TransactionRepository $transactionRepository,
        private ConnectionStatusResolver $connectionStatusResolver,
        private Notifier $notifier,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(UserNotificationsRequest $message): void
    {
        $user = $this->userRepository->find($message->getId());
        if (!$user instanceof User) {
            $this->logger->warning('User notifications skipped: user not found.', [
                'user_id' => $message->getId()->toRfc4122(),
            ]);

            return;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        foreach ($this->accountConnectionRepository->findBy(['owner' => $user, 'deletedAt' => null]) as $connection) {
            $this->notifyConnection($user, $connection, $now);
        }

        $this->notifyMonthClosing($user, $now);
    }

    private function notifyConnection(User $user, AccountConnection $connection, \DateTimeImmutable $now): void
    {
        $expiresAt = $connection->getAccessExpiresAt();
        $data = [
            'bankId' => $connection->getBankId(),
            'expiresAt' => $expiresAt?->format(\DateTimeInterface::ATOM),
        ];

        switch ($this->connectionStatusResolver->resolve($connection, $now)) {
            case UserActionType::CONNECTION_EXPIRED:
                $type = NotificationType::CONNECTION_EXPIRED;
                $this->notifier->notify($user, $type, $connection, $data, $type->dedupKey($connection->getId()));
                break;

            case UserActionType::CONNECTION_EXPIRING:
                // Two reminders at most: 7 days before, then 1 day before
                $type = NotificationType::CONNECTION_EXPIRING;
                $period = $expiresAt <= $now->modify('+1 day') ? '1D' : '7D';
                $data['daysLeft'] = (int) ceil(($expiresAt->getTimestamp() - $now->getTimestamp()) / 86400);
                $this->notifier->notify($user, $type, $connection, $data, $type->dedupKey($connection->getId(), $period));
                break;

            case UserActionType::CONNECTION_TO_CONFIGURE:
                // Leave the user the time to finish the consent at the bank
                if ($connection->getCreatedAt() > $now->modify(sprintf('-%d hours', self::TO_CONFIGURE_AFTER_HOURS))) {
                    break;
                }
                $type = NotificationType::CONNECTION_TO_CONFIGURE;
                unset($data['expiresAt']);
                $this->notifier->notify($user, $type, $connection, $data, $type->dedupKey($connection->getId()));
                break;

            default:
                break;
        }
    }

    /**
     * Reminder to close the budget of the month, when some of its transactions are still to categorize.
     */
    private function notifyMonthClosing(User $user, \DateTimeImmutable $now): void
    {
        $daysLeft = (int) $now->format('t') - (int) $now->format('j');
        if ($daysLeft < 1 || $daysLeft > self::MONTH_CLOSING_DAYS) {
            return;
        }

        $start = $now->modify('first day of this month')->setTime(0, 0);
        $count = $this->transactionRepository->countUncategorized($user, $start, $start->modify('+1 month'));
        if ($count === 0) {
            return;
        }

        $type = NotificationType::MONTH_CLOSING;
        $month = $now->format('Y-m');
        $this->notifier->notify($user, $type, null, [
            'count' => $count,
            'month' => $month,
            'daysLeft' => $daysLeft,
        ], $type->dedupKey($user->getId(), $month));
    }
}
