<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\Request;
use App\Core\Response;
use App\Monitoring\UptimeCalculator;
use App\Services\ServiceFactory;

Api::boot(['GET']);

$id = Request::int('id');
if ($id <= 0 || ServiceFactory::websites()->find($id) === null) {
    Response::error('Website not found.', [], 404);
}

$range = Request::string('range', '24h');
if (!in_array($range, ['24h', '7d', '30d', '90d'], true)) {
    $range = '24h';
}

$labels = [];
$timestamps = [];
$values = [];
$max = [];
$down = [];
$tz = app_timezone();

if ($range === '24h' || $range === '7d') {
    // Raw checks, bucketed (15 min for 24h, 1 hour for 7d). Older data than the retention window is simply absent.
    $bucket = $range === '24h' ? 900 : 3600;
    $from = utc_now()->modify($range === '24h' ? '-24 hours' : '-7 days')->format('Y-m-d H:i:s');
    $series = ServiceFactory::checks()->responseSeries($id, $from, $bucket);
    foreach ($series as $point) {
        $local = (new DateTimeImmutable('@' . $point['t']))->setTimezone($tz);
        $labels[] = $range === '24h' ? $local->format('H:i') : $local->format('D H:i');
        $timestamps[] = $local->format('j M · g:i A');
        $values[] = $point['avg'];
        $max[] = $point['max'];
        $down[] = $point['down'];
    }
    $summary = ServiceFactory::checks()->windowStats($id, $from);
    $summaryOut = ['avg' => $summary['avg'] !== null ? round($summary['avg']) : null, 'min' => $summary['min'], 'max' => $summary['max'], 'checks' => $summary['total']];
} else {
    // Daily aggregates for long ranges (kept indefinitely).
    $days = $range === '30d' ? 30 : 90;
    $fromDate = UptimeCalculator::localDate(-($days - 1));
    $toDate = UptimeCalculator::localDate();
    $rows = [];
    foreach (ServiceFactory::dailyStats()->daily($id, $fromDate, $toDate) as $row) {
        $rows[(string) $row['stat_date']] = $row;
    }
    $cursor = new DateTimeImmutable($fromDate, $tz);
    for ($i = 0; $i < $days; $i++) {
        $day = $cursor->modify("+{$i} days");
        $key = $day->format('Y-m-d');
        $labels[] = $day->format('j M');
        $timestamps[] = $day->format('D j M Y');
        $values[] = isset($rows[$key]) && $rows[$key]['average_response_time'] !== null ? (int) $rows[$key]['average_response_time'] : null;
        $max[] = isset($rows[$key]) && $rows[$key]['max_response_time'] !== null ? (int) $rows[$key]['max_response_time'] : null;
        $down[] = isset($rows[$key]) ? (int) $rows[$key]['failed_checks'] : 0;
    }
    $agg = ServiceFactory::dailyStats()->rangeStats($id, $fromDate, $toDate);
    $summaryOut = ['avg' => $agg['avg'], 'min' => $agg['min'], 'max' => $agg['max'], 'checks' => $agg['total']];
}

Response::success('', [
    'range'      => $range,
    'labels'     => $labels,
    'timestamps' => $timestamps,
    'values'     => $values,
    'max'        => $max,
    'down'       => $down,
    'summary'    => $summaryOut,
]);
