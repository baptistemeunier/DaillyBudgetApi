<?php

namespace App\Enum;

enum TransactionDirection: string
{
    case DEBIT = 'debit';
    case CREDIT = 'credit';

    public static function fromCents(int $cents): self
    {
        return $cents < 0 ? self::DEBIT : self::CREDIT;
    }

    public function allowsCents(int $cents): bool
    {
        return $this === self::fromCents($cents);
    }
}
