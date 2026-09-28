<?php

declare(strict_types=1);

/**
 * A brand kit's logo, for signed-in users (the brand editor and report previews). Share links serve the logo
 * through report.php instead.
 */

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Services\BrandLogoService;
use App\Services\ServiceFactory;

Api::boot(['GET'], permission: 'reports.view');
App::session()->release();

$brand = ServiceFactory::clientReports()->brands()->find(Request::int('id'));
$logos = BrandLogoService::create();
$path = $logos->resolve($brand['logo'] ?? null);
if ($brand === null || $path === null) {
    Response::error('No logo.', [], 404);
}
$logos->serve($path, (string) $brand['logo'], false);
