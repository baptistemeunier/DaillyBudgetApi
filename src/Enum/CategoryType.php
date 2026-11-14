<?php

namespace App\Enum;

enum CategoryType: string
{
    case EXPENSE = 'expense';
    case INCOME = 'income';
    case TRANSFER = 'transfer';
    case SAVING = 'saving';

    /**
     * Credits take income / transfer / saving categories, debits take expense / transfer / saving ones.
     */
    public function allowsAmountSign(int $cents): bool
    {
        return match ($this) {
            self::TRANSFER, self::SAVING => true,
            self::INCOME => $cents > 0,
            self::EXPENSE => $cents < 0,
        };
    }
}
