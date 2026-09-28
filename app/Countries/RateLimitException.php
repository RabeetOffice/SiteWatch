<?php

declare(strict_types=1);

namespace App\Countries;

use RuntimeException;

/**
 * The provider's free allowance is used up; try again after $retryAfter seconds.
 */
final class RateLimitException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}
