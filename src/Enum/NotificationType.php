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
    case BALANCE_LOW = 'BALANCE_LOW';
    case LARGE_TRANSACTION = 'LARGE_TRANSACTION';
    case INCOME_RECEIVED = 'INCOME_RECEIVED';
    case BUDGET_ALMOST_EXCEEDED = 'BUDGET_ALMOST_EXCEEDED';
    case BUDGET_EXCEEDED = 'BUDGET_EXCEEDED';

    public function description(): string
    {
        return match ($this) {
            self::CONNECTION_EXPIRING => 'Access to the bank expires in 7 days, then in 1 day',
            self::CONNECTION_EXPIRED => 'Access to the bank has expired: the user must reconnect it',
            self::CONNECTION_TO_CONFIGURE => 'The bank connection is still not completed 24 hours after its creation',
            self::MONTH_CLOSING => 'The month ends in 5 days or less and some of its transactions are not categorized',
            self::BALANCE_LOW => 'The balance of a checking account went below the threshold set on the account',
            self::LARGE_TRANSACTION => 'New debit above the large transaction threshold (several in one sync: grouped)',
            self::INCOME_RECEIVED => 'New credit categorized as income (several at once: grouped)',
            self::BUDGET_ALMOST_EXCEEDED => 'The spending of the month on a budgeted category reached 80 % of its budget',
            self::BUDGET_EXCEEDED => 'The spending of the month on a budgeted category exceeds its budget',
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
            self::MONTH_CLOSING,
            self::BALANCE_LOW,
            self::LARGE_TRANSACTION,
            self::BUDGET_ALMOST_EXCEEDED,
            self::BUDGET_EXCEEDED => true,
            // Expected and good news: pushed only if the user turns it on
            self::INCOME_RECEIVED => false,
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
