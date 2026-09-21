<?php

declare(strict_types=1);

/**
 * Run PageSpeed Insights for one website right now, or capture its screenshot right now.
 *
 * Both are slow external calls, so this is a deliberate per-website action rather than something the
 * interface triggers on its own.
 */

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityService;
use App\Services\ServiceFactory;

Api::boot(['POST'], permission: 'websites.check');

set_time_limit(180);

$id = Request::int('website_id');
$action = Request::string('action') === 'screenshot' ? 'screenshot' : 'vitals';

$website = $id > 0 ? ServiceFactory::websites()->find($id) : null;
if ($website === null) {
    Response::error('Website not found.', [], 404);
}

$performance = ServiceFactory::performance();

if ($action === 'screenshot') {
    if (!$performance->screenshotsEnabled()) {
        Response::error('Screenshots are switched off. Enable them under Monitoring Settings.', [], 422);
    }
    try {
        $performance->captureScreenshot($website);
    } catch (Throwable $e) {
        App::logger('monitor')->warning('Manual screenshot failed', ['website_id' => $id, 'error' => $e->getMessage()]);
        Response::error($e->getMessage(), [], 502);
    }
    $latest = ServiceFactory::screenshots()->latest($id);
    ActivityService::log('screenshot.captured', sprintf('Screenshot captured for %s', $website['name']), $id);
    Response::success('Screenshot captured.', [
        'screenshot' => $latest === null ? null : [
            'id'            => (int) $latest['id'],
            'provider'      => (string) $latest['provider'],
            'captured_at'   => (string) $latest['captured_at'],
            'captured_label' => format_datetime((string) $latest['captured_at']),
        ],
    ]);
}

if (!$performance->vitalsEnabled()) {
    Response::error('Core Web Vitals are switched off. Enable them under Monitoring Settings.', [], 422);
}

$result = $performance->refreshVitals($website);
if ($result['ok'] === 0) {
    $message = $result['errors'] !== [] ? implode(' · ', $result['errors']) : 'PageSpeed returned no usable result.';
    Response::error($message, [], 502);
}

ActivityService::log('vitals.checked', sprintf('Core Web Vitals measured for %s', $website['name']), $id, null, ['strategies' => $result['ok']]);

Response::success(
    $result['failed'] > 0
        ? sprintf('Measured %d of %d strategies. %s', $result['ok'], $result['ok'] + $result['failed'], implode(' · ', $result['errors']))
        : 'Core Web Vitals updated.',
    ['summary' => $performance->forWebsite($id)]
);
