<?php

namespace App\Enum;

enum AccountSyncError: string
{
    case DAILY_LIMIT_REACHED = 'DAILY_LIMIT_REACHED';
    case RATE_LIMITED = 'RATE_LIMITED';
    case ACCESS_EXPIRED = 'ACCESS_EXPIRED';
    case INVALID_DATA = 'INVALID_DATA';
    case UNKNOWN = 'UNKNOWN';

    public function description(): string
    {
        return match ($this) {
            self::DAILY_LIMIT_REACHED => 'The account has already been synchronized too many times in the last 24 hours',
            self::RATE_LIMITED => 'The bank data provider rate limit has been reached',
            self::ACCESS_EXPIRED => 'Access to the account has expired or has been revoked',
            self::INVALID_DATA => 'The bank returned data that could not be processed',
            self::UNKNOWN => 'An unexpected error occurred',
        };
    }
}
