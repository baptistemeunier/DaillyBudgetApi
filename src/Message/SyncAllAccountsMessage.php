<?php

namespace App\Message;

/**
 * Periodic sync (App\Scheduler\MainSchedule, 6:00 and 18:00 UTC): queues one AccountSyncRequest per syncable account.
 * The alerts (BALANCE_LOW, LARGE_TRANSACTION, INCOME_RECEIVED, BUDGET_*) follow each sync.
 */
final readonly class SyncAllAccountsMessage
{
}
