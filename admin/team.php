<?php

declare(strict_types=1);

/**
 * Team: users and roles, one tab each (the separate Users and Roles & Permissions pages of 1.x).
 */

require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/navigation.php';

use App\Core\App;
use App\Core\Permission;

App::auth()->requireLogin();
$pageTabs = sw_page_tabs('team');
$activeTab = sw_resolve_tab($pageTabs, $_GET['tab'] ?? null, 'users.manage');

$pageTitle = 'Team';
$activeNav = 'team';

if ($activeTab === 'users') {
    $pageSubtitle = 'Everyone who can sign in, and the role that decides what they can do.';
    $pageScripts = ['users.js'];
    $pageData = ['roleFilter' => (int) ($_GET['role'] ?? 0)];
    $headerActions = '<button type="button" class="btn btn-primary" id="btnAddUser"><i class="bi bi-person-plus" aria-hidden="true"></i>Add user</button>';
} else {
    $pageSubtitle = 'Decide what each group of people can see and change.';
    $pageScripts = ['roles.js'];
    $pageData = ['totalPermissions' => count(Permission::keys())];
    $headerActions = '<button type="button" class="btn btn-primary" id="btnAddRole"><i class="bi bi-plus-lg" aria-hidden="true"></i>Create role</button>';
}

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/pages/team-' . $activeTab . '.php';
require dirname(__DIR__) . '/includes/footer.php';
