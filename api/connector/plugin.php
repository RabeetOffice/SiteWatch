<?php

declare(strict_types=1);

/**
 * Download the SiteWatch Connector WordPress plugin as a zip ready for Plugins → Add New → Upload Plugin.
 */

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Response;
use App\Services\ConnectorService;

Api::boot(['GET']);
App::session()->release();

$service = ConnectorService::create();
try {
    $path = $service->buildZip();
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
