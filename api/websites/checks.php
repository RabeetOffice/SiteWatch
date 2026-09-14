<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\Request;
use App\Core\Response;
use App\Monitoring\Status;
use App\Services\ServiceFactory;

Api::boot(['GET']);

$id = Request::int('id');
if ($id <= 0 || ServiceFactory::websites()->find($id) === null) {
    Response::error('Website not found.', [], 404);
}

$pagination = Api::pagination(15, 100);
$only = Request::string('only') === 'failures' ? 'failures' : null;
$result = ServiceFactory::checks()->recent($id, $pagination['per_page'], $pagination['offset'], $only);

$rows = array_map(static fn (array $c): array => [
    'id'             => (int) $c['id'],
    'status'         => $c['status'],
    'status_label'   => Status::label($c['status']),
    'severity'       => Status::severity($c['status']),
    'is_failure'     => (int) $c['is_failure'] === 1,
    'is_up'          => (int) $c['is_up'] === 1,
    'http_status'    => $c['http_status'] !== null ? (int) $c['http_status'] : null,
    'response_time'  => $c['response_time'] !== null ? (int) $c['response_time'] : null,
    'error_type'     => $c['error_type'],
    'error_message'  => $c['error_message'],
    'redirect_count' => (int) $c['redirect_count'],
    'final_url'      => $c['final_url'],
    'source'         => $c['source'],
    'checked_at'     => $c['checked_at'],
    'checked_label'  => format_datetime($c['checked_at']),
], $result['rows']);

Response::success('', [
    'rows'     => $rows,
    'total'    => $result['total'],
    'page'     => $pagination['page'],
    'per_page' => $pagination['per_page'],
]);
