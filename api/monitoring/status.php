<?php

declare(strict_types=1);

define('SW_API', true);
define('SW_ALLOW_PENDING_SCHEMA', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\Request;
use App\Core\Response;
use App\Services\ServiceFactory;

Api::boot(['GET']);

$engine = ServiceFactory::scheduler()->engineStatus();

$since = Request::string('since');
$changes = [];
if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since) && can('incidents.view')) {
    foreach (ServiceFactory::incidents()->changesSince($since) as $i) {
        $resolved = $i['resolved_at'] !== null && $i['resolved_at'] > $since;
        $changes[] = [
            'id'      => (int) $i['id'],
            'kind'    => $resolved ? 'recovery' : 'down',
            'title'   => $resolved ? $i['website_name'] . ' is back online' : $i['website_name'] . ' is down',
            'body'    => (string) $i['title'],
            'url'     => base_url('admin/website-details.php?id=' . (int) $i['website_id']),
        ];
    }
}
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
    // Changes since the caller's last poll, for desktop notifications while SiteWatch is open.
    'changes'        => $changes,
]);
