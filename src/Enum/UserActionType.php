<?php

namespace App\Enum;

/**
 * Things the user has to deal with, listed by GET /user_actions.
 */
enum UserActionType: string
{
    case CONNECTION_EXPIRED = 'CONNECTION_EXPIRED';
    case CONNECTION_TO_CONFIGURE = 'CONNECTION_TO_CONFIGURE';
    case CONNECTION_EXPIRING = 'CONNECTION_EXPIRING';
    case TRANSACTIONS_TO_CATEGORIZE = 'TRANSACTIONS_TO_CATEGORIZE';

    /**
     * Higher first.
     */
    public function priority(): int
    {
        return match ($this) {
            self::CONNECTION_EXPIRED => 100,
            self::CONNECTION_TO_CONFIGURE => 75,
            self::CONNECTION_EXPIRING => 50,
            self::TRANSACTIONS_TO_CATEGORIZE => 10,
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CONNECTION_EXPIRED => 'Access to the bank has expired: the user must reconnect it',
            self::CONNECTION_TO_CONFIGURE => 'The bank connection has not been completed by the user',
            self::CONNECTION_EXPIRING => 'Access to the bank expires soon: the user should reconnect it',
            self::TRANSACTIONS_TO_CATEGORIZE => 'Some transactions are not fully categorized',
        };
    }
}
