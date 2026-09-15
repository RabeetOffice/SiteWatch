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

$team = ServiceFactory::team();
$role = ServiceFactory::roles()->find(Request::int('id'));
if ($role === null) {
    Response::error('Role not found.', [], 404);
}

$error = $team->deleteRoleError($role, App::auth()->user());
if ($error !== null) {
    Response::error($error, [], 422);
}

$team->deleteRole($role);

Response::success('Role deleted.', ['id' => (int) $role['id']]);
