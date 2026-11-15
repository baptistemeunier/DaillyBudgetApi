<?php

namespace App\Enum;

use Symfony\Component\Uid\Uuid;

/**
 * Kinds of notification (GET /notifications). The client composes the text from the type and the data.
 */
enum NotificationType: string
{
    case CONNECTION_EXPIRING = 'CONNECTION_EXPIRING';
    case CONNECTION_EXPIRED = 'CONNECTION_EXPIRED';
    case CONNECTION_TO_CONFIGURE = 'CONNECTION_TO_CONFIGURE';
    case MONTH_CLOSING = 'MONTH_CLOSING';

    public function description(): string
    {
        return match ($this) {
            self::CONNECTION_EXPIRING => 'Access to the bank expires in 7 days, then in 1 day',
            self::CONNECTION_EXPIRED => 'Access to the bank has expired: the user must reconnect it',
            self::CONNECTION_TO_CONFIGURE => 'The bank connection is still not completed 24 hours after its creation',
            self::MONTH_CLOSING => 'The month ends in 5 days or less and some of its transactions are not categorized',
        };
    }

    /**
     * Whether the notification is pushed when the user has not chosen (NotificationSettings.push).
     * The in-app notification is always created: the preference only turns the push off.
     */
    public function defaultPush(): bool
    {
        return match ($this) {
            self::CONNECTION_EXPIRING,
            self::CONNECTION_EXPIRED,
            self::CONNECTION_TO_CONFIGURE,
            self::MONTH_CLOSING => true,
        };
    }

    /**
     * Deduplication key "{TYPE}_{subject uuid}[_{period}]": the period says how often the notification can come back
     * for this subject; without period, it is sent only once.
     */
    public function dedupKey(Uuid $subject, ?string $period = null): string
    {
        return sprintf('%s_%s', $this->value, $subject->toRfc4122()) . (null !== $period ? '_' . $period : '');
    }
}
