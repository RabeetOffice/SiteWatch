<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\WebsiteRepository;
use App\Services\ActivityService;
use App\Services\ServiceFactory;

Api::boot(['POST']);

$ids = array_values(array_unique(array_filter(array_map('intval', Request::array('ids')), static fn (int $id): bool => $id > 0)));
$action = Request::string('action');
if ($ids === []) {
    Response::error('Select at least one website.', ['ids' => 'No websites selected.'], 422);
}
if (count($ids) > 500) {
    Response::error('Too many websites selected (maximum 500).', [], 422);
}
if (!in_array($action, ['check', 'pause', 'resume', 'interval', 'delete'], true)) {
    Response::error('Invalid bulk action.', ['action' => 'Unknown action.'], 422);
}
// Live checks keep this request open until every website answers; the page sends them in small batches.
if ($action === 'check' && count($ids) > 10) {
    Response::error('Check at most 10 websites per request.', ['ids' => 'Too many websites for one check request.'], 422);
}
Api::authorize(match ($action) {
    'check'  => 'websites.check',
    'delete' => 'websites.delete',
    default  => 'websites.manage',
});
// Outbound requests can take many seconds: unlock the session so the user's other requests are not queued behind this one.
App::session()->release();

$websites = ServiceFactory::websites();
$service = ServiceFactory::websiteService();
$rows = $websites->findMany($ids);
if ($rows === []) {
    Response::error('None of the selected websites exist.', [], 404);
}
$userId = App::auth()->id();
$ip = Request::ip();
$count = count($rows);

switch ($action) {
    case 'pause':
        foreach ($rows as $row) {
            $service->pause($row, $userId, $ip);
        }
        $message = "Monitoring paused for {$count} website" . ($count === 1 ? '' : 's') . '.';
        break;

    case 'resume':
        foreach ($rows as $row) {
            $service->resume($row, $userId, $ip);
        }
        $message = "Monitoring resumed for {$count} website" . ($count === 1 ? '' : 's') . '.';
        break;

    case 'interval':
        $interval = Request::int('interval');
        if (!in_array($interval, WebsiteRepository::INTERVALS, true)) {
            Response::error('Invalid monitoring interval.', ['interval' => 'Choose one of the supported intervals.'], 422);
        }
        foreach ($rows as $row) {
            $service->setInterval($row, $interval, $userId, $ip);
        }
        $message = "Monitoring interval set to {$interval} minute" . ($interval === 1 ? '' : 's') . " for {$count} website" . ($count === 1 ? '' : 's') . '.';
        break;

    case 'delete':
        foreach ($rows as $row) {
            $service->delete($row, $userId, $ip);
        }
        $message = "{$count} website" . ($count === 1 ? '' : 's') . ' deleted.';
        break;

    case 'check':
    default:
        // Run real checks now, concurrently, through the full state machine.
        set_time_limit(max(120, App::settings()->getInt('request_timeout', 30) * 3));
        $summary = ServiceFactory::monitor()->checkMany($rows, 'manual');
        $checked = $summary['checked'];
        $uptime = ServiceFactory::uptime();
        $incidents = ServiceFactory::incidents();
        $extra = [
            'checked'            => $checked,
            'failing'            => $summary['failing'],
            'incidents_opened'   => $summary['incidents_opened'],
            'incidents_resolved' => $summary['incidents_resolved'],
            'websites'           => [],
        ];
        foreach ($websites->findMany(array_map(static fn (array $r): int => (int) $r['id'], $rows)) as $row) {
            $row['uptime_30d'] = $uptime->forWebsite((int) $row['id'])['uptime_30d'];
            $row['open_incidents'] = $incidents->openFor((int) $row['id']) !== null ? 1 : 0;
            $extra['websites'][] = $service->present($row);
        }
        $message = "Checked {$checked} website" . ($checked === 1 ? '' : 's') . ": {$summary['failing']} failing"
            . ($summary['incidents_opened'] ? ", {$summary['incidents_opened']} incident(s) opened" : '')
            . ($summary['incidents_resolved'] ? ", {$summary['incidents_resolved']} recovered" : '') . '.';
        break;
}

ActivityService::log('bulk.action', sprintf('Bulk action "%s" on %d website(s)', $action, $count), null, ['action' => $action, 'ids' => $ids]);

Response::success($message, array_merge(['count' => $count, 'action' => $action], $extra ?? []));
