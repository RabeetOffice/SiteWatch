<?php

declare(strict_types=1);

define('SW_API', true);
define('SW_ALLOW_PENDING_SCHEMA', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\App;
use App\Core\Migrator;
use App\Core\Permission;
use App\Core\Response;
use App\Services\ActivityService;

/*
 * Apply pending database structure updates (System → Updates). Administrators only.
 */
Api::boot(['POST'], permission: Permission::ALL);

set_time_limit(300);
$migrator = Migrator::create();
$before = $migrator->currentVersion();

try {
    $applied = $migrator->migrate();
} catch (Throwable $e) {
    App::logger('app')->error('Database update failed', ['from' => $before, 'error' => $e->getMessage()]);
    Response::error('The database update stopped: ' . $e->getMessage() . ' Steps that already finished are kept, so you can run the update again after fixing the cause.', [], 500);
}

App::settings()->reload();

if ($applied === []) {
    Response::success('The database was already up to date.', ['version' => $before, 'applied' => []]);
}

ActivityService::log('system.updated', sprintf('Database updated from version %d to %d', $before, Migrator::VERSION), null, [
    'versions' => implode(', ', $applied),
]);

Response::success('Database updated to version ' . Migrator::VERSION . '.', ['version' => Migrator::VERSION, 'applied' => $applied]);
