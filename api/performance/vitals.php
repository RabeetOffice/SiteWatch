<?php

declare(strict_types=1);

/**
 * Core Web Vitals for one website (latest mobile + desktop run, plus history for the trend chart),
 * or the fleet overview when no website is given.
 */

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\Request;
use App\Core\Response;
use App\Performance\PageSpeedClient;
use App\Services\PerformanceService;
use App\Services\ServiceFactory;

Api::boot(['GET'], permission: 'reports.view');

$websiteId = Request::int('website_id');
$strategy = in_array(Request::string('strategy'), PageSpeedClient::STRATEGIES, true) ? Request::string('strategy') : 'mobile';
$days = max(1, min(365, Request::int('days') ?: 30));

$performance = ServiceFactory::performance();
$vitals = ServiceFactory::vitals();

if ($websiteId > 0) {
    $website = ServiceFactory::websites()->find($websiteId);
    if ($website === null) {
        Response::error('Website not found.', [], 404);
    }

    Response::success('', [
        'website_id' => $websiteId,
        'strategy'   => $strategy,
        'summary'    => $performance->forWebsite($websiteId),
        'history'    => array_map(static fn (array $row): array => [
            'fetched_at' => $row['fetched_at'],
            'label'      => format_datetime((string) $row['fetched_at'], 'j M · g:i A'),
            'score'      => $row['performance_score'] === null ? null : (int) $row['performance_score'],
            'lcp'        => $row['lab_lcp'] === null ? null : (int) $row['lab_lcp'],
            'cls'        => $row['lab_cls'] === null ? null : (float) $row['lab_cls'],
            'tbt'        => $row['lab_tbt'] === null ? null : (int) $row['lab_tbt'],
            'ttfb'       => $row['lab_ttfb'] === null ? null : (int) $row['lab_ttfb'],
            'inp'        => $row['field_inp'] === null ? null : (int) $row['field_inp'],
        ], $vitals->history($websiteId, $strategy, $days)),
    ]);
}

// ---- Fleet overview -------------------------------------------------------
$latest = $vitals->latestPerWebsite($strategy);
$rows = [];
$scored = [];
$counts = ['good' => 0, 'needs-improvement' => 0, 'poor' => 0, 'unmeasured' => 0];

foreach (ServiceFactory::websites()->all() as $website) {
    $id = (int) $website['id'];
    $run = isset($latest[$id]) ? PerformanceService::presentRun($latest[$id]) : null;

    if ($run === null) {
        $counts['unmeasured']++;
    } else {
        $band = $run['score_rating'] ?? 'unmeasured';
        $counts[$band] = ($counts[$band] ?? 0) + 1;
        if ($run['score'] !== null) {
            $scored[] = $run['score'];
        }
    }

    $rows[] = [
        'id'          => $id,
        'name'        => (string) $website['name'],
        'client_name' => (string) $website['client_name'],
        'url'         => (string) $website['url'],
        'status'      => (string) $website['status'],
        // Measured on every check, so this is current even when PageSpeed has not run recently.
        'last_ttfb'   => $website['last_ttfb'] === null ? null : (int) $website['last_ttfb'],
        'run'         => $run,
    ];
}

usort($rows, static function (array $a, array $b): int {
    // Worst scores first; websites with no measurement yet sink to the bottom.
    $sa = $a['run']['score'] ?? null;
    $sb = $b['run']['score'] ?? null;
    if ($sa === null && $sb === null) {
        return strcasecmp($a['name'], $b['name']);
    }
    if ($sa === null) {
        return 1;
    }
    if ($sb === null) {
        return -1;
    }
    return $sa <=> $sb;
});

Response::success('', [
    'strategy'  => $strategy,
    'rows'      => $rows,
    'counts'    => $counts,
    'average'   => $scored !== [] ? (int) round(array_sum($scored) / count($scored)) : null,
    'measured'  => count($scored),
    'total'     => count($rows),
    'enabled'   => $performance->vitalsEnabled(),
    'interval_hours' => $performance->vitalsIntervalHours(),
]);
