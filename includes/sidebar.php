<?php

declare(strict_types=1);

/**
 * Sidebar navigation. Uses $activeNav, $currentUser, $appName, $csrfToken, $initials from header.php.
 */

use App\Core\App;
use App\Monitoring\MonitoringScheduler;
use App\Repositories\HeartbeatRepository;
use App\Repositories\IncidentRepository;
use App\Repositories\WebsiteRepository;

$openIncidents = 0;
$engine = ['state' => 'never', 'label' => 'Not Running', 'last_run_ago' => 'never', 'message' => null];
try {
    $db = App::db();
    $openIncidents = (new IncidentRepository($db))->countOpen();
    $engine = (new MonitoringScheduler(new WebsiteRepository($db), new HeartbeatRepository($db), App::settings()))->engineStatus();
} catch (Throwable) {
    // Sidebar must never break a page.
}

$nav = [
    ['label' => null, 'items' => [
        ['key' => 'dashboard', 'icon' => 'bi-grid-1x2', 'label' => 'Dashboard', 'href' => 'admin/dashboard.php'],
    ]],
    ['label' => 'Monitoring', 'items' => [
        ['key' => 'websites', 'icon' => 'bi-globe2', 'label' => 'Websites', 'href' => 'admin/websites.php'],
        ['key' => 'website-add', 'icon' => 'bi-plus-circle', 'label' => 'Add Website', 'href' => 'admin/website-add.php'],
        ['key' => 'incidents', 'icon' => 'bi-exclamation-octagon', 'label' => 'Incidents', 'href' => 'admin/incidents.php', 'count' => $openIncidents],
        ['key' => 'response-times', 'icon' => 'bi-speedometer2', 'label' => 'Response Times', 'href' => 'admin/response-times.php'],
    ]],
    ['label' => 'Reports', 'items' => [
        ['key' => 'reports', 'icon' => 'bi-bar-chart-line', 'label' => 'Uptime Reports', 'href' => 'admin/reports.php'],
        ['key' => 'performance', 'icon' => 'bi-activity', 'label' => 'Performance', 'href' => 'admin/performance.php'],
    ]],
    ['label' => 'System', 'items' => [
        ['key' => 'notifications', 'icon' => 'bi-bell', 'label' => 'Notifications', 'href' => 'admin/notifications.php'],
        ['key' => 'monitoring-settings', 'icon' => 'bi-sliders', 'label' => 'Monitoring Settings', 'href' => 'admin/settings.php?section=monitoring'],
        ['key' => 'settings', 'icon' => 'bi-gear', 'label' => 'General Settings', 'href' => 'admin/settings.php'],
        ['key' => 'activity', 'icon' => 'bi-clock-history', 'label' => 'Activity Log', 'href' => 'admin/activity.php'],
    ]],
    ['label' => 'Account', 'items' => [
        ['key' => 'profile', 'icon' => 'bi-person', 'label' => 'Profile', 'href' => 'admin/profile.php'],
        ['key' => 'logout', 'icon' => 'bi-box-arrow-right', 'label' => 'Logout', 'href' => null],
    ]],
];
?>
<aside class="sw-sidebar" id="sidebar" aria-label="Main navigation">
    <a class="sw-brand" href="<?= e(base_url('admin/dashboard.php')) ?>">
        <span class="sw-brand-mark"><i class="bi bi-broadcast"></i></span>
        <span><?= e($appName) ?></span>
    </a>
    <nav class="sw-nav">
        <?php foreach ($nav as $group): ?>
            <div class="sw-nav-group">
                <?php if ($group['label'] !== null): ?><div class="sw-nav-label"><?= e($group['label']) ?></div><?php endif; ?>
                <?php foreach ($group['items'] as $item): ?>
                    <?php if ($item['href'] === null): ?>
                        <form method="post" action="<?= e(base_url('logout.php')) ?>">
                            <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
                            <button type="submit" class="sw-nav-link w-100 border-0 text-start" data-bs-toggle="tooltip" data-bs-placement="right" title="<?= e($item['label']) ?>">
                                <i class="bi <?= e($item['icon']) ?>"></i><span><?= e($item['label']) ?></span>
                            </button>
                        </form>
                    <?php else: ?>
                        <a class="sw-nav-link<?= $activeNav === $item['key'] ? ' active' : '' ?>" href="<?= e(base_url($item['href'])) ?>" data-bs-toggle="tooltip" data-bs-placement="right" title="<?= e($item['label']) ?>">
                            <i class="bi <?= e($item['icon']) ?>"></i><span><?= e($item['label']) ?></span>
                            <?php if (isset($item['count'])): ?><span class="sw-nav-count" id="navIncidentCount"><?= $item['count'] > 0 ? (int) $item['count'] : '' ?></span><?php endif; ?>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </nav>
    <div class="sw-sidebar-footer">
        <div class="sw-engine state-<?= e($engine['state']) ?>" id="engineStatus" data-bs-toggle="tooltip" data-bs-placement="right" title="<?= e($engine['message'] ?? ('Last run ' . $engine['last_run_ago'])) ?>">
            <span class="sw-engine-dot"></span>
            <div class="sw-engine-text">
                <div class="t">Monitoring Engine</div>
                <div class="s" data-engine-label><?= e($engine['label']) ?></div>
                <div class="m" data-engine-meta>Last run: <?= e($engine['last_run_ago']) ?></div>
            </div>
        </div>
        <div class="sw-user">
            <span class="sw-avatar"><?= e($initials) ?></span>
            <div class="sw-user-text">
                <div class="n"><?= e($currentUser['name']) ?></div>
                <div class="e"><?= e($currentUser['email']) ?></div>
            </div>
        </div>
    </div>
</aside>
