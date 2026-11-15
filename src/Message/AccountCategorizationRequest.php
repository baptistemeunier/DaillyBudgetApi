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
     * @param Uuid $id id of the Account to categorize
     */
    public function __construct(private Uuid $id)
    {
    }

    public function getId(): Uuid
    {
        return $this->id;
    }
}
