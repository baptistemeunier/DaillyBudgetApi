<?php

namespace App\Enum;

/**
 * Who set a TransactionSplit: the user (every split sent through the API), or a categorization rule.
 */
enum SplitSource: string
{
    case MANUAL = 'manual';
    case RULE = 'rule';
}
