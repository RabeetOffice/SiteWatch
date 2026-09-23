<?php

declare(strict_types=1);

/**
 * Serves a stored screenshot image.
 *
 * Screenshots live under storage/, which the web server denies outright, so this is the only way to read
 * one — and it requires a signed-in session, like every other website endpoint. The response is an image,
 * not JSON, so it deliberately does not go through Response.
 */

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Services\ServiceFactory;

Api::boot(['GET']);
// Outbound requests can take many seconds: unlock the session so the user's other requests are not queued behind this one.
App::session()->release();

$id = Request::int('id');
$websiteId = Request::int('website_id');

$screenshots = ServiceFactory::screenshots();
$row = $id > 0 ? $screenshots->find($id) : ($websiteId > 0 ? $screenshots->latest($websiteId) : null);

if ($row === null || ($row['status'] ?? '') !== 'ok' || empty($row['path'])) {
    Response::error('No screenshot is available for this website yet.', [], 404);
}
// When the caller asked by website, make sure the row really belongs to it.
if ($websiteId > 0 && (int) $row['website_id'] !== $websiteId) {
    Response::error('No screenshot is available for this website yet.', [], 404);
}

$path = ServiceFactory::performance()->capturer()->resolve((string) $row['path']);
if ($path === null) {
    Response::error('The screenshot file is no longer on disk.', [], 404);
}

$mime = (string) ($row['mime'] ?? 'image/jpeg');
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    $mime = 'image/jpeg';
}

$etag = '"' . sha1((string) $row['id'] . '|' . (string) $row['captured_at']) . '"';
$modified = strtotime((string) $row['captured_at'] . ' UTC') ?: time();

// The image is per-account data, so it must never be held by a shared cache.
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="screenshot-' . (int) $row['website_id'] . '.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modified) . ' GMT');

$ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($ifNoneMatch !== '' && str_contains($ifNoneMatch, $etag)) {
    http_response_code(304);
    exit;
}

header('Content-Length: ' . (string) filesize($path));
if (Request::method() === 'HEAD') {
    exit;
}
readfile($path);
