<?php

namespace App\Client;

/**
 * Thrown when GoCardless answers 401 because the End User Agreement has expired:
 * the end user must reconnect the bank with a new requisition.
 */
final class GoCardlessAccessExpiredException extends \RuntimeException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 401, $previous);
    }
}
