<?php

declare(strict_types=1);

/**
 * Profile pictures.
 *
 *   GET  ?u=<user id>             the picture (any signed-in user; the Users list and top bar show them)
 *   POST action=upload, avatar=<file>   replace the signed-in user's own picture
 *   POST action=remove                   remove it
 *
 * Pictures live under storage/, which the web server denies, so GET is the only way to read one. That response is
 * an image, not JSON, so it does not go through Response on success.
 */

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityService;
use App\Services\AvatarService;
use App\Services\ServiceFactory;

Api::boot(['GET', 'POST']);

$avatars = AvatarService::create();

if (Request::method() !== 'POST') {
    App::session()->release();
    $user = ServiceFactory::users()->find(Request::int('u'));
    $path = $avatars->resolve($user['avatar'] ?? null);
    if ($user === null || $path === null) {
        Response::error('No profile picture.', [], 404);
    }
    $etag = '"' . sha1((string) $user['avatar']) . '"';
    // Per-account data: private caching only. The URL changes with every new picture, so it can be cached long.
    header('Content-Type: ' . (str_ends_with($path, '.webp') ? 'image/webp' : 'image/png'));
    header('Cache-Control: private, max-age=604800');
    header('X-Content-Type-Options: nosniff');
    header('ETag: ' . $etag);
    if (str_contains(trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')), $etag)) {
        http_response_code(304);
        exit;
    }
    header('Content-Length: ' . (string) filesize($path));
    if (Request::method() === 'HEAD') {
        exit;
    }
    readfile($path);
    exit;
}

$auth = App::auth();
$user = $auth->user();
$action = Request::string('action');
$current = isset($user['avatar']) && $user['avatar'] !== '' ? (string) $user['avatar'] : null;

if ($action === 'remove') {
    $avatars->remove((int) $user['id'], $current);
    $auth->refreshUser();
    ActivityService::log('profile.updated', sprintf('%s removed their profile picture', $user['name']));
    Response::success('Profile picture removed.', ['avatar_url' => null]);
}

if ($action !== 'upload') {
    Response::error('Unknown action.', ['action' => 'Use upload or remove.'], 422);
}

$file = $_FILES['avatar'] ?? null;
if (!is_array($file) || is_array($file['error'] ?? null)) {
    Response::error('Choose a picture to upload.', ['avatar' => 'No file received.'], 422);
}
if ((int) $file['error'] !== UPLOAD_ERR_OK) {
    $message = match ((int) $file['error']) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The picture is larger than this server accepts.',
        UPLOAD_ERR_NO_FILE => 'Choose a picture to upload.',
        default => 'The upload did not complete. Please try again.',
    };
    Response::error($message, ['avatar' => $message], 422);
}
if (!is_uploaded_file((string) $file['tmp_name'])) {
    Response::error('The upload did not complete. Please try again.', [], 422);
}

try {
    $avatars->store((int) $user['id'], (string) $file['tmp_name'], $current);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), ['avatar' => $e->getMessage()], 422);
}

$auth->refreshUser();
$fresh = $auth->user();
ActivityService::log('profile.updated', sprintf('%s changed their profile picture', $user['name']));
Response::success('Profile picture updated.', ['avatar_url' => AvatarService::url($fresh ?? [])]);
