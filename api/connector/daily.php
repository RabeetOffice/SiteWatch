<?php

declare(strict_types=1);

/**
 * One earlier daily report from the WordPress plugin (page speed and PHP warnings), for the report picker on the
 * website page's Performance tab.
 *
 *   GET website_id, id
 */

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ConnectorRepository;
use App\Services\ServiceFactory;

Api::boot(['GET']);
App::session()->release();

$website = ServiceFactory::websites()->find(Request::int('website_id'));
if ($website === null) {
    Response::error('Website not found.', [], 404);
}
$row = (new ConnectorRepository(App::db()))->dailyOne((int) $website['id'], Request::int('id'));
if ($row === null) {
    Response::error('That report is not stored (reports are kept for 90 days).', [], 404);
}
$decode = static fn (?string $json): ?array => $json !== null && $json !== '' ? (json_decode($json, true) ?: null) : null;
Response::success('', ['report' => [
    'id'           => (int) $row['id'],
    'period_start' => $row['period_start'],
    'period_end'   => $row['period_end'],
    'performance'  => $decode($row['performance']),
    'php_warnings' => $decode($row['php_warnings']),
]]);
