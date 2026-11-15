<?php

namespace App\Enum;

/**
 * Who set a TransactionSplit: the user (every split sent through the API), a categorization rule (to be reviewed),
 * or a rule whose split the user confirmed (PATCH /transactions/{id} with confirm: true; the rule link is kept, for the stats).
 */
enum SplitSource: string
{
    case MANUAL = 'manual';
    case RULE = 'rule';
    case CONFIRMED = 'confirmed';

    /**
     * Sources validated by the user: the categorization rules leave the transaction alone.
     *
     * @return list<self>
     */
    public static function userSet(): array
    {
        return [self::MANUAL, self::CONFIRMED];
    }
}
