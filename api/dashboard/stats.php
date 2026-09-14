<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\Response;
use App\Services\ServiceFactory;

Api::boot(['GET']);

$dashboard = ServiceFactory::dashboard();

Response::success('', [
    'stats'     => $dashboard->stats(),
    'incidents' => $dashboard->recentIncidents(6),
    'activity'  => $dashboard->recentActivity(8),
]);
