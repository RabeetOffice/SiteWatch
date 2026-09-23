#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Apply pending database schema upgrades.
 *
 *   php database/migrate.php
 *
 * SiteWatch also applies them automatically on the first request after a deploy; run this over SSH to upgrade
 * explicitly (for example straight after uploading a new version).
 */

use App\Core\Migrator;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

define('SW_SKIP_MIGRATIONS', true);
require dirname(__DIR__) . '/bootstrap.php';

$migrator = Migrator::create();
$before = $migrator->currentVersion();
$applied = $migrator->migrate();

echo $applied === []
    ? "Database schema is up to date (version {$before})." . PHP_EOL
    : 'Applied schema version ' . implode(', ', $applied) . '. The database is now at version ' . Migrator::VERSION . ' (SiteWatch ' . Migrator::releaseFor(Migrator::VERSION) . ').' . PHP_EOL;
