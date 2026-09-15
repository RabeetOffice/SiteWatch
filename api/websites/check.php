<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityService;
use App\Services\ServiceFactory;

Api::boot(['POST'], permission: 'websites.check');

$id = Request::int('id');
$websites = ServiceFactory::websites();
$website = $id > 0 ? $websites->find($id) : null;
if ($website === null) {
    Response::error('Website not found.', [], 404);
}

// A manual check may take up to the configured request timeout (plus SSL inspection).
set_time_limit(max(60, App::settings()->getInt('request_timeout', 30) + 30));

try {
    $monitor = ServiceFactory::monitor();
    $outcome = $monitor->checkNow($website, 'manual');
} catch (Throwable $e) {
    App::logger('monitor')->error('Manual check failed', ['id' => $id, 'error' => $e->getMessage()]);
    Response::error('Unable to check website: ' . $e->getMessage(), [], 500);
}

ActivityService::log('check.manual', sprintf('Manual check of %s: %s', $website['name'], $outcome['result']->label()), $id, [
    'status' => $outcome['result']->status, 'http_status' => $outcome['result']->httpStatus, 'response_time' => $outcome['result']->responseTime,
]);

$row = $websites->find($id) ?? $outcome['website'];
// Attach the 30-day uptime + open incident count the list view expects.
$row['uptime_30d'] = ServiceFactory::uptime()->forWebsite($id)['uptime_30d'];
$row['open_incidents'] = ServiceFactory::incidents()->openFor($id) !== null ? 1 : 0;

Response::success('Check completed.', [
    'website'           => ServiceFactory::websiteService()->present($row),
    'result'            => $outcome['result']->toArray(),
    'incident_opened'   => $outcome['incident_opened'],
    'incident_resolved' => $outcome['incident_resolved'],
    'ssl'               => $outcome['ssl'],
]);
