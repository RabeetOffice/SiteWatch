<?php

declare(strict_types=1);

/**
 * Page shell: <head>, sidebar, topbar, page header. Expects (optional) variables:
 *   $pageTitle     string   Page title (page header + <title>)
 *   $pageSubtitle  string   Short context line under the page title (optional; keep it factual)
 *   $pageContext   string   HTML rendered in the page header context row (overrides $pageSubtitle)
 *   $activeNav     string   Sidebar key (dashboard, websites, website-add, incidents, response-times, domains,
 *                            reports, performance, notifications, monitoring-settings, settings, activity,
 *                            users, roles, profile)
 *   $pageScripts   array    JS files from assets/js to load after app.js
 *   $needsCharts   bool     Load Chart.js
 *   $headerActions string   HTML rendered in the page header action area
 *   $hidePageHead  bool     Skip the standard page header (page renders its own)
 *   $pageData      array    JSON payload exposed to JS as SW.page
 */

use App\Core\App;
use App\Core\Permission;
use App\Monitoring\MonitoringScheduler;
use App\Repositories\HeartbeatRepository;
use App\Repositories\IncidentRepository;
use App\Repositories\WebsiteRepository;

require_once __DIR__ . '/brand.php';

$auth = App::auth();
$auth->requireLogin();
$currentUser = $auth->user() ?? ['name' => 'Administrator', 'email' => ''];

$pageTitle = $pageTitle ?? 'Dashboard';
$pageSubtitle = $pageSubtitle ?? '';
$pageContext = $pageContext ?? '';
$activeNav = $activeNav ?? '';
$pageScripts = $pageScripts ?? [];
$needsCharts = $needsCharts ?? false;
$headerActions = $headerActions ?? '';
$hidePageHead = $hidePageHead ?? false;
$pageData = $pageData ?? [];

$appName = (string) setting('app_name', 'SiteWatch') ?: 'SiteWatch';
$csrfToken = App::csrf()->token();
$nonce = defined('SW_CSP_NONCE') ? SW_CSP_NONCE : '';

// Monitoring engine health and open incident count power the topbar chip, the
// degraded-state banner and the sidebar badge. Never let them break a page.
$openIncidents = 0;
$engine = ['state' => 'never', 'label' => 'Not Running', 'last_run_ago' => 'never', 'message' => null];
try {
    $db = App::db();
    $openIncidents = (new IncidentRepository($db))->countOpen();
    $engine = (new MonitoringScheduler(new WebsiteRepository($db), new HeartbeatRepository($db), App::settings()))->engineStatus();
} catch (Throwable) {
    // Fall through with the defaults above.
}
$engineHealthy = $engine['state'] === 'running';
$engineDetail = $engine['message'] ?? ('Last monitoring run: ' . $engine['last_run_ago']);
$bannerTitle = $engine['state'] === 'never'
    ? 'Monitoring has never run'
    : ($engine['state'] === 'problem' ? 'The last monitoring run failed' : 'Monitoring is not running');
$monitoringSettingsUrl = can('settings.manage') ? base_url('admin/settings.php?section=monitoring') : null;
$engineTag = $monitoringSettingsUrl !== null ? 'a' : 'span';

