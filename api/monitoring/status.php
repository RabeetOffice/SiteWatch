<?php

declare(strict_types=1);

define('SW_API', true);
define('SW_ALLOW_PENDING_SCHEMA', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\Response;
use App\Services\ServiceFactory;

Api::boot(['GET']);

$engine = ServiceFactory::scheduler()->engineStatus();
$heartbeats = ServiceFactory::heartbeats();

Response::success('', [
    'engine'         => $engine,
    'open_incidents' => ServiceFactory::incidents()->countOpen(),
    'recent_runs'    => array_map(static fn (array $r): array => [
        'started_at'       => $r['started_at'],
        'started_label'    => format_datetime($r['started_at']),
        'status'           => $r['status'],
        'websites_checked' => (int) $r['websites_checked'],
        'failures'         => (int) $r['failures'],
        'duration_ms'      => $r['duration_ms'] !== null ? (int) $r['duration_ms'] : null,
        'message'          => $r['message'],
    ], $heartbeats->recent('monitor', 10)),
    'server_time'    => utc_now()->format('Y-m-d H:i:s'),
]);
