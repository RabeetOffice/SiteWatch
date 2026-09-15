<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Response;
use App\Services\ServiceFactory;

Api::boot(['GET'], permission: 'users.manage');

$actor = App::auth()->user();
$team = ServiceFactory::team();

Response::success('', [
    'users' => array_map(static fn (array $user): array => $team->presentUser($user, $actor), ServiceFactory::users()->all()),
    'roles' => array_map(static fn (array $role): array => $team->presentRole($role, $actor), ServiceFactory::roles()->allWithCounts()),
]);
