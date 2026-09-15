<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Services\ServiceFactory;

/*
 * Fill in missing or outdated domain details for a website (anyone who can view domain information), or
 * force a fresh lookup (requires domains.lookup).
 */
Api::boot(['POST'], permission: 'domains.view');

$website = ServiceFactory::websites()->find(Request::int('website_id'));
if ($website === null) {
    Response::error('Website not found.', [], 404);
}

$service = ServiceFactory::domainService();
$force = Request::bool('force');
if ($force) {
    Api::authorize('domains.lookup');
} else {
    $current = $service->forWebsite($website);
    if ($current !== null && !$current['stale']) {
        $current['registration']['raw'] = null;
        Response::success('Domain details are up to date.', ['domain' => $current, 'refreshed' => false]);
    }
}

// Registry and DNS lookups can take several seconds; do not hold the session lock meanwhile.
App::session()->release();
set_time_limit(120);

$domain = $service->refresh($website);
$domain['registration']['raw'] = null;

Response::success('Domain and hosting details refreshed.', ['domain' => $domain, 'refreshed' => true]);
