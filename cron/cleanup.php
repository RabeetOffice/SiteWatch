#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * SiteWatch data retention cron.
 *
 * Recommended schedule (daily, e.g. 03:15):
 *   15 3 * * * /usr/local/bin/php /path/to/sitewatch/cron/cleanup.php >/dev/null 2>&1
 *
 * Removes detailed checks, activity entries and notification logs older than the configured
 * retention periods using batched deletes. Incidents and daily statistics are kept indefinitely.
 * (If this cron is not configured, monitor.php runs the same cleanup automatically once a day.)
 */

use App\Core\App;
use App\Monitoring\MonitorManager;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require dirname(__DIR__) . '/bootstrap.php';

set_time_limit(0);
$log = App::logger('cron');

try {
    $result = MonitorManager::create()->maintenance()->cleanup();
    echo sprintf(
        '[%s UTC] Cleanup complete: %d checks, %d activity entries, %d notifications, %d heartbeats removed in %s.%s',
        gmdate('Y-m-d H:i:s'),
        $result['checks'],
        $result['activity'],
        $result['notifications'],
        $result['heartbeats'],
        format_ms($result['duration_ms']),
        PHP_EOL
    );
    exit(0);
} catch (Throwable $e) {
    $log->error('cleanup.php crashed', ['error' => $e->getMessage()]);
    fwrite(STDERR, '[' . gmdate('Y-m-d H:i:s') . ' UTC] ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
