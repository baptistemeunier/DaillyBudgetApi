<?php

namespace App\Enum;

enum AccountSyncStatus: string
{
    case PENDING = 'PENDING';
    case IN_PROGRESS = 'IN_PROGRESS';
    case FAILED = 'FAILED';
    case SUCCESS = 'SUCCESS';
    case SKIPPED = 'SKIPPED';
}
