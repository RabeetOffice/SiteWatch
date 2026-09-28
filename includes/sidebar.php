<?php

declare(strict_types=1);

/**
 * Sidebar navigation. Uses $navGroups, $activeNav, $currentUser, $csrfToken and $openIncidents, all
 * prepared by header.php. The map itself lives in includes/navigation.php.
 */

// Pages that belong to a sidebar entry without being one themselves.
$navAliases = [
    'website-add' => 'websites', 'website-edit' => 'websites', 'website-import' => 'websites', 'website-details' => 'websites',
    'response-times' => 'performance', 'web-vitals' => 'performance',
    'users' => 'team', 'roles' => 'team',
    'notifications' => 'settings', 'monitoring-settings' => 'settings',
];
$navActive = $navAliases[$activeNav] ?? $activeNav;
?>
<aside class="sw-sidebar" id="sidebar" aria-label="Main navigation">
    <a class="sw-brand" href="<?= e(base_url('admin/dashboard.php')) ?>">
        <?= sw_brand_logo(150) ?>
        <?= sw_brand_mark(28) ?>
    </a>
    <nav class="sw-nav">
        <?php foreach ($navGroups as $group): ?>
            <div class="sw-nav-group"<?= $group['label'] !== null ? ' role="group" aria-label="' . e($group['label']) . '"' : '' ?>>
                <?php if ($group['label'] !== null): ?><div class="sw-nav-label" aria-hidden="true"><?= e($group['label']) ?></div><?php endif; ?>
                <?php foreach ($group['items'] as $item): ?>
                    <a class="sw-nav-link<?= $navActive === $item['key'] ? ' active' : '' ?>" href="<?= e(base_url($item['href'])) ?>" data-nav="<?= e($item['key']) ?>"
                       <?= $navActive === $item['key'] ? 'aria-current="page"' : '' ?>
                       data-bs-toggle="tooltip" data-bs-placement="right" title="<?= e($item['label']) ?>">
                        <i class="bi <?= e($item['icon']) ?>" aria-hidden="true"></i><span><?= e($item['label']) ?></span>
                        <?php if (($item['count'] ?? null) === 'incidents'): ?>
                            <span class="sw-nav-count" id="navIncidentCount"><?= $openIncidents > 0 ? (int) $openIncidents : '' ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </nav>
    <div class="sw-sidebar-footer">
        <?php $swVersion = 'v' . (string) config('app.version', ''); ?>
        <?php if (can('*')): ?>
            <a class="sw-version<?= $activeNav === 'updates' ? ' active' : '' ?>" href="<?= e(base_url('admin/updates.php')) ?>" title="Release notes and database version"><i class="bi bi-tag" aria-hidden="true"></i><span>SiteWatch <?= e($swVersion) ?></span></a>
        <?php else: ?>
            <span class="sw-version"><i class="bi bi-tag" aria-hidden="true"></i><span>SiteWatch <?= e($swVersion) ?></span></span>
        <?php endif; ?>
        <a class="sw-user<?= $activeNav === 'profile' ? ' active' : '' ?>" href="<?= e(base_url('admin/profile.php')) ?>" data-nav="profile"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Profile">
            <?= user_avatar($currentUser) ?>
            <span class="sw-user-text">
                <span class="n"><?= e($currentUser['name']) ?></span>
                <span class="e"><?= e(!empty($currentUser['role_name']) ? $currentUser['role_name'] : $currentUser['email']) ?></span>
            </span>
        </a>
    </div>
</aside>
