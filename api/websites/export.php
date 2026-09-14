<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\WebsiteRepository;
use App\Services\ServiceFactory;

Api::boot(['GET']);

$filter = Request::string('filter', 'all');
if (!in_array($filter, WebsiteRepository::FILTERS, true)) {
    $filter = 'all';
}
$criteria = ['q' => mb_substr(Request::string('q'), 0, 100), 'filter' => $filter, 'client' => mb_substr(Request::string('client'), 0, 150)];

$websites = ServiceFactory::websites();
$rows = $websites->searchAll($criteria);

// Attach 30-day uptime from daily stats.
$stats = ServiceFactory::dailyStats()->perWebsiteRangeStats(
    \App\Monitoring\UptimeCalculator::localDate(-29),
    \App\Monitoring\UptimeCalculator::localDate()
);
foreach ($rows as &$row) {
    $row['uptime_30d'] = $stats[(int) $row['id']]['uptime'] ?? null;
}
unset($row);

$export = ServiceFactory::websiteService()->exportRows($rows);
Response::csv('sitewatch-websites-' . gmdate('Y-m-d') . '.csv', $export['headers'], $export['rows']);
