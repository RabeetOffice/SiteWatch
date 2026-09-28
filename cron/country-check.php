#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * SiteWatch country availability cron (recommended, hourly).
 *
 *   20 * * * * /usr/local/bin/php /path/to/sitewatch/cron/country-check.php >/dev/null 2>&1
 *
 * Checks websites whose country results are older than the "Country re-check interval" setting from test
 * servers in the configured countries. Each run stays within about four minutes and inside the free hourly
 * allowance of Globalping, so running it every hour spreads the daily sweep over the day.
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

$lock = new Lock(rtrim((string) App::config()->get('app.paths.locks'), '/\\') . DIRECTORY_SEPARATOR . 'country-check.lock');
if (!$lock->acquire()) {
    echo '[' . gmdate('Y-m-d H:i:s') . ' UTC] Another country check is still running.' . PHP_EOL;
    exit(0);
}

$heartbeats = ServiceFactory::heartbeats();
$heartbeatId = $heartbeats->start('country-check');

try {
    $result = ServiceFactory::countryChecks()->sweep(240);
    $message = sprintf('Country check: %d website(s) checked with %d test(s).', $result['checked'], $result['tests'])
        . ($result['stopped'] !== null ? ' ' . $result['stopped'] : '');
    $heartbeats->finish($heartbeatId, 'completed', $result['checked'], 0, $message);
    echo '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    $heartbeats->finish($heartbeatId, 'failed', 0, 0, mb_substr($e->getMessage(), 0, 500));
    $log->error('country-check.php crashed', ['error' => $e->getMessage()]);
    fwrite(STDERR, '[' . gmdate('Y-m-d H:i:s') . ' UTC] ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
