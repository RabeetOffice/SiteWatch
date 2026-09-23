<?php

declare(strict_types=1);

/**
 * Queue a remote action for a WordPress site (SiteWatch Connector 1.4.0+).
 *
 *   POST website_id, action (clear_cache | deactivate_plugin | activate_plugin | update_plugins | maintenance), args {…}
 *
 * The site runs it after its next report if its WordPress administrator allowed that action. Needs the
 * "Run remote actions" permission (websites.remote).
 */

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityService;
use App\Services\ConnectorService;
use App\Services\RemoteActionService;
use App\Services\ServiceFactory;

Api::boot(['POST'], permission: 'websites.remote');
App::session()->release();

$website = ServiceFactory::websites()->find(Request::int('website_id'));
if ($website === null) {
    Response::error('Website not found.', [], 404);
}
$id = (int) $website['id'];
$action = Request::string('action');

try {
    $rawArgs = Request::input('args');
    $command = RemoteActionService::create()->request($id, $action, is_array($rawArgs) ? $rawArgs : [], App::auth()->id());
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), [], 422);
}

$args = json_decode((string) $command['args'], true) ?: [];
$what = match ($action) {
    'deactivate_plugin', 'activate_plugin' => RemoteActionService::ACTIONS[$action] . ' ' . ($args['plugin'] ?? ''),
    'update_plugins' => 'Update ' . count($args['plugins'] ?? []) . ' plugin(s)',
    'update_themes'  => 'Update ' . count($args['themes'] ?? []) . ' theme(s)',
    'update_core'    => 'Update WordPress to ' . ($args['version'] ?? ''),
    'rollback_plugin' => 'Roll back ' . ($args['plugin'] ?? '') . ' to ' . ($args['version'] ?? ''),
    'maintenance'    => ($args['mode'] ?? '') === 'on' ? 'Maintenance page on for ' . (int) ($args['minutes'] ?? 0) . ' min' : 'Maintenance page off',
    default          => RemoteActionService::ACTIONS[$action] ?? $action,
};
ActivityService::log('connector.remote_action', sprintf('Remote action requested on %s: %s (#%d)', $website['name'], $what, (int) $command['id']), $id, ['command_id' => (int) $command['id'], 'action' => $action]);

Response::success(
    'Requested. The site runs it after its next report (usually within 5 minutes).',
    ['connector' => ConnectorService::create()->details($id)]
);
