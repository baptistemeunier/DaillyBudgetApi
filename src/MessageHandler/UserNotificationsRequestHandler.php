<?php

namespace App\MessageHandler;

use App\Entity\AccountConnection;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Enum\UserActionType;
use App\Message\UserNotificationsRequest;
use App\Notification\Notifier;
use App\Repository\AccountConnectionRepository;
use App\Repository\UserRepository;
use App\Service\BankNameResolver;
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

    public function __construct(
        private UserRepository $userRepository,
        private AccountConnectionRepository $accountConnectionRepository,
        private ConnectionStatusResolver $connectionStatusResolver,
        private BankNameResolver $bankNameResolver,
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

        // Only the owner of a connection can renew or configure it (MONTH_CLOSING is sent once for all the users,
        // by DailyNotificationsMessageHandler)
        foreach ($this->accountConnectionRepository->findBy(['owner' => $user, 'deletedAt' => null]) as $connection) {
            $this->notifyConnection($user, $connection, $now);
        }
    }

    private function notifyConnection(User $user, AccountConnection $connection, \DateTimeImmutable $now): void
    {
        $type = $this->connectionStatusResolver->resolve($connection, $now);
        if (null === $type) {
            return;
        }

        // The bank name is stored: the service worker writes the push text without calling the API
        $expiresAt = $connection->getAccessExpiresAt();
        $data = [
            'bankId' => $connection->getBankId(),
            'bankName' => $this->bankNameResolver->resolve($connection->getBankId()),
            'expiresAt' => $expiresAt?->format(\DateTimeInterface::ATOM),
        ];

        switch ($type) {
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
}
