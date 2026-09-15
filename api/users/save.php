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

$auth = App::auth();
$actor = $auth->user();
$team = ServiceFactory::team();
$id = Request::int('id');

$existing = null;
if ($id > 0) {
    $existing = ServiceFactory::users()->find($id);
    if ($existing === null) {
        Response::error('User not found.', [], 404);
    }
    if (!$team->canManageUser($existing, $actor)) {
        Response::error('You cannot change a user who has more access than you.', [], 403);
    }
}

$validated = $team->validateUser(Request::all(), $actor, $existing);
if ($validated['errors'] !== []) {
    Response::error('Please correct the highlighted fields.', $validated['errors'], 422);
}
$data = $validated['data'];

if ($existing === null) {
    $user = $team->createUser($data);
    $message = 'User added. Share the password with them securely — they can change it from their profile.';
} else {
    $user = $team->updateUser($existing, $data);
    if ((int) $existing['id'] === (int) $actor['id']) {
        // Changing your own password here keeps this browser signed in; other sessions end.
        $auth->syncSessionVersion();
    }
    $message = $data['password'] !== null ? 'User updated. The new password signed them out of every other session.' : 'User updated.';
}

Response::success($message, ['user' => $team->presentUser($user, $auth->user() ?? $actor)]);
