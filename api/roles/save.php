<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Services\ServiceFactory;

Api::boot(['POST'], permission: 'roles.manage');

$actor = App::auth()->user();
$team = ServiceFactory::team();
$roles = ServiceFactory::roles();
$id = Request::int('id');

$existing = null;
if ($id > 0) {
    $existing = $roles->find($id);
    if ($existing === null) {
        Response::error('Role not found.', [], 404);
    }
    $error = $team->editRoleError($existing, $actor);
    if ($error !== null) {
        Response::error($error, [], 403);
    }
}

$validated = $team->validateRole(Request::all(), $actor, $existing);
if ($validated['errors'] !== []) {
    Response::error('Please correct the highlighted fields.', $validated['errors'], 422);
}

$role = $existing === null ? $team->createRole($validated['data']) : $team->updateRole($existing, $validated['data']);
$role['user_count'] = $roles->userCount((int) $role['id']);
$role['active_count'] = ServiceFactory::users()->countActiveWithRole((int) $role['id']);

Response::success(
    $existing === null ? 'Role created.' : 'Role updated. The change applies to its users on their next click.',
    ['role' => $team->presentRole($role, $actor)]
);
