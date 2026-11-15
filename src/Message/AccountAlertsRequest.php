<?php

namespace App\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;
use Symfony\Component\Uid\Uuid;

/**
 * Alerts that follow a synchronization and don't need the categories: CONNECTION_EXPIRED (bank refused the sync),
 * BALANCE_LOW, LARGE_TRANSACTION. Sent by AccountSyncRequestHandler. The alerts based on the categories
 * (INCOME_RECEIVED, BUDGET_*) follow the categorization (AccountCategorizationRequest with alerts).
 */
#[AsMessage('async')]
final readonly class AccountAlertsRequest
{
    /**
     * @param Uuid        $syncId        id of the AccountSync
     * @param string|null $balanceBefore balance of the account before the sync (BALANCE_LOW only fires on a crossing)
     */
    public function __construct(
        private Uuid $syncId,
        private ?string $balanceBefore,
    ) {
    }

    public function getSyncId(): Uuid
    {
        return $this->syncId;
    }

    public function getBalanceBefore(): ?string
    {
        return $this->balanceBefore;
    }
}
