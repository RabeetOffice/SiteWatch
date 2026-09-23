<?php

declare(strict_types=1);

/**
 * Plugin zip for self-updates. WordPress downloads it with a plain GET (no cookies or custom headers), so access is
 * granted by a signature in the link that only this site's secret can produce (see ConnectorService::packageUrl).
 */

define('SW_API', true);
define('SW_STATELESS', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Services\ConnectorException;
use App\Services\ConnectorService;

if (Request::method() !== 'GET') {
    header('Allow: GET');
    Response::error('Method not allowed.', [], 405);
}

$service = ConnectorService::create();
$websiteId = Request::int('site');
try {
    $service->throttle($websiteId);
    $service->verifyPackage($websiteId, Request::string('v'), Request::int('expires'), strtolower(Request::string('sig')));
    $path = $service->buildZip();
} catch (ConnectorException $e) {
    App::logger('connector')->notice('Plugin download refused', ['website_id' => $websiteId, 'ip' => Request::ip(), 'reason' => $e->getMessage()]);
    Response::error($e->getMessage(), [], $e->getCode() >= 400 ? $e->getCode() : 403);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), [], 500);
}

$version = $service->bundledPluginVersion() ?? 'latest';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="sitewatch-connector-' . preg_replace('/[^0-9A-Za-z.\-]/', '', $version) . '.zip"');
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($path);
@unlink($path);
exit;
