<?php

declare(strict_types=1);

/**
 * Reports from the SiteWatch Connector WordPress plugin.
 *
 * Machine-to-machine: no session, cookie or CSRF token. Each request is authenticated by an HMAC signature made
 * with the website's own secret (see App\Services\ConnectorService for the protocol).
 */

define('SW_API', true);
define('SW_STATELESS', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Services\ConnectorException;
use App\Services\ConnectorService;

if (Request::method() !== 'POST') {
    header('Allow: POST');
    Response::error('Method not allowed.', [], 405);
}

$length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($length > ConnectorService::MAX_BODY) {
    Response::error('Report too large.', [], 413);
}
$body = (string) file_get_contents('php://input', false, null, 0, ConnectorService::MAX_BODY + 1);
if ($body === '' || strlen($body) > ConnectorService::MAX_BODY) {
    Response::error('Empty or oversized report.', [], 400);
}

$service = ConnectorService::create();
$websiteId = (int) ($_SERVER['HTTP_X_SITEWATCH_SITE'] ?? 0);

try {
    $verified = $service->verify(
        $websiteId,
        (string) ($_SERVER['HTTP_X_SITEWATCH_TIMESTAMP'] ?? ''),
        strtolower((string) ($_SERVER['HTTP_X_SITEWATCH_SIGNATURE'] ?? '')),
        $body
    );
    $service->throttle($websiteId);

    $payload = json_decode($body, true);
    if (!is_array($payload) || (int) ($payload['v'] ?? 0) !== 1) {
        throw new ConnectorException('Unsupported report format. Update the SiteWatch Connector plugin.', 400);
    }

    $data = $service->ingest($verified['website'], $verified['connector'], $payload, Request::ip());
} catch (ConnectorException $e) {
    if ($e->getCode() !== 429) {
        App::logger('connector')->notice('Connector request rejected', ['website_id' => $websiteId, 'ip' => Request::ip(), 'reason' => $e->getMessage()]);
    }
    Response::error($e->getMessage(), [], $e->getCode() >= 400 ? $e->getCode() : 400);
}

Response::success('Report received.', $data);
