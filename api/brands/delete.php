<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityService;
use App\Services\BrandLogoService;
use App\Services\ServiceFactory;

Api::boot(['POST'], permission: 'reports.share');

$brands = ServiceFactory::clientReports()->brands();
$brand = $brands->find(Request::int('id'));
if ($brand === null) {
    Response::error('Brand not found.', [], 404);
}

// Reports that used this brand fall back to the client's brand, or the neutral default.
$brands->delete((int) $brand['id']);
BrandLogoService::create()->delete($brand['logo'] ?? null);
ActivityService::log('report.brand', sprintf('Deleted the report brand "%s"', $brand['name']));

Response::success('Brand deleted.', ['id' => (int) $brand['id']]);
