<?php

declare(strict_types=1);

/**
 * Client reports: branded reports with a share link and PDF download, and the brand kits they use.
 */

require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/navigation.php';

use App\Services\ClientReportService;
use App\Services\ServiceFactory;

require_permission('reports.view');
$pageTabs = sw_page_tabs('client-reports');
$activeTab = sw_resolve_tab($pageTabs, $_GET['tab'] ?? null, 'reports.view');

$pageTitle = 'Client reports';
$activeNav = 'client-reports';
$pageScripts = ['client-reports.js'];
$canShare = can('reports.share');

$sections = [];
foreach (ClientReportService::SECTIONS as $key => [$label, $description]) {
    $sections[] = ['key' => $key, 'label' => $label, 'description' => $description];
}
$pageData = [
    'tab'      => $activeTab,
    'canShare' => $canShare,
    'websites' => ServiceFactory::websites()->options(),
    'clients'  => ServiceFactory::websites()->clients(),
    'periods'  => ClientReportService::PERIODS,
    'sections' => $sections,
    'defaults' => ['primary' => ClientReportService::DEFAULT_PRIMARY, 'accent' => ClientReportService::DEFAULT_ACCENT, 'preparedBy' => (string) config('app.name', 'SiteWatch')],
];

if ($activeTab === 'brands') {
    $pageSubtitle = 'Logos, colours and contact details that make each report look like your client\'s own.';
    $headerActions = $canShare ? '<button type="button" class="btn btn-primary" id="btnAddBrand"><i class="bi bi-plus-lg" aria-hidden="true"></i>New brand kit</button>' : '';
} else {
    $pageSubtitle = 'Branded uptime and performance reports for your clients: share a live link or send the PDF.';
    $headerActions = $canShare ? '<button type="button" class="btn btn-primary" id="btnAddReport"><i class="bi bi-plus-lg" aria-hidden="true"></i>New report</button>' : '';
}

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/pages/client-reports-' . $activeTab . '.php';
require dirname(__DIR__) . '/includes/footer.php';
