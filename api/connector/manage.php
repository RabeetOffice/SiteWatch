<?php

declare(strict_types=1);

/**
 * SiteWatch Connector management for one website.
 *
 *   POST action=create   create (or replace) the connection key; returns it
 *   POST action=show     return the current key again
 *   POST action=refresh  ask the plugin for a fresh health snapshot on its next report
 *   POST action=update   ask the plugin to install the newest version on its next report
 *   POST action=revoke   delete the key; the plugin can no longer report (stored reports are kept)
 */

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityService;
use App\Services\ConnectorService;
use App\Services\ServiceFactory;

Api::boot(['POST'], permission: 'websites.manage');
App::session()->release();

$website = ServiceFactory::websites()->find(Request::int('website_id'));
if ($website === null) {
    Response::error('Website not found.', [], 404);
}
$id = (int) $website['id'];
$service = ConnectorService::create();

switch (Request::string('action')) {
    case 'create':
        try {
            $key = $service->createKey($website);
        } catch (RuntimeException $e) {
            Response::error($e->getMessage(), [], 500);
        }
        ActivityService::log('connector.key_created', sprintf('Connection key created for %s', $website['name']), $id);
        Response::success('Connection key created. Paste it in WordPress under Settings → SiteWatch.', ['key' => $key, 'connector' => $service->details($id)]);

    case 'show':
        $key = $service->currentKey($id);
        if ($key === null) {
            Response::error('No connection key exists for this website yet.', [], 404);
        }
        Response::success('', ['key' => $key]);

    case 'refresh':
        $service->requestSnapshot($id);
        Response::success('A fresh health report was requested. It arrives with the plugin\'s next report (within about 5 minutes).', ['connector' => $service->details($id)]);

    case 'update':
        $service->requestUpdate($id);
        ActivityService::log('connector.update_requested', sprintf('Plugin update requested for %s', $website['name']), $id);
        Response::success('Update requested. The plugin installs it after its next report (within about 5 minutes).', ['connector' => $service->details($id)]);

    case 'revoke':
        $service->revoke($id);
        ActivityService::log('connector.revoked', sprintf('Connection key revoked for %s', $website['name']), $id);
        Response::success('Connection key revoked. The plugin on this site can no longer report.', ['connector' => $service->details($id)]);

    default:
        Response::error('Unknown action.', ['action' => 'Use create, show, refresh, update or revoke.'], 422);
}
