#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * SiteWatch SSL certificate cron (optional).
 *
 * Recommended schedule (daily):
 *   30 4 * * * /usr/local/bin/php /path/to/sitewatch/cron/ssl-check.php >/dev/null 2>&1
 *
 * Inspects the certificate of every monitored HTTPS website and raises expiry alerts
 * (30 / 14 / 7 days, expired). monitor.php also refreshes stale certificate data gradually,
 * so this cron simply guarantees a full daily pass.
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
    $result = MonitorManager::create()->runSslChecks();
    echo sprintf('[%s UTC] SSL check complete: %d website(s) inspected, %d warning(s).%s', gmdate('Y-m-d H:i:s'), $result['checked'], $result['warnings'], PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    $log->error('ssl-check.php crashed', ['error' => $e->getMessage()]);
    fwrite(STDERR, '[' . gmdate('Y-m-d H:i:s') . ' UTC] ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
