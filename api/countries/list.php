<?php

declare(strict_types=1);

/**
 * Latest country availability of every website (Performance → Countries), or of one (website_id).
 */

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\Request;
use App\Core\Response;
use App\Services\ServiceFactory;

Api::boot(['GET']);

$service = ServiceFactory::countryChecks();
$id = Request::int('website_id');
if ($id > 0) {
    Response::success('', ['enabled' => $service->enabled(), 'countries' => $service->presentWebsite($id)]);
}
if (!can('reports.view')) {
    Response::error('You do not have permission to view this.', [], 403);
}
Response::success('', $service->pageData(can('websites.check'), can('settings.manage')));
