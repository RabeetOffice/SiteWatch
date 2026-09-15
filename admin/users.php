<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

require_permission('users.manage');

$pageTitle = 'Users';
$pageSubtitle = 'Everyone who can sign in to SiteWatch, and the role that decides what they can do.';
$activeNav = 'users';
$pageScripts = ['users.js'];
$pageData = ['roleFilter' => (int) ($_GET['role'] ?? 0)];
$headerActions = (can('roles.manage') ? '<a class="btn btn-light" href="' . e(base_url('admin/roles.php')) . '"><i class="bi bi-shield-lock"></i>Roles &amp; permissions</a>' : '')
    . '<button type="button" class="btn btn-primary" id="btnAddUser"><i class="bi bi-person-plus"></i>Add user</button>';

require dirname(__DIR__) . '/includes/header.php';
?>

<div class="sw-card" id="usersPage">
    <div class="sw-toolbar">
        <div class="search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" class="form-control form-control-sm" id="userSearch" placeholder="Search name or email…" aria-label="Search users">
        </div>
        <select class="form-select form-select-sm" id="userRoleFilter" aria-label="Filter by role"><option value="">All roles</option></select>
        <div class="segmented" id="userStatusFilter" role="group" aria-label="Filter by status">
            <button type="button" data-status="" class="active">All</button>
            <button type="button" data-status="active">Active</button>
            <button type="button" data-status="inactive">Deactivated</button>
        </div>
        <div class="spacer"></div>
        <span class="fs-13 text-muted" id="usersSummary"></span>
    </div>
    <div class="sw-table-wrap">
        <table class="sw-table">
            <thead>
                <tr>
                    <th scope="col">User</th>
                    <th scope="col">Role</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="hide-mobile">Last sign-in</th>
                    <th scope="col" class="hide-mobile">Added</th>
                    <th scope="col" class="actions"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody id="usersBody"></tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="userModal" tabindex="-1" aria-labelledby="userModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="userForm" novalidate autocomplete="off">
            <div class="modal-header">
                <h2 class="modal-title" id="userModalTitle">Add user</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id" value="">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="u-name">Full name</label>
                        <input type="text" class="form-control" id="u-name" name="name" maxlength="100" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="u-email">Email address</label>
                        <input type="email" class="form-control" id="u-email" name="email" maxlength="190" required>
                        <div class="form-text">They sign in with this address.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="u-role">Role</label>
                        <select class="form-select" id="u-role" name="role_id" required></select>
                        <div class="form-text" id="u-role-help"></div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="u-password" id="u-password-label">Password</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="u-password" name="password" minlength="10" maxlength="200" autocomplete="new-password" aria-describedby="u-password-help">
                            <button type="button" class="btn btn-light" id="u-password-toggle" aria-label="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button>
                            <button type="button" class="btn btn-light" id="u-password-generate"><i class="bi bi-magic" aria-hidden="true"></i> Generate</button>
                        </div>
                        <div class="form-text" id="u-password-help">At least 10 characters. Share it with the user securely — they can change it from their profile.</div>
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="u-active" name="is_active" value="1" checked>
                            <label class="form-check-label" for="u-active">Account active</label>
                        </div>
                        <div class="form-text">Deactivated users cannot sign in and are signed out straight away.</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i> <span data-submit-label>Add user</span></button>
            </div>
        </form>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
