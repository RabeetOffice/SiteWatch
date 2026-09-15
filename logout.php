<?php

declare(strict_types=1);

define('SW_ALLOW_PENDING_SCHEMA', true);
require __DIR__ . '/bootstrap.php';

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityService;

$auth = App::auth();

// Logout is a state change: require POST + CSRF so a third-party page cannot sign the admin out.
if (!Request::isPost() || !App::csrf()->validateRequest()) {
    Response::redirect($auth->check() ? base_url('admin/dashboard.php') : base_url('login.php'));
}

if ($auth->check()) {
    ActivityService::log('auth.logout', sprintf('%s signed out', $auth->user()['name'] ?? 'Administrator'));
    $auth->logout();
}

Response::redirect(base_url('login.php'));
