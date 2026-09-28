<?php

declare(strict_types=1);

/**
 * Overview tab of a website: health banner and score, key figures, 90 days of uptime, recent events.
 */

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\Request;
use App\Core\Response;
use App\Services\ServiceFactory;
use App\Services\WebsiteOverviewService;

Api::boot(['GET']);

$id = Request::int('id');
$website = $id > 0 ? ServiceFactory::websites()->find($id) : null;
if ($website === null) {
    Response::error('Website not found.', [], 404);
}

Response::success('', (new WebsiteOverviewService())->build($website, [
    'incidents' => can('incidents.view'),
    'reports'   => can('reports.view'),
    'domains'   => can('domains.view'),
]));
