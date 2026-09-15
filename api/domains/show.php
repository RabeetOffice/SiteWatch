<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\Request;
use App\Core\Response;
use App\Services\ServiceFactory;

Api::boot(['GET'], permission: 'domains.view');

$website = ServiceFactory::websites()->find(Request::int('website_id'));
if ($website === null) {
    Response::error('Website not found.', [], 404);
}

$service = ServiceFactory::domainService();
$domain = $service->forWebsite($website);
if ($domain !== null && !Request::bool('raw')) {
    // The raw registry record can be large; it is only sent when asked for.
    $domain['registration']['raw'] = null;
}

Response::success('', ['domain' => $domain, 'max_age_hours' => $service->maxAgeHours()]);
