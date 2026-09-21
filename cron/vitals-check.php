#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * SiteWatch Core Web Vitals and screenshot cron (recommended, hourly).
 *
 *   20 * * * * /usr/local/bin/php /path/to/sitewatch/cron/vitals-check.php >/dev/null 2>&1
 *
 * Runs Google PageSpeed Insights for every website whose Core Web Vitals are older than the
 * "Vitals re-check interval" setting, and captures screenshots for every website whose picture is older
 * than the screenshot interval. Both jobs are rate limited by their providers, so this runs a small batch
 * per invocation and pauses between websites; schedule it hourly and it works through the fleet steadily.
 *
 * Screenshots set to "every check" are captured by cron/monitor.php instead and are skipped here.
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

$lock = new Lock(rtrim((string) App::config()->get('app.paths.locks'), '/\\') . DIRECTORY_SEPARATOR . 'vitals-check.lock');
if (!$lock->acquire()) {
    echo '[' . gmdate('Y-m-d H:i:s') . ' UTC] Another vitals check is still running.' . PHP_EOL;
    exit(0);
}

$heartbeats = ServiceFactory::heartbeats();
$heartbeatId = $heartbeats->start('vitals-check');

// A Lighthouse run takes tens of seconds, so keep the batch small enough to finish inside an hour.
$vitalsBatch = max(1, (int) ($argv[1] ?? 12));
$shotBatch = max(1, (int) ($argv[2] ?? 40));

try {
    $performance = ServiceFactory::performance();
    $vitals = $performance->refreshStaleVitals($vitalsBatch);
    $shots = $performance->captureStaleScreenshots($shotBatch);

    $parts = [];
    $parts[] = $vitals['skipped']
        ? 'Core Web Vitals are switched off'
        : sprintf('%d website(s) analysed, %d with no usable result', $vitals['checked'], $vitals['failed']);
    $parts[] = $shots['skipped']
        ? 'screenshots are switched off or captured with the vitals run'
        : sprintf('%d screenshot(s) captured, %d failed', $shots['captured'], $shots['failed']);

    $message = 'Vitals check complete: ' . implode('; ', $parts) . '.';
    $heartbeats->finish($heartbeatId, 'completed', $vitals['checked'] + $shots['captured'], $vitals['failed'] + $shots['failed'], $message);
    echo '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    $heartbeats->finish($heartbeatId, 'failed', 0, 0, mb_substr($e->getMessage(), 0, 500));
    $log->error('vitals-check.php crashed', ['error' => $e->getMessage()]);
    fwrite(STDERR, '[' . gmdate('Y-m-d H:i:s') . ' UTC] ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
