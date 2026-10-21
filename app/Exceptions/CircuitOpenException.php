<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown instead of calling a dependency whose circuit is open. The queue
 * retries the job later with its normal backoff.
 */
final class CircuitOpenException extends RuntimeException
{
    public static function for(string $name, int $retryAfterSeconds): self
    {
        return new self("Circuit [{$name}] is open; retry in {$retryAfterSeconds}s.");
    }
}
