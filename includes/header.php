<?php

declare(strict_types=1);

/**
 * Page shell: <head>, sidebar, topbar, page header. Expects (optional) variables:
 *   $pageTitle     string   Page title (page header + <title>)
 *   $pageSubtitle  string   Short context line under the page title (optional; keep it factual)
 *   $pageContext   string   HTML rendered in the page header context row (overrides $pageSubtitle)
 *   $activeNav     string   Sidebar key (dashboard, websites, incidents, performance, domains, reports, team,
 *                            settings, activity, profile, updates) — see includes/navigation.php
 *   $pageTabs      array    Tabs under the page header: [['key','label','href','icon'?, 'count'?], ...]
 *   $activeTab     string   Key of the selected tab
 *   $breadcrumbs   array    [['label', 'href'?], ...] shown above the page title
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
require_once __DIR__ . '/navigation.php';

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
$pageTabs = $pageTabs ?? [];
$activeTab = $activeTab ?? '';
$breadcrumbs = $breadcrumbs ?? [];

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
$monitoringSettingsUrl = can('settings.manage') ? base_url('admin/settings.php?tab=monitoring') : null;
$engineTag = $monitoringSettingsUrl !== null ? 'a' : 'span';

$navGroups = sw_nav();

// Everything the command palette (Ctrl/Cmd + K) can jump to, besides websites (loaded on demand).
$paletteItems = [];
foreach ($navGroups as $group) {
    foreach ($group['items'] as $item) {
        $paletteItems[] = ['label' => $item['label'], 'href' => base_url($item['href']), 'icon' => $item['icon'], 'group' => 'Pages', 'shortcut' => $item['shortcut'] ?? null];
        foreach (sw_page_tabs($item['key']) as $tab) {
            $paletteItems[] = ['label' => $item['label'] . ' › ' . $tab['label'], 'href' => base_url($tab['href']), 'icon' => $tab['icon'], 'group' => 'Pages'];
        }
    }
}
if (can('websites.manage')) {
    $paletteItems[] = ['label' => 'Add website', 'href' => base_url('admin/website-add.php'), 'icon' => 'bi-plus-lg', 'group' => 'Actions', 'shortcut' => 'n'];
    $paletteItems[] = ['label' => 'Import websites', 'href' => base_url('admin/website-import.php'), 'icon' => 'bi-upload', 'group' => 'Actions'];
}
$paletteItems[] = ['label' => 'Profile', 'href' => base_url('admin/profile.php'), 'icon' => 'bi-person-circle', 'group' => 'Account'];
if (can('*')) {
    $paletteItems[] = ['label' => 'Updates & release notes', 'href' => base_url('admin/updates.php'), 'icon' => 'bi-cloud-arrow-down', 'group' => 'Account'];
}

$swConfig = [
    'baseUrl'  => base_url(),
    'csrf'     => $csrfToken,
    'refresh'  => max(15, (int) setting('dashboard_refresh_seconds', 30)),
    'version'  => (string) config('app.version', ''),
    // Upper bound for one website check (seconds), used for the bulk-check time estimate.
    'requestTimeout' => (int) setting('request_timeout', 30),
    'timezone' => app_timezone()->getName(),
    'page'     => $activeNav,
    'tab'      => $activeTab,
    'user'     => ['name' => (string) $currentUser['name'], 'email' => (string) $currentUser['email'], 'role' => (string) ($currentUser['role_name'] ?? '')],
    // Only used to hide controls the user cannot use; the server enforces every permission.
    'permissions' => Permission::expand($auth->permissions()),
    'openIncidents' => $openIncidents,
    'palette'  => $paletteItems,
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
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= e($csrfToken) ?>">
    <meta name="theme-color" content="#F8FAFC" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#111315" media="(prefers-color-scheme: dark)">
    <title><?= e($pageTitle) ?> · <?= e($appName) ?></title>
    <link rel="icon" href="<?= e(sw_brand_asset('favicon')) ?>" type="image/svg+xml">
    <link rel="apple-touch-icon" href="<?= e(sw_brand_asset('apple')) ?>">
    <link rel="manifest" href="<?= e(base_url('manifest.webmanifest')) ?>">
    <link rel="preload" href="<?= e(base_url('assets/fonts/inter-latin-wght-normal.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <?= sw_splash_head() ?>
    <link href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset('css/app.css')) ?>" rel="stylesheet">
    <script nonce="<?= e($nonce) ?>">
        (function () {
            var html = document.documentElement;
            try {
                var t = localStorage.getItem('sw-theme');
                if (t !== 'dark' && t !== 'light') { t = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'; }
                html.setAttribute('data-bs-theme', t);
                if (localStorage.getItem('sw-sidebar') === 'collapsed') { html.classList.add('sidebar-collapsed'); }
                if (localStorage.getItem('sw-density') === 'compact') { html.classList.add('density-compact'); }
            } catch (e) {}
            <?= sw_splash_script() ?>
        })();
    </script>
</head>
<body class="sw-body">
<?= sw_brand_splash() ?>
<div class="sw-progress" id="swProgress" aria-hidden="true"><span></span></div>
<a class="skip-link" href="#mainContent">Skip to content</a>
<div class="sw-layout">
    <?php require __DIR__ . '/sidebar.php'; ?>
    <div class="sw-main">
        <header class="sw-topbar">
            <button type="button" class="btn-icon d-lg-none" id="sidebarToggle" aria-label="Open navigation"><i class="bi bi-list" aria-hidden="true"></i></button>
            <button type="button" class="btn-icon d-none d-lg-inline-flex" id="sidebarCollapse" aria-label="Collapse navigation" data-bs-toggle="tooltip" title="Toggle sidebar"><i class="bi bi-layout-sidebar" aria-hidden="true"></i></button>
            <a class="sw-topbar-brand" href="<?= e(base_url('admin/dashboard.php')) ?>"><?= sw_brand_mark(28) ?></a>
            <button type="button" class="sw-search-trigger" id="paletteTrigger" aria-label="Search websites and pages" aria-haspopup="dialog">
                <i class="bi bi-search" aria-hidden="true"></i>
                <span class="t">Search websites and pages…</span>
                <kbd class="sw-kbd" data-mod-key>Ctrl K</kbd>
            </button>
            <div class="sw-topbar-spacer"></div>
            <div class="sw-topbar-actions">
                <<?= $engineTag ?> class="sw-engine state-<?= e($engine['state']) ?>" id="engineStatus"
                   <?= $monitoringSettingsUrl !== null ? 'href="' . e($monitoringSettingsUrl) . '"' : 'tabindex="0"' ?>
                   data-bs-toggle="tooltip" title="<?= e($engineDetail) ?>">
                    <span class="sw-engine-dot" aria-hidden="true"></span>
                    <span class="sw-engine-text">
                        <span class="t visually-hidden">Monitoring engine:</span>
                        <span class="s" data-engine-label><?= e($engine['label']) ?></span>
                        <span class="m d-none d-xl-inline" data-engine-meta>Last run <?= e($engine['last_run_ago']) ?></span>
                    </span>
                </<?= $engineTag ?>>
                <button type="button" class="btn-icon" id="themeToggle" aria-label="Toggle dark mode" data-bs-toggle="tooltip" title="Toggle theme"><i class="bi bi-moon-stars" aria-hidden="true"></i></button>
                <div class="dropdown">
                    <button type="button" class="btn-icon sw-account-btn" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account menu"><?= user_avatar($currentUser) ?></button>
                    <ul class="dropdown-menu dropdown-menu-end sw-account-menu">
                        <li class="sw-account-head">
                            <?= user_avatar($currentUser) ?>
                            <span class="min-w-0"><span class="n"><?= e($currentUser['name']) ?></span><span class="e"><?= e(!empty($currentUser['role_name']) ? $currentUser['role_name'] : $currentUser['email']) ?></span></span>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= e(base_url('admin/profile.php')) ?>"><i class="bi bi-person" aria-hidden="true"></i> Profile</a></li>
                        <li><a class="dropdown-item" href="<?= e(base_url('admin/profile.php#device')) ?>"><i class="bi bi-bell" aria-hidden="true"></i> Desktop notifications</a></li>
                        <li><button type="button" class="dropdown-item" data-install-app hidden><i class="bi bi-window-plus" aria-hidden="true"></i> Install SiteWatch app</button></li>
                        <li><button type="button" class="dropdown-item" data-toggle-density><i class="bi bi-distribute-vertical" aria-hidden="true"></i> <span data-density-label>Compact tables</span></button></li>
                        <li><button type="button" class="dropdown-item" data-open-shortcuts><i class="bi bi-keyboard" aria-hidden="true"></i> Keyboard shortcuts <kbd class="sw-kbd ms-auto">?</kbd></button></li>
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
<?php if (!$hidePageHead): ?>
        <div class="sw-page-head<?= $pageTabs !== [] ? ' has-tabs' : '' ?>">
            <div class="min-w-0">
                <?php if ($breadcrumbs !== []): ?>
                    <nav class="sw-breadcrumb" aria-label="Breadcrumb">
                        <?php foreach ($breadcrumbs as $crumb): ?>
                            <?php if (!empty($crumb['href'])): ?><a href="<?= e(base_url($crumb['href'])) ?>"><?= e($crumb['label']) ?></a><?php else: ?><span><?= e($crumb['label']) ?></span><?php endif; ?>
                            <i class="bi bi-chevron-right" aria-hidden="true"></i>
                        <?php endforeach; ?>
                    </nav>
                <?php endif; ?>
                <h1><?= e($pageTitle) ?></h1>
                <?php if ($pageContext !== ''): ?>
                    <div class="sw-page-context"><?= $pageContext ?></div>
                <?php elseif ($pageSubtitle !== ''): ?>
                    <div class="sw-page-context"><?= e($pageSubtitle) ?></div>
                <?php endif; ?>
            </div>
            <?php if ($headerActions !== ''): ?><div class="sw-page-actions"><?= $headerActions ?></div><?php endif; ?>
        </div>
        <?php if ($pageTabs !== []): ?>
            <nav class="sw-tabs" aria-label="<?= e($pageTitle) ?> sections">
                <?php foreach ($pageTabs as $tab): ?>
                    <a href="<?= e(base_url($tab['href'])) ?>"<?= $activeTab === $tab['key'] ? ' class="active" aria-current="page"' : '' ?>>
                        <?php if (!empty($tab['icon'])): ?><i class="bi <?= e($tab['icon']) ?>" aria-hidden="true"></i><?php endif; ?>
                        <span><?= e($tab['label']) ?></span>
                        <?php if (isset($tab['count']) && (int) $tab['count'] > 0): ?><span class="sw-tab-count"><?= (int) $tab['count'] ?></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
<?php endif; ?>