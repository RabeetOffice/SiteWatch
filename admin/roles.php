<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Permission;

require_permission('roles.manage');

$pageTitle = 'Roles & Permissions';
$pageSubtitle = 'Decide what each group of people can see and change.';
$activeNav = 'roles';
$pageScripts = ['roles.js'];
$pageData = ['totalPermissions' => count(Permission::keys())];
$headerActions = (can('users.manage') ? '<a class="btn btn-light" href="' . e(base_url('admin/users.php')) . '"><i class="bi bi-people"></i>Users</a>' : '')
    . '<button type="button" class="btn btn-primary" id="btnAddRole"><i class="bi bi-plus-lg"></i>Create role</button>';

require dirname(__DIR__) . '/includes/header.php';
?>

<div class="coverage-note mb-4" role="note">
    <i class="bi bi-info-circle" aria-hidden="true"></i>
    <div>
        <strong>What every signed-in user can already do</strong>
        Open the dashboard, the website list and each website's details, and edit their own profile. A role adds everything else.
        Role changes apply to its users on their next click.
    </div>
</div>

<section class="sw-card mb-4" aria-labelledby="rolesHeading">
    <div class="sw-card-header">
        <div><h3 id="rolesHeading">Roles</h3><p class="sub" id="rolesSummary">Loading…</p></div>
    </div>
    <div class="sw-table-wrap">
        <table class="sw-table">
            <thead>
                <tr>
                    <th scope="col">Role</th>
                    <th scope="col">Access</th>
                    <th scope="col" class="num">Users</th>
                    <th scope="col" class="hide-mobile">Updated</th>
                    <th scope="col" class="actions"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody id="rolesBody"></tbody>
        </table>
    </div>
</section>

<section class="sw-card" aria-labelledby="matrixHeading">
    <div class="sw-card-header">
        <div><h3 id="matrixHeading">Permission matrix</h3><p class="sub">What each role can do, side by side</p></div>
    </div>
    <div class="sw-table-wrap">
        <table class="sw-table compact perm-matrix" id="permMatrix"></table>
    </div>
</section>

<div class="modal fade" id="roleModal" tabindex="-1" aria-labelledby="roleModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content" id="roleForm" novalidate autocomplete="off">
            <div class="modal-header">
                <h2 class="modal-title" id="roleModalTitle">Create role</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id" value="">
                <div class="row g-3 mb-4">
                    <div class="col-md-5">
                        <label class="form-label" for="r-name">Role name</label>
                        <input type="text" class="form-control" id="r-name" name="name" maxlength="60" required placeholder="e.g. Account manager">
                    </div>
                    <div class="col-md-7">
                        <label class="form-label" for="r-description">Description</label>
                        <input type="text" class="form-control" id="r-description" name="description" maxlength="255" placeholder="Who this role is for">
                    </div>
                </div>
                <fieldset class="sw-fieldset mb-0">
                    <legend>Permissions</legend>
                    <p class="desc">You can only grant permissions you have yourself.</p>
                    <input type="hidden" name="permissions" value="">
                    <div id="rolePermissions"></div>
                </fieldset>
            </div>
            <div class="modal-footer">
                <span class="fs-13 text-muted me-auto" id="rolePermissionCount"></span>
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i> <span data-submit-label>Create role</span></button>
            </div>
        </form>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
