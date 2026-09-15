<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Services\ServiceFactory;

Api::boot(['POST'], permission: 'users.manage');

$team = ServiceFactory::team();
$user = ServiceFactory::users()->find(Request::int('id'));
if ($user === null) {
    Response::error('User not found.', [], 404);
}

$error = $team->deleteUserError($user, App::auth()->user());
if ($error !== null) {
    Response::error($error, [], 422);
}

$team->deleteUser($user);

Response::success('User deleted.', ['id' => (int) $user['id']]);
