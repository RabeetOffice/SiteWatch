<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Response;
use App\Services\ClientReportService;
use App\Services\ServiceFactory;

Api::boot(['GET'], permission: 'reports.view');
App::session()->release();

$service = ServiceFactory::clientReports();

Response::success('', [
    'reports' => array_map([$service, 'presentReport'], $service->reports()->all()),
    'brands'  => array_map([ClientReportService::class, 'presentBrand'], $service->brands()->all()),
]);
