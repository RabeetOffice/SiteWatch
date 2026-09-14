<?php

declare(strict_types=1);

/**
 * Page shell: <head>, sidebar, topbar. Expects (optional) variables:
 *   $pageTitle     string   Page title (topbar + <title>)
 *   $pageSubtitle  string   Topbar subtitle
 *   $activeNav     string   Sidebar key (dashboard, websites, website-add, incidents, response-times,
 *                            reports, performance, notifications, monitoring-settings, settings, activity, profile)
 *   $pageScripts   array    JS files from assets/js to load after app.js
 *   $needsCharts   bool     Load Chart.js
 *   $headerActions string   HTML rendered in the topbar action area
 *   $pageData      array    JSON payload exposed to JS as SW.page
 */

use App\Core\App;

$auth = App::auth();
$auth->requireLogin();
$currentUser = $auth->user() ?? ['name' => 'Administrator', 'email' => ''];

$pageTitle = $pageTitle ?? 'Dashboard';
$pageSubtitle = $pageSubtitle ?? '';
$activeNav = $activeNav ?? '';
$pageScripts = $pageScripts ?? [];
$needsCharts = $needsCharts ?? false;
$headerActions = $headerActions ?? '';
$pageData = $pageData ?? [];

$appName = (string) setting('app_name', 'SiteWatch') ?: 'SiteWatch';
$csrfToken = App::csrf()->token();
$nonce = defined('SW_CSP_NONCE') ? SW_CSP_NONCE : '';
$swConfig = [
    'baseUrl'  => base_url(),
    'csrf'     => $csrfToken,
    'refresh'  => max(15, (int) setting('dashboard_refresh_seconds', 30)),
    'timezone' => app_timezone()->getName(),
    'page'     => $activeNav,
    'user'     => ['name' => (string) $currentUser['name'], 'email' => (string) $currentUser['email']],
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
    <link rel="icon" href="<?= e(base_url('assets/images/favicon.svg')) ?>" type="image/svg+xml">
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
<div class="sw-layout">
    <?php require __DIR__ . '/sidebar.php'; ?>
    <div class="sw-main">
        <header class="sw-topbar">
            <button type="button" class="btn-icon d-lg-none" id="sidebarToggle" aria-label="Open navigation"><i class="bi bi-list"></i></button>
            <button type="button" class="btn-icon d-none d-lg-inline-flex" id="sidebarCollapse" aria-label="Collapse navigation" data-bs-toggle="tooltip" title="Toggle sidebar"><i class="bi bi-layout-sidebar"></i></button>
            <div class="sw-topbar-title">
                <h1><?= e($pageTitle) ?></h1>
                <?php if ($pageSubtitle !== ''): ?><p><?= e($pageSubtitle) ?></p><?php endif; ?>
            </div>
            <div class="sw-topbar-actions">
                <?= $headerActions ?>
                <span class="refresh-indicator d-none d-md-inline-flex" id="refreshIndicator" title="Live updates"><span class="dot"></span><span class="txt">Live</span></span>
                <button type="button" class="btn-icon" id="themeToggle" aria-label="Toggle dark mode" data-bs-toggle="tooltip" title="Toggle theme"><i class="bi bi-moon-stars"></i></button>
                <div class="dropdown">
                    <button type="button" class="btn-icon" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account menu"><span class="sw-avatar" style="width:30px;height:30px;font-size:12px"><?= e($initials) ?></span></button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><h6 class="dropdown-header"><?= e($currentUser['name']) ?></h6></li>
                        <li><a class="dropdown-item" href="<?= e(base_url('admin/profile.php')) ?>"><i class="bi bi-person"></i> Profile</a></li>
                        <li><a class="dropdown-item" href="<?= e(base_url('admin/settings.php')) ?>"><i class="bi bi-gear"></i> Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="post" action="<?= e(base_url('logout.php')) ?>">
                                <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
                                <button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right"></i> Sign out</button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </header>
        <main class="sw-content">
