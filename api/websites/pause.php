<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Services\ServiceFactory;

Api::boot(['POST'], permission: 'websites.manage');

$id = Request::int('id');
$action = Request::string('action', 'pause');
if (!in_array($action, ['pause', 'resume'], true)) {
    Response::error('Invalid action.', ['action' => 'Must be pause or resume.'], 422);
}

$websites = ServiceFactory::websites();
$website = $id > 0 ? $websites->find($id) : null;
if ($website === null) {
    Response::error('Website not found.', [], 404);
}

$service = ServiceFactory::websiteService();
$userId = App::auth()->id();
$ip = Request::ip();
$updated = $action === 'pause' ? $service->pause($website, $userId, $ip) : $service->resume($website, $userId, $ip);
$updated['uptime_30d'] = ServiceFactory::uptime()->forWebsite($id)['uptime_30d'];
$updated['open_incidents'] = ServiceFactory::incidents()->openFor($id) !== null ? 1 : 0;

Response::success($action === 'pause' ? 'Monitoring paused.' : 'Monitoring resumed.', [
    'website' => $service->present($updated),
]);
