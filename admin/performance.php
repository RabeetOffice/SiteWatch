<?php

declare(strict_types=1);

/**
 * Performance: response times, Core Web Vitals and country availability, one tab each.
 * Replaces the separate Response Times, Performance report and Core Web Vitals pages of 1.x.
 */

require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/navigation.php';

use App\Core\App;
use App\Services\ReportService;
use App\Services\ServiceFactory;

App::auth()->requireLogin();
$pageTabs = sw_page_tabs('performance');
$activeTab = sw_resolve_tab($pageTabs, $_GET['tab'] ?? null, 'reports.view');

$pageTitle = 'Performance';
$activeNav = 'performance';
$s = App::settings();

if ($activeTab === 'response') {
    $window = (string) ($_GET['window'] ?? '24h');
    $criteria = [
        'window' => isset(ReportService::RESPONSE_WINDOWS[$window]) ? $window : '24h',
        'client' => mb_substr((string) ($_GET['client'] ?? ''), 0, 150),
        'from'   => (string) ($_GET['from'] ?? ''),
        'to'     => (string) ($_GET['to'] ?? ''),
    ];
    $pageSubtitle = 'How quickly each website answers, from SiteWatch\'s own checks.';
    $pageScripts = ['performance-response.js'];
    $needsCharts = true;
    $pageData = [
        'criteria' => $criteria,
        'clients'  => ServiceFactory::websites()->clients(),
        // First page of data is part of the page, so the table does not wait for a second request.
        'initial'  => ServiceFactory::reports()->responseTimeReport($criteria, $s->getInt('slow_threshold', 5000)),
    ];
    $headerActions = '<a class="btn btn-light" id="rtExport" href="#" data-no-swap><i class="bi bi-download" aria-hidden="true"></i>Export CSV</a>'
        . '<button type="button" class="btn btn-light" id="printReport"><i class="bi bi-printer" aria-hidden="true"></i>Print</button>';
} elseif ($activeTab === 'vitals') {
    $pageSubtitle = 'Loading speed and stability as Google measures them, for mobile and desktop.';
    $pageScripts = ['web-vitals.js'];
    $needsCharts = true;
    $pageData = [
        'vitalsEnabled' => $s->getBool('vitals_enabled'),
        'intervalHours' => max(1, $s->getInt('vitals_interval_hours', 24)),
        'canRun'        => can('websites.check'),
    ];
    $headerActions = '<div class="segmented" id="cwvStrategy" role="group" aria-label="Device">'
        . '<button type="button" data-strategy="mobile" class="active"><i class="bi bi-phone" aria-hidden="true"></i> Mobile</button>'
        . '<button type="button" data-strategy="desktop"><i class="bi bi-display" aria-hidden="true"></i> Desktop</button></div>';
} else {
    $pageSubtitle = 'Whether each website loads from other countries, checked from test servers around the world.';
    $pageScripts = ['countries.js'];
    $pageData = ServiceFactory::countryChecks()->pageData(can('websites.check'), can('settings.manage'));
    $headerActions = can('websites.check')
        ? '<button type="button" class="btn btn-light" id="ccRunAll"' . ($pageData['enabled'] ? '' : ' disabled') . '><i class="bi bi-arrow-repeat" aria-hidden="true"></i>Check all now</button>'
        : '';
}

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/pages/performance-' . $activeTab . '.php';
require dirname(__DIR__) . '/includes/footer.php';
