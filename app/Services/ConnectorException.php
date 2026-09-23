<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * A rejected connector request. The code is the HTTP status to answer with.
 */
final class ConnectorException extends RuntimeException
{
}
