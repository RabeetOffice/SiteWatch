<?php

declare(strict_types=1);

/**
 * Settings: general, monitoring and notifications, one tab each (three sidebar entries in 1.x).
 */

require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/navigation.php';

use App\Core\App;

App::auth()->requireLogin();

// 1.x addresses: settings.php?section=monitoring.
if (($_GET['section'] ?? '') === 'monitoring') {
    sw_redirect('admin/settings.php', ['tab' => 'monitoring']);
}

$pageTabs = sw_page_tabs('settings');
$activeTab = sw_resolve_tab($pageTabs, $_GET['tab'] ?? null, 'settings.manage');
$s = App::settings()->allForDisplay();

$pageTitle = 'Settings';
$activeNav = 'settings';
$pageScripts = ['settings.js'];
$pageData = ['section' => $activeTab];
$pageSubtitle = [
    'general'       => 'Name, address, timezone and how this interface behaves.',
    'monitoring'    => 'How websites are checked, and what counts as slow or down.',
    'notifications' => 'Where alerts go and which events send one.',
][$activeTab];

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/pages/settings-' . $activeTab . '.php';
require dirname(__DIR__) . '/includes/footer.php';
