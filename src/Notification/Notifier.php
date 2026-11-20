<?php

namespace App\Notification;

use App\Entity\Account;
use App\Entity\AccountConnection;
use App\Entity\Category;
use App\Entity\Notification;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\NotificationRecipients;
use App\Enum\NotificationType;
use App\Message\SendPushNotification;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Single entry point to create a notification.
 *
 * The recipients depend on the type (NotificationType::recipients()): all the users, or the owner of the subject
 * only. Each recipient gets their own notification (inbox, read state), pushed on their own devices according to
 * their own settings.
 *
 * The (owner, dedupKey) pair is unique: a notification already sent is never created again, so a handler or a
 * scheduled task can be replayed without duplicate. Inserted through DBAL so that a duplicate (unique constraint
 * violation) does not close the EntityManager of the caller.
 * A notification created is then pushed asynchronously (SendPushNotification), if the user enabled its type.
 */
final readonly class Notifier
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private MessageBusInterface $bus,
        private UserRepository $userRepository,
    ) {
    }

    /**
     * Notifies the recipients of the type.
     *
     * @param User|null $owner owner of the subject; required when the type goes to the owner only
     * @param array<string, mixed> $data parameters of the text (count, expiresAt...)
     *
     * @return int number of notifications created (the recipients who already had it are skipped)
     */
    public function notify(
        ?User $owner,
        NotificationType $type,
        Account|AccountConnection|Transaction|Category|null $subject,
        array $data,
        string $dedupKey,
    ): int {
        $created = 0;
        foreach ($this->recipients($type, $owner) as $recipient) {
            $created += (int) $this->notifyRecipient($recipient, $type, $subject, $data, $dedupKey);
        }

        return $created;
    }

    /**
     * @return list<User>
     */
    public function recipients(NotificationType $type, ?User $owner): array
    {
        return match ($type->recipients()) {
            NotificationRecipients::ALL => $this->userRepository->findAll(),
            NotificationRecipients::OWNER => [$owner ?? throw new \LogicException(sprintf('%s goes to the owner of its subject: an owner is required.', $type->value))],
        };
    }

    /**
     * Notifies this user only, whatever the recipients of the type (the caller chose them, e.g. per user threshold).
     *
     * @param array<string, mixed> $data parameters of the text (count, expiresAt...)
     *
     * @return bool true when the notification was created, false when the user already had it
     */
    public function notifyRecipient(
        User $owner,
        NotificationType $type,
        Account|AccountConnection|Transaction|Category|null $subject,
        array $data,
        string $dedupKey,
    ): bool {
        $metadata = $this->entityManager->getClassMetadata(Notification::class);
        $now = new \DateTimeImmutable();
        $id = Uuid::v7();

        $row = [
            'id' => $id,
            'owner_id' => $owner->getId(),
            'type' => $type->value,
            'data' => $data,
            'dedup_key' => $dedupKey,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $types = [
            'id' => UuidType::NAME,
            'owner_id' => UuidType::NAME,
            'type' => Types::STRING,
            'data' => Types::JSON,
            'dedup_key' => Types::STRING,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ];

        $subjectField = match (true) {
            $subject instanceof Account => 'account',
            $subject instanceof AccountConnection => 'accountConnection',
            $subject instanceof Transaction => 'transaction',
            $subject instanceof Category => 'category',
            default => null,
        };
        if (null !== $subjectField) {
            $column = $metadata->getSingleAssociationJoinColumnName($subjectField);
            $row[$column] = $subject->getId();
            $types[$column] = UuidType::NAME;
        }

        try {
            $this->entityManager->getConnection()->insert($metadata->getTableName(), $row, $types);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        $this->logger->info('Notification created.', [
            'notification_id' => $id->toRfc4122(),
            'owner_id' => $owner->getId()->toRfc4122(),
            'type' => $type->value,
            'dedup_key' => $dedupKey,
        ]);

        $this->bus->dispatch(new SendPushNotification($id));

        return true;
    }
}
