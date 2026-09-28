<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityService;
use App\Services\ServiceFactory;

Api::boot(['POST'], permission: 'reports.share');

$service = ServiceFactory::clientReports();
$id = Request::int('id');
$existing = $id > 0 ? $service->reports()->find($id) : null;
if ($id > 0 && $existing === null) {
    Response::error('Report not found.', [], 404);
}

$validated = $service->validateReport(Request::all());
if ($validated['errors'] !== []) {
    Response::error('Please correct the highlighted fields.', $validated['errors'], 422);
}

if ($existing === null) {
    $id = $service->reports()->create($validated['data'] + ['created_by' => App::auth()->id()]);
    ActivityService::log('report.created', sprintf('Created the client report "%s"', $validated['data']['title']));
} else {
    $service->reports()->update($id, $validated['data']);
    ActivityService::log('report.updated', sprintf('Updated the client report "%s"', $validated['data']['title']));
}

Response::success(
    $existing === null ? 'Report created. Copy the link to share it.' : 'Report saved.',
    ['report' => $service->presentReport((array) $service->reports()->find($id))]
);
