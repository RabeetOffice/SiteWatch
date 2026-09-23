<?php

declare(strict_types=1);

/**
 * Queue a remote action on many WordPress sites at once (bulk bar of the website list).
 *
 *   POST ids[], action (clear_cache | update_plugins | update_themes | maintenance | backup), args {…}
 *
 * Each site is queued separately with the same checks as a single request; sites that cannot take the action are
 * skipped and listed with the reason. Needs the "Run remote actions" permission (websites.remote).
 */

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityService;
use App\Services\RemoteActionService;
use App\Services\ServiceFactory;

Api::boot(['POST'], permission: 'websites.remote');
App::session()->release();

$ids = array_values(array_unique(array_filter(array_map('intval', Request::array('ids')), static fn (int $id): bool => $id > 0)));
if ($ids === []) {
    Response::error('Select at least one website.', ['ids' => 'No websites selected.'], 422);
}
if (count($ids) > 500) {
    Response::error('Too many websites selected (maximum 500).', [], 422);
}
$action = Request::string('action');
$rawArgs = Request::input('args');
$args = is_array($rawArgs) ? $rawArgs : [];

$rows = ServiceFactory::websites()->findMany($ids);
if ($rows === []) {
    Response::error('None of the selected websites exist.', [], 404);
}

try {
    $result = RemoteActionService::create()->bulk($rows, $action, $args, App::auth()->id());
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), [], 422);
}

$label = match ($action) {
    'update_plugins' => 'Update all plugins',
    'update_themes'  => 'Update all themes',
    'backup'         => 'Backup (UpdraftPlus or BackWPup)',
    'maintenance'    => ($args['mode'] ?? '') === 'off' ? 'Maintenance page off' : 'Maintenance page on for ' . (int) ($args['minutes'] ?? 0) . ' min',
    default          => RemoteActionService::ACTIONS[$action] ?? $action,
};
foreach ($result['queued'] as $site) {
    ActivityService::log('connector.remote_action', sprintf('Remote action requested on %s: %s (#%d, bulk)', $site['name'], $label, $site['command_id']), $site['website_id'], ['command_id' => $site['command_id'], 'action' => $action, 'bulk' => true]);
}

$queued = count($result['queued']);
$skipped = count($result['skipped']);
$message = $queued > 0
    ? sprintf('%s requested on %d site%s; each runs it after its next report.', $label, $queued, $queued === 1 ? '' : 's')
    : 'No site could take this action.';
if ($skipped > 0) {
    $message .= sprintf(' %d skipped.', $skipped);
}
Response::success($message, $result);
