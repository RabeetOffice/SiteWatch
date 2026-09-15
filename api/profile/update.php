<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\ActivityService;
use App\Services\ServiceFactory;

Api::boot(['POST']);

$auth = App::auth();
$user = $auth->user();
$users = ServiceFactory::users();
$input = Request::all();
$action = Request::string('action', 'profile');

if ($action === 'password') {
    $v = new Validator($input);
    $v->required('current_password', 'Current password');
    $v->required('new_password', 'New password')->min('new_password', 10, 'New password')->max('new_password', 200, 'New password');
    $v->required('new_password_confirmation', 'Password confirmation');
    if (($input['new_password'] ?? '') !== ($input['new_password_confirmation'] ?? '')) {
        $v->addError('new_password_confirmation', 'The confirmation does not match the new password.');
    }
    if (!$v->fails() && !password_verify((string) $input['current_password'], (string) $user['password_hash'])) {
        $v->addError('current_password', 'The current password is incorrect.');
    }
    if (!$v->fails() && strtolower((string) $input['new_password']) === strtolower((string) $user['email'])) {
        $v->addError('new_password', 'The password must not be your email address.');
    }
    if ($v->fails()) {
        Response::error('Please correct the highlighted fields.', $v->errors(), 422);
    }
    $users->updatePassword((int) $user['id'], (string) $input['new_password']);
    App::session()->regenerate();
    // The new password ends every other session; keep this one.
    $auth->syncSessionVersion();
    ActivityService::log('password.changed', sprintf('%s changed their password', $user['name']));
    Response::success('Password updated.');
}

$v = new Validator($input);
$v->required('name', 'Name')->max('name', 100, 'Name');
$v->required('email', 'Email')->email('email', 'Email')->max('email', 190, 'Email');
if (!$v->fails()) {
    $existing = $users->findByEmail((string) $input['email']);
    if ($existing !== null && (int) $existing['id'] !== (int) $user['id']) {
        $v->addError('email', 'This email address is already used by another account.');
    }
}
if ($v->fails()) {
    Response::error('Please correct the highlighted fields.', $v->errors(), 422);
}

$users->updateProfile((int) $user['id'], (string) $input['name'], (string) $input['email']);
$auth->refreshUser();
ActivityService::log('profile.updated', sprintf('%s updated their profile', trim((string) $input['name'])));

Response::success('Profile saved.', ['reload' => true]);
