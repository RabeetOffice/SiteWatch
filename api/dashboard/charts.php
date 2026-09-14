<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\Response;
use App\Services\ServiceFactory;

Api::boot(['GET']);

Response::success('', ServiceFactory::dashboard()->charts());
