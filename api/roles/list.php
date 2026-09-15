<?php

declare(strict_types=1);

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Response;
use App\Services\ServiceFactory;
use App\Services\TeamService;

Api::boot(['GET'], permission: 'roles.manage');

$actor = App::auth()->user();
$team = ServiceFactory::team();

Response::success('', [
    'roles'     => array_map(static fn (array $role): array => $team->presentRole($role, $actor), ServiceFactory::roles()->allWithCounts()),
    'catalogue' => TeamService::permissionCatalogue(),
]);
