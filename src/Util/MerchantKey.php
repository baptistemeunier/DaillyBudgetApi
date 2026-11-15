<?php

namespace App\Util;

/**
 * Merchant key: the description of a transaction reduced to the words naming the merchant,
 * so that the same merchant gives the same key from one transaction to another
 * ("CB CARREFOUR MARKET 12/11 X4521" → "CARREFOUR MARKET"). Also used to normalize the rule patterns.
 *
 * Uppercase ASCII words separated by a single space: matching a pattern is a word-boundary search.
 */
final class MerchantKey
{
    public const int MAX_LENGTH = 100;

    /**
     * Bank wording removed from the start of the description (payment means, not the merchant).
     */
    private const array PREFIXES = [
        'CB', 'CARTE', 'PRLV', 'PRELEVEMENT', 'SEPA', 'VIR', 'VIREMENT', 'INST', 'RECU', 'EMIS',
        'PAIEMENT', 'PAIEMENTS', 'ACHAT', 'RETRAIT', 'DAB', 'FACTURE', 'ECHEANCE', 'REMISE', 'CHEQUE', 'CHQ', 'DE', 'A', 'PAR',
    ];

    public static function fromDescription(?string $description): ?string
    {
        if (null === $description) {
            return null;
        }

        $words = self::words($description);

        while ($words && in_array($words[0], self::PREFIXES, true)) {
            array_shift($words);
        }

        return self::join($words);
    }

    /**
     * Same normalization as the keys, without removing the bank wording: a pattern typed by the user is kept as is.
     */
    public static function normalizePattern(?string $pattern): ?string
    {
        return null === $pattern ? null : self::join(self::words($pattern));
    }

    /**
     * The pattern appears in the key as whole words.
     */
    public static function matches(?string $key, ?string $pattern): bool
    {
        if (null === $key || null === $pattern || '' === $pattern) {
            return false;
        }

        return str_contains(' '.$key.' ', ' '.$pattern.' ');
    }

    /**
     * @return list<string> uppercase ASCII words, without the ones holding a digit (dates, card numbers, references)
     */
    private static function words(string $text): array
    {
        // intl, not iconv: iconv TRANSLIT does not transliterate on musl (Alpine image)
        static $transliterator = null;
        $transliterator ??= \Transliterator::create('Any-Latin; Latin-ASCII');
        $ascii = $transliterator?->transliterate($text);
        $upper = strtoupper(false === $ascii || null === $ascii ? $text : $ascii);
        $words = preg_split('/[^A-Z0-9]+/', $upper, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($words, static fn (string $word) => !preg_match('/\d/', $word)));
    }

    /**
     * @param list<string> $words
     */
    private static function join(array $words): ?string
    {
        $key = '';
        foreach ($words as $word) {
            $next = '' === $key ? $word : $key.' '.$word;
            if (strlen($next) > self::MAX_LENGTH) {
                break;
            }
            $key = $next;
        }

        return '' === $key ? null : $key;
    }
}
