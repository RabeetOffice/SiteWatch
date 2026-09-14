<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\Request;
use App\Core\Response;
use App\Monitoring\Status;
use App\Monitoring\UptimeCalculator;
use App\Services\ServiceFactory;

Api::boot(['GET']);

$id = Request::int('id');
if ($id <= 0 || ServiceFactory::websites()->find($id) === null) {
    Response::error('Website not found.', [], 404);
}

$limit = max(20, min(240, Request::int('limit', 120)));
$checks = array_map(static fn (array $c): array => [
    'checked_at'    => $c['checked_at'],
    'status'        => $c['status'],
    'status_label'  => Status::label($c['status']),
    'severity'      => Status::severity($c['status']),
    'is_up'         => (int) $c['is_up'] === 1,
    'http_status'   => $c['http_status'] !== null ? (int) $c['http_status'] : null,
    'response_time' => $c['response_time'] !== null ? (int) $c['response_time'] : null,
], ServiceFactory::checks()->timeline($id, $limit));

$tz = app_timezone();
$fromDate = UptimeCalculator::localDate(-29);
$toDate = UptimeCalculator::localDate();
$rows = [];
foreach (ServiceFactory::dailyStats()->daily($id, $fromDate, $toDate) as $row) {
    $rows[(string) $row['stat_date']] = $row;
}
$days = [];
$cursor = new DateTimeImmutable($fromDate, $tz);
for ($i = 0; $i < 30; $i++) {
    $day = $cursor->modify("+{$i} days");
    $key = $day->format('Y-m-d');
    $r = $rows[$key] ?? null;
    $days[] = [
        'date'      => $key,
        'label'     => $day->format('D j M'),
        'total'     => $r ? (int) $r['total_checks'] : 0,
        'failed'    => $r ? (int) $r['failed_checks'] : 0,
        'uptime'    => $r && $r['uptime_percentage'] !== null ? (float) $r['uptime_percentage'] : null,
        'incidents' => $r ? (int) $r['incident_count'] : 0,
        'avg'       => $r && $r['average_response_time'] !== null ? (int) $r['average_response_time'] : null,
    ];
}

Response::success('', ['checks' => $checks, 'days' => $days]);
