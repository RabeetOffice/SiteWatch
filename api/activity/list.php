<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityService;
use App\Services\ServiceFactory;

Api::boot(['GET']);

$pagination = Api::pagination(30, 100);
$result = ServiceFactory::activity()->search([
    'q'          => mb_substr(Request::string('q'), 0, 100),
    'action'     => mb_substr(Request::string('action'), 0, 50),
    'website_id' => Request::int('website_id'),
], $pagination['per_page'], $pagination['offset']);

Response::success('', [
    'rows'     => array_map([ActivityService::class, 'present'], $result['rows']),
    'total'    => $result['total'],
    'page'     => $pagination['page'],
    'per_page' => $pagination['per_page'],
]);
