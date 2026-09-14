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

$pagination = Api::pagination(20, 100);
$criteria = [
    'q'          => mb_substr(Request::string('q'), 0, 100),
    'website_id' => Request::int('website_id'),
    'client'     => mb_substr(Request::string('client'), 0, 150),
    'type'       => Request::string('type'),
    'status'     => Request::string('status'),
    'from'       => Request::string('from'),
    'to'         => Request::string('to'),
];

$incidents = ServiceFactory::incidents();
$result = $incidents->search($criteria, $pagination['per_page'], $pagination['offset']);

Response::success('', [
    'rows'       => array_map([ReportService::class, 'presentIncident'], $result['rows']),
    'total'      => $result['total'],
    'page'       => $pagination['page'],
    'per_page'   => $pagination['per_page'],
    'open_count' => $incidents->countOpen(),
]);
