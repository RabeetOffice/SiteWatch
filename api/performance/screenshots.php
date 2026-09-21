<?php

declare(strict_types=1);

/**
 * Screenshot metadata for one website (latest plus recent history), or the latest for every website.
 * The images themselves are served by api/websites/screenshot.php.
 */

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\Request;
use App\Core\Response;
use App\Services\ServiceFactory;

Api::boot(['GET']);

$websiteId = Request::int('website_id');
$screenshots = ServiceFactory::screenshots();
$performance = ServiceFactory::performance();

$present = static fn (?array $row): ?array => $row === null ? null : [
    'id'             => (int) $row['id'],
    'website_id'     => (int) $row['website_id'],
    'provider'       => (string) $row['provider'],
    'bytes'          => $row['bytes'] === null ? null : (int) $row['bytes'],
    'captured_at'    => (string) $row['captured_at'],
    'captured_label' => format_datetime((string) $row['captured_at']),
];

if ($websiteId > 0) {
    if (ServiceFactory::websites()->find($websiteId) === null) {
        Response::error('Website not found.', [], 404);
    }
    $attempt = $screenshots->lastAttempt($websiteId);

    Response::success('', [
        'enabled'    => $performance->screenshotsEnabled(),
        'provider'   => $performance->screenshotProvider(),
        'interval'   => $performance->screenshotIntervalMinutes(),
        'latest'     => $present($screenshots->latest($websiteId)),
        'history'    => array_values(array_filter(array_map($present, $screenshots->historyFor($websiteId, 12)))),
        'last_error' => $attempt !== null && $attempt['status'] === 'failed' ? (string) $attempt['error_message'] : null,
    ]);
}

$latest = $screenshots->latestPerWebsite();
$rows = [];
foreach (ServiceFactory::websites()->all() as $website) {
    $id = (int) $website['id'];
    $rows[] = [
        'id'         => $id,
        'name'       => (string) $website['name'],
        'url'        => (string) $website['url'],
        'status'     => (string) $website['status'],
        'screenshot' => $present($latest[$id] ?? null),
    ];
}

Response::success('', [
    'enabled'  => $performance->screenshotsEnabled(),
    'provider' => $performance->screenshotProvider(),
    'interval' => $performance->screenshotIntervalMinutes(),
    'rows'     => $rows,
]);
