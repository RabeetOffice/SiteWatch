<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Monitoring\Status;
use App\Services\ServiceFactory;

Api::boot(['GET'], permission: 'reports.view');

$window = Request::string('window', '24h') === '7d' ? '7d' : '24h';
$from = utc_now()->modify($window === '7d' ? '-7 days' : '-24 hours')->format('Y-m-d H:i:s');

$checks = ServiceFactory::checks();
$stats = $checks->perWebsiteStats($from);
$slowThreshold = App::settings()->getInt('slow_threshold', 5000);

$rows = [];
$sum = 0.0;
$count = 0;
$fastest = null;
$slowest = null;
$over = 0;
foreach (ServiceFactory::websites()->all() as $w) {
    $s = $stats[(int) $w['id']] ?? null;
    $avg = $s !== null && $s['avg_rt'] !== null ? (int) round((float) $s['avg_rt']) : null;
    $status = (int) $w['monitoring_enabled'] === 1 ? (string) $w['status'] : Status::PAUSED;

    // Sparkline: hourly (24h) or 6-hourly (7d) buckets.
    $trend = array_map(static fn (array $p) => $p['avg'], $checks->responseSeries((int) $w['id'], $from, $window === '7d' ? 21600 : 3600));

    $rows[] = [
        'id'           => (int) $w['id'],
        'name'         => $w['name'],
        'domain'       => $w['domain'],
        'client_name'  => $w['client_name'],
        'favicon_url'  => $w['favicon_url'],
        'status'       => $status,
        'status_label' => Status::label($status),
        'severity'     => Status::severity($status),
        'current'      => $w['last_response_time'] !== null ? (int) $w['last_response_time'] : null,
        'avg'          => $avg,
        'min'          => $s !== null && $s['min_rt'] !== null ? (int) $s['min_rt'] : null,
        'max'          => $s !== null && $s['max_rt'] !== null ? (int) $s['max_rt'] : null,
        'checks'       => $s !== null ? (int) $s['checks'] : 0,
        'down'         => $s !== null ? (int) $s['down'] : 0,
        'trend'        => $trend,
        'urls'         => ['details' => base_url('admin/website-details.php?id=' . (int) $w['id'])],
    ];
    if ($avg !== null) {
        $sum += $avg;
        $count++;
        if ($fastest === null || $avg < $fastest['avg']) {
            $fastest = ['name' => $w['name'], 'avg' => $avg];
        }
        if ($slowest === null || $avg > $slowest['avg']) {
            $slowest = ['name' => $w['name'], 'avg' => $avg];
        }
        if ($avg >= $slowThreshold) {
            $over++;
        }
    }
}

usort($rows, static function (array $a, array $b): int {
    if ($a['avg'] === null && $b['avg'] === null) {
        return strcmp($a['name'], $b['name']);
    }
    if ($a['avg'] === null) {
        return 1;
    }
    if ($b['avg'] === null) {
        return -1;
    }
    return $b['avg'] <=> $a['avg'];
});

Response::success('', [
    'window'  => $window,
    'rows'    => $rows,
    'summary' => [
        'avg'            => $count > 0 ? (int) round($sum / $count) : null,
        'fastest'        => $fastest,
        'slowest'        => $slowest,
        'over_threshold' => $over,
        'websites'       => count($rows),
        'slow_threshold' => $slowThreshold,
    ],
]);
