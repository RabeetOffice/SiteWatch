<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\Request;
use App\Core\Response;
use App\Services\ServiceFactory;

Api::boot(['GET']);

$report = ServiceFactory::reports()->uptimeReport([
    'website_id' => Request::int('website_id'),
    'client'     => mb_substr(Request::string('client'), 0, 150),
    'from'       => Request::string('from'),
    'to'         => Request::string('to'),
]);

Response::success('', $report);
