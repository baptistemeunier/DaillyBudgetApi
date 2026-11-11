<?php

namespace App\Enum;

enum AccountStatus: string
{
    case DISCOVERED = 'DISCOVERED';
    case ERROR = 'ERROR';
    case EXPIRED = 'EXPIRED';
    case PROCESSING = 'PROCESSING';
    case READY = 'READY';
    case SUSPENDED = 'SUSPENDED';

    public function description(): string
    {
        return match ($this) {
            self::DISCOVERED => 'User has successfully authenticated, and account has been discovered',
            self::ERROR => 'An error was encountered when processing account',
            self::EXPIRED => 'Access to account has expired as set in End User Agreement',
            self::PROCESSING => 'Account is being processed by the institution',
            self::READY => 'Account has been successfully processed',
            self::SUSPENDED => 'Account has been suspended (more than 10 consecutive failed attempts to access the account)',
        };
    }
}
