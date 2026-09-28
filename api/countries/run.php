<?php

declare(strict_types=1);

/**
 * Check one website from every configured country now (Performance → Countries and the website page).
 * Takes 5–20 seconds: the tests run on Globalping / check-host.net servers around the world.
 */

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Countries\RateLimitException;
use App\Services\ServiceFactory;

Api::boot(['POST'], permission: 'websites.check');
App::session()->release();
set_time_limit(90);

$service = ServiceFactory::countryChecks();
if (!$service->enabled()) {
    Response::error('Country checks are switched off. Turn them on in Settings › Monitoring.', [], 422);
}
$id = Request::int('website_id');
$website = $id > 0 ? ServiceFactory::websites()->find($id) : null;
if ($website === null) {
    Response::error('Website not found.', [], 404);
}

try {
    $result = $service->run($website);
} catch (RateLimitException $e) {
    $minutes = $e->retryAfter !== null ? max(1, (int) ceil($e->retryAfter / 60)) : 60;
    Response::error('The free hourly allowance of test servers is used up. Try again in about ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ', or add a free Globalping token in Settings › Monitoring to double it.', ['retry_after' => $e->retryAfter], 429);
} catch (Throwable $e) {
    App::logger('countries')->warning('Country check failed', ['website_id' => $id, 'error' => $e->getMessage()]);
    Response::error('The country check could not run: ' . $e->getMessage(), [], 502);
}

Response::success('Checked from ' . count($result['countries']) . ' countries.', $result);
