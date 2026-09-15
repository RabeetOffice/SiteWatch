<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Services\ServiceFactory;

Api::boot(['POST'], permission: 'websites.manage');

$service = ServiceFactory::websiteService();
$validated = $service->validate(Request::all());
if ($validated['errors'] !== []) {
    Response::error('Please correct the highlighted fields.', $validated['errors'], 422);
}

$website = $service->create($validated['data'], App::auth()->id(), Request::ip());

$firstCheck = null;
if (Request::bool('check_now', false) && (int) $website['monitoring_enabled'] === 1) {
    set_time_limit(max(60, App::settings()->getInt('request_timeout', 30) + 30));
    try {
        $outcome = ServiceFactory::monitor()->checkNow($website, 'manual');
        $website = $outcome['website'];
        $firstCheck = $outcome['result']->toArray();
    } catch (Throwable $e) {
        App::logger('monitor')->error('Initial check failed', ['id' => $website['id'], 'error' => $e->getMessage()]);
    }
}

$row = ServiceFactory::websites()->find((int) $website['id']) ?? $website;

Response::success('Website added successfully.', [
    'website'     => $service->present($row),
    'first_check' => $firstCheck,
    'warnings'    => $validated['warnings'],
    'redirect'    => base_url('admin/website-details.php?id=' . (int) $website['id']),
], 201);
