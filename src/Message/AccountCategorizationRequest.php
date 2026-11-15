<?php

namespace App\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;
use Symfony\Component\Uid\Uuid;

/**
 * Applies the categorization rules of the owner to the transactions of an account (sent after each successful sync).
 */
#[AsMessage('async')]
final readonly class AccountCategorizationRequest
{
    /**
     * @param Uuid $id     id of the Account to categorize
     * @param bool $alerts true after a sync: the transactions categorized by this run may trigger INCOME_RECEIVED
     *                     and BUDGET_* (false after a change of the rules, or on the first sync of the account)
     */
    public function __construct(
        private Uuid $id,
        private bool $alerts = false,
    ) {
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function withAlerts(): bool
    {
        return $this->alerts;
    }
}
