<?php

namespace App\Client;

/**
 * Thrown when GoCardless answers 429 Too Many Requests.
 */
final class GoCardlessRateLimitException extends \RuntimeException
{
    public function __construct(
        public readonly int $retryAfter,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(sprintf('GoCardless rate limit reached, retry in %d seconds.', $retryAfter), 429, $previous);
    }
}
