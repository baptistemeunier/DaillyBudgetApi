<?php

namespace App\Util;

/**
 * Exact conversions between decimal strings ("-12.3") and integer cents (-1230), without float rounding.
 */
final class Money
{
    public static function isDecimal(string $amount): bool
    {
        return 1 === preg_match('/^-?\d+(\.\d{1,2})?$/', $amount);
    }

    public static function toCents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        [$units, $decimals] = explode('.', ltrim($amount, '-') . '.', 3);
        $cents = (int) $units * 100 + (int) str_pad(substr($decimals, 0, 2), 2, '0');

        return $negative ? -$cents : $cents;
    }

    public static function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