$swConfig = [
    'baseUrl'  => base_url(),
    'csrf'     => $csrfToken,
    'refresh'  => max(15, (int) setting('dashboard_refresh_seconds', 30)),
    'version'  => (string) config('app.version', ''),
    // Upper bound for one website check (seconds), used for the bulk-check time estimate.
    'requestTimeout' => (int) setting('request_timeout', 30),
    'timezone' => app_timezone()->getName(),
    'page'     => $activeNav,
    'user'     => ['name' => (string) $currentUser['name'], 'email' => (string) $currentUser['email'], 'role' => (string) ($currentUser['role_name'] ?? '')],
    // Only used to hide controls the user cannot use; the server enforces every permission.
    'permissions' => Permission::expand($auth->permissions()),
    'thresholds' => [
        'moderate' => (int) setting('moderate_threshold', 2000),
        'slow'     => (int) setting('slow_threshold', 5000),
        'critical' => (int) setting('critical_performance_threshold', 10000),
    ],
];
$initials = strtoupper(mb_substr((string) $currentUser['name'], 0, 1));
$parts = preg_split('/\s+/', trim((string) $currentUser['name'])) ?: [];
if (count($parts) > 1) {
    $initials .= strtoupper(mb_substr((string) end($parts), 0, 1));
}
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= e($csrfToken) ?>">
    <title><?= e($pageTitle) ?> · <?= e($appName) ?></title>
    <link rel="icon" href="<?= e(sw_brand_asset('favicon')) ?>" type="image/svg+xml">
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= e(asset('css/app.css')) ?>" rel="stylesheet">
    <script nonce="<?= e($nonce) ?>">
        (function () {
            try {
                var t = localStorage.getItem('sw-theme');
                if (t !== 'dark' && t !== 'light') { t = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'; }
                document.documentElement.setAttribute('data-bs-theme', t);
                if (localStorage.getItem('sw-sidebar') === 'collapsed') { document.documentElement.classList.add('sidebar-collapsed'); }
            } catch (e) {}
        })();
    </script>
</head>
<body class="sw-body">
<a class="skip-link" href="#mainContent">Skip to content</a>
<div class="sw-layout">
    <?php require __DIR__ . '/sidebar.php'; ?>
    <div class="sw-main">
        <header class="sw-topbar">
            <button type="button" class="btn-icon d-lg-none" id="sidebarToggle" aria-label="Open navigation"><i class="bi bi-list" aria-hidden="true"></i></button>
            <button type="button" class="btn-icon d-none d-lg-inline-flex" id="sidebarCollapse" aria-label="Collapse navigation" data-bs-toggle="tooltip" title="Toggle sidebar"><i class="bi bi-layout-sidebar" aria-hidden="true"></i></button>
            <a class="sw-topbar-brand" href="<?= e(base_url('admin/dashboard.php')) ?>"><?= sw_brand_logo(130) ?></a>
            <div class="sw-topbar-spacer"></div>
            <div class="sw-topbar-actions">
                <<?= $engineTag ?> class="sw-engine state-<?= e($engine['state']) ?> d-none d-md-inline-flex" id="engineStatus"
                   <?= $monitoringSettingsUrl !== null ? 'href="' . e($monitoringSettingsUrl) . '"' : 'tabindex="0"' ?>
                   data-bs-toggle="tooltip" title="<?= e($engineDetail) ?>">
                    <span class="sw-engine-dot" aria-hidden="true"></span>
                    <span class="sw-engine-text">
                        <span class="t">Monitoring engine</span>
                        <span class="s" data-engine-label><?= e($engine['label']) ?></span>
                        <span class="m d-none d-xl-inline" data-engine-meta>Last run <?= e($engine['last_run_ago']) ?></span>
                    </span>
                </<?= $engineTag ?>>
                <span class="refresh-indicator d-none d-lg-inline-flex" id="refreshIndicator" data-bs-toggle="tooltip" title="This page refreshes itself. It is separate from the monitoring engine."><span class="dot" aria-hidden="true"></span><span class="txt">Auto-refresh</span></span>
                <button type="button" class="btn-icon" id="themeToggle" aria-label="Toggle dark mode" data-bs-toggle="tooltip" title="Toggle theme"><i class="bi bi-moon-stars" aria-hidden="true"></i></button>
                <div class="dropdown">
                    <button type="button" class="btn-icon" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account menu"><span class="sw-avatar"><?= e($initials) ?></span></button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><h6 class="dropdown-header"><?= e($currentUser['name']) ?><?php if (!empty($currentUser['role_name'])): ?><span class="d-block text-muted fw-normal" style="text-transform:none;letter-spacing:0"><?= e($currentUser['role_name']) ?></span><?php endif; ?></h6></li>
                        <li><a class="dropdown-item" href="<?= e(base_url('admin/profile.php')) ?>"><i class="bi bi-person" aria-hidden="true"></i> Profile</a></li>
                        <?php if (can('users.manage')): ?><li><a class="dropdown-item" href="<?= e(base_url('admin/users.php')) ?>"><i class="bi bi-people" aria-hidden="true"></i> Users</a></li><?php endif; ?>
                        <?php if (can('settings.manage')): ?><li><a class="dropdown-item" href="<?= e(base_url('admin/settings.php')) ?>"><i class="bi bi-gear" aria-hidden="true"></i> Settings</a></li><?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="post" action="<?= e(base_url('logout.php')) ?>">
                                <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
                                <button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right" aria-hidden="true"></i> Sign out</button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </header>
        <main class="sw-content" id="mainContent" tabindex="-1">
        <div class="monitor-banner state-<?= e($engine['state']) ?>" id="monitorBanner" role="status"<?= $engineHealthy ? ' hidden' : '' ?>>
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <div>
                <strong data-monitor-title><?= e($bannerTitle) ?></strong>
                <span data-monitor-detail><?= e($engineDetail) ?> Website statuses on this page may be out of date.</span>
            </div>
            <?php if ($monitoringSettingsUrl !== null): ?><a href="<?= e($monitoringSettingsUrl) ?>">Monitoring settings <i class="bi bi-arrow-right" aria-hidden="true"></i></a><?php endif; ?>
        </div>
<?php if (!$hidePageHead): ?>
        <div class="sw-page-head">
            <div class="min-w-0">
                <h1><?= e($pageTitle) ?></h1>
                <?php if ($pageContext !== ''): ?>
                    <div class="sw-page-context"><?= $pageContext ?></div>
                <?php elseif ($pageSubtitle !== ''): ?>
                    <div class="sw-page-context"><?= e($pageSubtitle) ?></div>
                <?php endif; ?>
            </div>
            <?php if ($headerActions !== ''): ?><div class="sw-page-actions"><?= $headerActions ?></div><?php endif; ?>
        </div>
<?php endif; ?>
