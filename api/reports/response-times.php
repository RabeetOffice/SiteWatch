<?php

declare(strict_types=1);

/**
 * Response times per website (Performance → Response time). window = 24h | 7d | 30d | 90d, or a
 * custom from/to date range; optional client filter. Add export=csv for a download.
 */

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Services\ReportService;
use App\Services\ServiceFactory;

Api::boot(['GET'], permission: 'reports.view');

$report = ServiceFactory::reports()->responseTimeReport([
    'window' => Request::string('window', '24h'),
    'client' => mb_substr(Request::string('client'), 0, 150),
    'from'   => Request::string('from'),
    'to'     => Request::string('to'),
], App::settings()->getInt('slow_threshold', 5000));

if (Request::string('export') === 'csv') {
    $export = ReportService::exportResponseRows($report['rows']);
    $suffix = $report['range'] !== null ? $report['range']['from'] . '_' . $report['range']['to'] : $report['window'];
    Response::csv('sitewatch-response-times-' . $suffix . '.csv', $export['headers'], $export['rows']);
}

Response::success('', $report);
