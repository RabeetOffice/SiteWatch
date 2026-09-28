<?php

declare(strict_types=1);

/**
 * Navigation map shared by the sidebar, the command palette and the keyboard shortcuts.
 *
 * Every page appears once. Pages that group several views (Performance, Team, Settings) show them as
 * tabs; see sw_page_tabs(). A 'permission' may be a single permission or a list, in which case any of
 * them is enough. Items the signed-in role cannot open are removed.
 */

if (!function_exists('sw_can_any')) {
    /**
     * @param string|list<string>|null $permission
     */
    function sw_can_any(string|array|null $permission): bool
    {
        if ($permission === null) {
            return true;
        }
        foreach ((array) $permission as $p) {
            if (can($p)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('sw_nav')) {
    /**
     * @return list<array{label: ?string, items: list<array<string, mixed>>}>
     */
    function sw_nav(): array
    {
        $nav = [
            ['label' => null, 'items' => [
                ['key' => 'dashboard', 'icon' => 'bi-grid-1x2', 'label' => 'Dashboard', 'href' => 'admin/dashboard.php', 'shortcut' => 'g d'],
            ]],
            ['label' => 'Monitoring', 'items' => [
                ['key' => 'websites', 'icon' => 'bi-globe2', 'label' => 'Websites', 'href' => 'admin/websites.php', 'shortcut' => 'g w'],
                ['key' => 'incidents', 'icon' => 'bi-exclamation-octagon', 'label' => 'Incidents', 'href' => 'admin/incidents.php', 'permission' => 'incidents.view', 'count' => 'incidents', 'shortcut' => 'g i'],
                ['key' => 'performance', 'icon' => 'bi-speedometer2', 'label' => 'Performance', 'href' => 'admin/performance.php', 'permission' => 'reports.view', 'shortcut' => 'g p'],
                ['key' => 'domains', 'icon' => 'bi-globe-americas', 'label' => 'Domains & Hosting', 'href' => 'admin/domains.php', 'permission' => 'domains.view', 'shortcut' => 'g h'],
            ]],
            ['label' => 'Reports', 'items' => [
                ['key' => 'reports', 'icon' => 'bi-bar-chart-line', 'label' => 'Uptime report', 'href' => 'admin/reports.php', 'permission' => 'reports.view', 'shortcut' => 'g r'],
            ]],
            ['label' => 'Admin', 'items' => [
                ['key' => 'team', 'icon' => 'bi-people', 'label' => 'Team', 'href' => 'admin/team.php', 'permission' => ['users.manage', 'roles.manage'], 'shortcut' => 'g t'],
                ['key' => 'settings', 'icon' => 'bi-gear', 'label' => 'Settings', 'href' => 'admin/settings.php', 'permission' => ['settings.manage', 'notifications.manage'], 'shortcut' => 'g s'],
                ['key' => 'activity', 'icon' => 'bi-clock-history', 'label' => 'Activity log', 'href' => 'admin/activity.php', 'permission' => 'activity.view', 'shortcut' => 'g a'],
            ]],
        ];

        foreach ($nav as $i => $group) {
            $nav[$i]['items'] = array_values(array_filter(
                $group['items'],
                static fn (array $item): bool => sw_can_any($item['permission'] ?? null)
            ));
        }
        return array_values(array_filter($nav, static fn (array $group): bool => $group['items'] !== []));
    }
}

if (!function_exists('sw_page_tabs')) {
    /**
     * Tabs of the grouped pages, filtered by permission.
     *
     * @return list<array{key: string, label: string, href: string, icon: string}>
     */
    function sw_page_tabs(string $page): array
    {
        $tabs = [
            'performance' => [
                ['key' => 'response', 'label' => 'Response time', 'icon' => 'bi-stopwatch', 'href' => 'admin/performance.php', 'permission' => 'reports.view'],
                ['key' => 'vitals', 'label' => 'Core Web Vitals', 'icon' => 'bi-lightning-charge', 'href' => 'admin/performance.php?tab=vitals', 'permission' => 'reports.view'],
                ['key' => 'countries', 'label' => 'Countries', 'icon' => 'bi-globe-europe-africa', 'href' => 'admin/performance.php?tab=countries', 'permission' => 'reports.view'],
            ],
            'team' => [
                ['key' => 'users', 'label' => 'Users', 'icon' => 'bi-person', 'href' => 'admin/team.php', 'permission' => 'users.manage'],
                ['key' => 'roles', 'label' => 'Roles & permissions', 'icon' => 'bi-shield-lock', 'href' => 'admin/team.php?tab=roles', 'permission' => 'roles.manage'],
            ],
            'settings' => [
                ['key' => 'general', 'label' => 'General', 'icon' => 'bi-sliders2', 'href' => 'admin/settings.php', 'permission' => 'settings.manage'],
                ['key' => 'monitoring', 'label' => 'Monitoring', 'icon' => 'bi-activity', 'href' => 'admin/settings.php?tab=monitoring', 'permission' => 'settings.manage'],
                ['key' => 'notifications', 'label' => 'Notifications', 'icon' => 'bi-bell', 'href' => 'admin/settings.php?tab=notifications', 'permission' => 'notifications.manage'],
            ],
        ];

        return array_values(array_map(
            static fn (array $t): array => ['key' => $t['key'], 'label' => $t['label'], 'href' => $t['href'], 'icon' => $t['icon']],
            array_filter($tabs[$page] ?? [], static fn (array $t): bool => sw_can_any($t['permission']))
        ));
    }
}

if (!function_exists('sw_resolve_tab')) {
    /**
     * The requested tab when the user may open it, otherwise the first one they can. Sends a 403 page
     * when none is available.
     *
     * @param list<array{key: string}> $tabs
     */
    function sw_resolve_tab(array $tabs, ?string $requested, string $deniedPermission = '*'): string
    {
        if ($tabs === []) {
            \App\Core\App::auth()->requireLogin();
            http_response_code(403);
            require __DIR__ . '/forbidden.php';
            exit;
        }
        foreach ($tabs as $tab) {
            if ($tab['key'] === $requested) {
                return $tab['key'];
            }
        }
        return $tabs[0]['key'];
    }
}

if (!function_exists('sw_redirect')) {
    /**
     * Permanent redirect for pages that moved in 2.0, keeping any extra query parameters.
     *
     * @param array<string, scalar> $params
     */
    function sw_redirect(string $path, array $params = []): never
    {
        $query = array_merge($_GET, $params);
        unset($query['section']);
        $qs = http_build_query(array_filter($query, static fn ($v): bool => $v !== '' && $v !== null));
        header('Location: ' . base_url($path) . ($qs !== '' ? (str_contains($path, '?') ? '&' : '?') . $qs : ''), true, 301);
        exit;
    }
}
