<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\Request;
use App\Core\Response;
use App\Services\ReportService;
use App\Services\ServiceFactory;

Api::boot(['GET']);

$criteria = [
    'q'          => mb_substr(Request::string('q'), 0, 100),
    'website_id' => Request::int('website_id'),
    'client'     => mb_substr(Request::string('client'), 0, 150),
    'type'       => Request::string('type'),
    'status'     => Request::string('status'),
    'from'       => Request::string('from'),
    'to'         => Request::string('to'),
];

$rows = ServiceFactory::incidents()->searchAll($criteria, 20000);
$export = ReportService::exportIncidents($rows);
Response::csv('sitewatch-incidents-' . gmdate('Y-m-d') . '.csv', $export['headers'], $export['rows']);
