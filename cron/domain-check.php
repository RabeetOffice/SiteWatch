#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * SiteWatch domain & hosting cron (recommended, daily).
 *
 *   45 4 * * * /usr/local/bin/php /path/to/sitewatch/cron/domain-check.php >/dev/null 2>&1
 *
 * Refreshes registration details (domain age, expiry, registrar via RDAP/WHOIS) and hosting details for every
 * website whose data is older than the "Domain re-check interval" monitoring setting. Website details pages
 * also fill in missing data on demand, so this job keeps everything current in the background.
 */

use App\Core\App;
use App\Core\Lock;
use App\Services\ServiceFactory;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require dirname(__DIR__) . '/bootstrap.php';

if (App::schemaPending()) {
    echo '[' . gmdate('Y-m-d H:i:s') . ' UTC] Skipped: a database update is waiting. Apply it under System → Updates or run php database/migrate.php.' . PHP_EOL;
    exit(0);
}

set_time_limit(0);
$log = App::logger('cron');

$lock = new Lock(rtrim((string) App::config()->get('app.paths.locks'), '/\\') . DIRECTORY_SEPARATOR . 'domain-check.lock');
if (!$lock->acquire()) {
    echo '[' . gmdate('Y-m-d H:i:s') . ' UTC] Another domain check is still running.' . PHP_EOL;
    exit(0);
}

$heartbeats = ServiceFactory::heartbeats();
$heartbeatId = $heartbeats->start('domain-check');

try {
    $result = ServiceFactory::domainService()->refreshStale();
    $message = sprintf('Domain check complete: %d website(s) refreshed, %d with lookup problems.', $result['checked'], $result['failed']);
    $heartbeats->finish($heartbeatId, 'completed', $result['checked'], $result['failed'], $message);
    echo '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    $heartbeats->finish($heartbeatId, 'failed', 0, 0, mb_substr($e->getMessage(), 0, 500));
    $log->error('domain-check.php crashed', ['error' => $e->getMessage()]);
    fwrite(STDERR, '[' . gmdate('Y-m-d H:i:s') . ' UTC] ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
