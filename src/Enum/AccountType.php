<?php

namespace App\Enum;

enum AccountType: string
{
    case CHECKING = 'checking';
    case SAVINGS = 'savings';
    case INVESTMENT = 'investment';

    /**
     * Guess from the ISO 20022 cashAccountType GoCardless gives in the account details.
     * Anything unknown is a checking account: the user can fix it with PATCH /accounts/{id}.
     */
    public static function fromCashAccountType(?string $cashAccountType): self
    {
        return match (strtoupper((string) $cashAccountType)) {
            'SVGS', 'ONDP' => self::SAVINGS,
            'MOMA' => self::INVESTMENT,
            default => self::CHECKING,
        };
    }
}
