<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

require_permission('reports.view');

use App\Services\ServiceFactory;

$pageTitle = 'Uptime report';
$pageSubtitle = 'Availability per website over a date range, ready to print or export for a client.';
$activeNav = 'reports';
$pageScripts = ['reports.js'];
$preset = ['website_id' => (int) ($_GET['website_id'] ?? 0)];
$pageData = [
    'websites' => ServiceFactory::websites()->options(),
    'clients'  => ServiceFactory::websites()->clients(),
    'preset'   => $preset,
    // The default view (last 30 days) is part of the page, so it shows without a second request.
    'initial'  => ServiceFactory::reports()->uptimeReport(['website_id' => $preset['website_id']]),
];
$headerActions = '<a class="btn btn-light" id="reportExport" href="#" data-no-swap><i class="bi bi-download" aria-hidden="true"></i>Export CSV</a>'
    . '<button type="button" class="btn btn-light" id="printReport"><i class="bi bi-printer" aria-hidden="true"></i>Print</button>';

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/report-layout.php';
require dirname(__DIR__) . '/includes/footer.php';
