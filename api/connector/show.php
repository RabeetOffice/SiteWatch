<?php

declare(strict_types=1);

/**
 * SiteWatch Connector status, health snapshot, errors and activity for one website.
 */

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Services\ConnectorService;
use App\Services\ServiceFactory;

Api::boot(['GET']);
App::session()->release();

$website = ServiceFactory::websites()->find(Request::int('website_id'));
if ($website === null) {
    Response::error('Website not found.', [], 404);
}

Response::success('', ['connector' => ConnectorService::create()->details((int) $website['id'])]);
