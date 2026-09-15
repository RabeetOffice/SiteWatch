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

$id = Request::int('id');
$website = $id > 0 ? ServiceFactory::websites()->find($id) : null;
if ($website === null) {
    Response::error('Website not found.', [], 404);
}

$stats = ServiceFactory::uptime()->forWebsite($id);
$website['uptime_30d'] = $stats['uptime_30d'];
$open = ServiceFactory::incidents()->openFor($id);
$website['open_incidents'] = $open !== null ? 1 : 0;

$incidents = can('incidents.view') ? ServiceFactory::incidents()->search(['website_id' => $id], 8, 0) : ['rows' => []];

Response::success('', [
    'website'   => ServiceFactory::websiteService()->present($website),
    'stats'     => $stats,
    'incidents' => array_map([ReportService::class, 'presentIncident'], $incidents['rows']),
]);
