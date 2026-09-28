<?php

declare(strict_types=1);

/**
 * Actions on a saved client report: enable / disable the share link, give it a new address, delete it.
 */

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityService;
use App\Services\ServiceFactory;

Api::boot(['POST'], permission: 'reports.share');

$service = ServiceFactory::clientReports();
$repo = $service->reports();
$report = $repo->find(Request::int('id'));
if ($report === null) {
    Response::error('Report not found.', [], 404);
}
$id = (int) $report['id'];
$title = (string) $report['title'];

switch (Request::string('action')) {
    case 'enable':
    case 'disable':
        $on = Request::string('action') === 'enable';
        $repo->update($id, ['is_active' => $on ? 1 : 0]);
        ActivityService::log('report.updated', sprintf('%s the share link of the client report "%s"', $on ? 'Switched on' : 'Switched off', $title));
        Response::success($on ? 'The share link works again.' : 'The share link is switched off. People who open it see "not available".', ['report' => $service->presentReport((array) $repo->find($id))]);

    case 'regenerate':
        $repo->regenerateToken($id);
        ActivityService::log('report.updated', sprintf('Gave the client report "%s" a new share link', $title));
        Response::success('New link created. The old link no longer works.', ['report' => $service->presentReport((array) $repo->find($id))]);

    case 'delete':
        $repo->delete($id);
        ActivityService::log('report.deleted', sprintf('Deleted the client report "%s"', $title));
        Response::success('Report deleted. Its share link no longer works.', ['id' => $id]);

    default:
        Response::error('Unknown action.', ['action' => 'Use enable, disable, regenerate or delete.'], 422);
}
