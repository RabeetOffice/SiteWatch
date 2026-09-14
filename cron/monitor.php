#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * SiteWatch monitoring cron.
 *
 * Recommended schedule (every minute):
 *   * * * * * /usr/local/bin/php /path/to/sitewatch/cron/monitor.php >/dev/null 2>&1
 *
 * The application decides which websites are due based on their individual intervals.
 * Overlapping runs are prevented with a file lock.
 */

use App\Core\App;
use App\Monitoring\MonitorManager;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require dirname(__DIR__) . '/bootstrap.php';

set_time_limit(0);
ignore_user_abort(true);

$log = App::logger('cron');
$started = microtime(true);

try {
    $manager = MonitorManager::create();
    $summary = $manager->run();
    $elapsed = round((microtime(true) - $started) * 1000);
    echo '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . ($summary['skipped'] ? 'SKIPPED: ' : '') . $summary['message'] . PHP_EOL;
    $log->info('monitor.php finished', ['elapsed_ms' => $elapsed, 'skipped' => $summary['skipped']]);
    exit(0);
} catch (Throwable $e) {
    $log->error('monitor.php crashed', ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    fwrite(STDERR, '[' . gmdate('Y-m-d H:i:s') . ' UTC] ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
