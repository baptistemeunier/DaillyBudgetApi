<?php

namespace App\Enum;

enum CategoryType: string
{
    case EXPENSE = 'expense';
    case INCOME = 'income';
    case TRANSFER = 'transfer';

    /**
     * Credits take income / transfer categories, debits take expense / transfer ones.
     */
    public function allowsAmountSign(int $cents): bool
    {
        return match ($this) {
            self::TRANSFER => true,
            self::INCOME => $cents > 0,
            self::EXPENSE => $cents < 0,
        };
    }
}
