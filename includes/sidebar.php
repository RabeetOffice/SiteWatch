<?php

declare(strict_types=1);

/**
 * Sidebar navigation. Uses $activeNav, $currentUser, $csrfToken, $initials and
 * $openIncidents, all prepared by header.php. Items the user's role cannot open are hidden.
 */

$nav = [
    ['label' => null, 'items' => [
        ['key' => 'dashboard', 'icon' => 'bi-grid-1x2', 'label' => 'Dashboard', 'href' => 'admin/dashboard.php'],
    ]],
    ['label' => 'Monitoring', 'items' => [
        ['key' => 'websites', 'icon' => 'bi-globe2', 'label' => 'Websites', 'href' => 'admin/websites.php'],
        ['key' => 'website-add', 'icon' => 'bi-plus-circle', 'label' => 'Add Website', 'href' => 'admin/website-add.php', 'permission' => 'websites.manage'],
        ['key' => 'incidents', 'icon' => 'bi-exclamation-octagon', 'label' => 'Incidents', 'href' => 'admin/incidents.php', 'count' => $openIncidents, 'permission' => 'incidents.view'],
        ['key' => 'response-times', 'icon' => 'bi-speedometer2', 'label' => 'Response Times', 'href' => 'admin/response-times.php', 'permission' => 'reports.view'],
        ['key' => 'domains', 'icon' => 'bi-globe-americas', 'label' => 'Domains & Hosting', 'href' => 'admin/domains.php', 'permission' => 'domains.view'],
    ]],
    ['label' => 'Reports', 'items' => [
        ['key' => 'reports', 'icon' => 'bi-bar-chart-line', 'label' => 'Uptime', 'href' => 'admin/reports.php', 'permission' => 'reports.view'],
        ['key' => 'performance', 'icon' => 'bi-activity', 'label' => 'Performance', 'href' => 'admin/performance.php', 'permission' => 'reports.view'],
    ]],
    ['label' => 'Team', 'items' => [
        ['key' => 'users', 'icon' => 'bi-people', 'label' => 'Users', 'href' => 'admin/users.php', 'permission' => 'users.manage'],
        ['key' => 'roles', 'icon' => 'bi-shield-lock', 'label' => 'Roles & Permissions', 'href' => 'admin/roles.php', 'permission' => 'roles.manage'],
    ]],
    ['label' => 'System', 'items' => [
        ['key' => 'notifications', 'icon' => 'bi-bell', 'label' => 'Notifications', 'href' => 'admin/notifications.php', 'permission' => 'notifications.manage'],
        ['key' => 'monitoring-settings', 'icon' => 'bi-sliders', 'label' => 'Monitoring Settings', 'href' => 'admin/settings.php?section=monitoring', 'permission' => 'settings.manage'],
        ['key' => 'settings', 'icon' => 'bi-gear', 'label' => 'General Settings', 'href' => 'admin/settings.php', 'permission' => 'settings.manage'],
        ['key' => 'activity', 'icon' => 'bi-clock-history', 'label' => 'Activity Log', 'href' => 'admin/activity.php', 'permission' => 'activity.view'],
        ['key' => 'updates', 'icon' => 'bi-cloud-arrow-down', 'label' => 'Updates', 'href' => 'admin/updates.php', 'permission' => '*'],
    ]],
];

foreach ($nav as $i => $group) {
    $nav[$i]['items'] = array_values(array_filter($group['items'], static fn (array $item): bool => !isset($item['permission']) || can($item['permission'])));
}
$nav = array_values(array_filter($nav, static fn (array $group): bool => $group['items'] !== []));
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
                    <span class="e"><?= e(!empty($currentUser['role_name']) ? $currentUser['role_name'] : $currentUser['email']) ?></span>
                </span>
            </a>
            <form method="post" action="<?= e(base_url('logout.php')) ?>" class="ms-auto">
                <input type="hidden" name="_token" value="<?= e($csrfToken) ?>">
                <button type="submit" class="btn-icon btn-sm" aria-label="Sign out" data-bs-toggle="tooltip" data-bs-placement="right" title="Sign out"><i class="bi bi-box-arrow-right" aria-hidden="true"></i></button>
            </form>
        </div>
    </div>
</aside>
