<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\Request;
use App\Core\Response;
use App\Monitoring\Status;
use App\Repositories\WebsiteRepository;
use App\Services\ServiceFactory;

Api::boot(['GET']);

$websites = ServiceFactory::websites();
$service = ServiceFactory::websiteService();
$pagination = Api::pagination(25, 100);

$filter = Request::string('filter', 'all');
if (!in_array($filter, WebsiteRepository::FILTERS, true)) {
    $filter = 'all';
}
$sort = Request::string('sort', 'status');
if (!in_array($sort, WebsiteRepository::SORTS, true)) {
    $sort = 'status';
}
$criteria = [
    'q'      => mb_substr(Request::string('q'), 0, 100),
    'filter' => $filter,
    'sort'   => $sort,
    'dir'    => Request::string('dir'),
    'client' => mb_substr(Request::string('client'), 0, 150),
];

// Every matching website in compact form, for "select all" across pages and the bulk-check time estimate.
if (Request::string('select') === 'all') {
    $items = array_map(static fn (array $w): array => [
        'id'                 => (int) $w['id'],
        'name'               => (string) $w['name'],
        'monitoring_enabled' => (int) $w['monitoring_enabled'] === 1,
        'last_response_time' => $w['last_response_time'] !== null ? (int) $w['last_response_time'] : null,
    ], $websites->searchAll($criteria));
    Response::success('', ['items' => $items, 'total' => count($items)]);
}

$result = $websites->search($criteria, $pagination['per_page'], $pagination['offset']);

// Clamp the page when the result set shrank (e.g. after a delete).
$page = $pagination['page'];
if ($result['total'] > 0 && $pagination['offset'] >= $result['total']) {
    $page = max(1, (int) ceil($result['total'] / $pagination['per_page']));
    $result = $websites->search($criteria, $pagination['per_page'], ($page - 1) * $pagination['per_page']);
}

// Filter chip counts (cheap GROUP BY on the status index).
$statusCounts = $websites->statusCounts();
$counts = ['all' => array_sum($statusCounts), 'online' => 0, 'down' => 0, 'critical' => 0, 'warning' => 0, 'slow' => 0, 'ssl_expiring' => $websites->sslExpiringCount(30), 'paused' => $statusCounts[Status::PAUSED] ?? 0,
    'connector' => $websites->connectorCount()];
foreach ($statusCounts as $status => $count) {
    if ($status === Status::PAUSED || $status === Status::PENDING) {
        continue;
    }
    $severity = Status::severity($status);
    if ($severity === Status::SEVERITY_OK) {
        $counts['online'] += $count;
    } elseif ($severity === Status::SEVERITY_DOWN) {
        $counts['down'] += $count;
    } elseif ($severity === Status::SEVERITY_WARNING) {
        $counts['warning'] += $count;
    }
    if (in_array($status, [Status::CRITICAL_ERROR, Status::DATABASE_ERROR], true)) {
        $counts['critical'] += $count;
    }
    if (in_array($status, [Status::SLOW, Status::CRITICAL_PERFORMANCE], true)) {
        $counts['slow'] += $count;
    }
}

Response::success('', [
    'rows'     => array_map([$service, 'present'], $result['rows']),
    'total'    => $result['total'],
    'page'     => $page,
    'per_page' => $pagination['per_page'],
    'counts'   => $counts,
    'clients'  => $websites->clients(),
]);
