<?php

declare(strict_types=1);

/**
 * Sidebar navigation. Uses $activeNav, $currentUser, $csrfToken, $initials and
 * $openIncidents, all prepared by header.php.
 */

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
        ['key' => 'reports', 'icon' => 'bi-bar-chart-line', 'label' => 'Uptime', 'href' => 'admin/reports.php'],
        ['key' => 'performance', 'icon' => 'bi-activity', 'label' => 'Performance', 'href' => 'admin/performance.php'],
    ]],
    ['label' => 'System', 'items' => [
        ['key' => 'notifications', 'icon' => 'bi-bell', 'label' => 'Notifications', 'href' => 'admin/notifications.php'],
        ['key' => 'monitoring-settings', 'icon' => 'bi-sliders', 'label' => 'Monitoring Settings', 'href' => 'admin/settings.php?section=monitoring'],
        ['key' => 'settings', 'icon' => 'bi-gear', 'label' => 'General Settings', 'href' => 'admin/settings.php'],
        ['key' => 'activity', 'icon' => 'bi-clock-history', 'label' => 'Activity Log', 'href' => 'admin/activity.php'],
    ]],
];
?>
<aside class="sw-sidebar" id="sidebar" aria-label="Main navigation">
    <a class="sw-brand" href="<?= e(base_url('admin/dashboard.php')) ?>">
        <?= sw_brand_logo(158) ?>
        <?= sw_brand_mark(30) ?>
    </a>
    <nav class="sw-nav">
        <?php foreach ($nav as $group): ?>
            <div class="sw-nav-group"<?= $group['label'] !== null ? ' role="group" aria-label="' . e($group['label']) . '"' : '' ?>>
                <?php if ($group['label'] !== null): ?><div class="sw-nav-label" aria-hidden="true"><?= e($group['label']) ?></div><?php endif; ?>
                <?php foreach ($group['items'] as $item): ?>
                    <a class="sw-nav-link<?= $activeNav === $item['key'] ? ' active' : '' ?>" href="<?= e(base_url($item['href'])) ?>"
                       <?= $activeNav === $item['key'] ? 'aria-current="page"' : '' ?>
                       data-bs-toggle="tooltip" data-bs-placement="right" title="<?= e($item['label']) ?>">
                        <i class="bi <?= e($item['icon']) ?>" aria-hidden="true"></i><span><?= e($item['label']) ?></span>
                        <?php if (isset($item['count'])): ?>
                            <span class="sw-nav-count" id="navIncidentCount"><?= $item['count'] > 0 ? (int) $item['count'] : '' ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </nav>
    <div class="sw-sidebar-footer">
        <div class="d-flex align-items-center gap-1">
            <a class="sw-user<?= $activeNav === 'profile' ? ' active' : '' ?>" href="<?= e(base_url('admin/profile.php')) ?>"
               data-bs-toggle="tooltip" data-bs-placement="right" title="Profile">
                <span class="sw-avatar" aria-hidden="true"><?= e($initials) ?></span>
                <span class="sw-user-text">
                    <span class="n"><?= e($currentUser['name']) ?></span>
                    <span class="e"><?= e($currentUser['email']) ?></span>
                </span>
            </a>
            <form method="post" action="<?= e(base_url('logout.php')) ?>" class="ms-auto">
                <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
                <button type="submit" class="btn-icon btn-sm" aria-label="Sign out" data-bs-toggle="tooltip" data-bs-placement="right" title="Sign out"><i class="bi bi-box-arrow-right" aria-hidden="true"></i></button>
            </form>
        </div>
    </div>
</aside>
