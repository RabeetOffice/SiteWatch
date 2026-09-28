<?php

declare(strict_types=1);

/**
 * Compact list of every website for the command palette (Ctrl/Cmd + K): name, domain, client and
 * current severity. Filtering happens in the browser, so one request serves every keystroke.
 */

define('SW_API', true);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Core\Api;
use App\Core\Response;
use App\Monitoring\Status;
use App\Services\ServiceFactory;

Api::boot(['GET']);

$items = array_map(static fn (array $w): array => [
    'id'       => (int) $w['id'],
    'name'     => (string) $w['name'],
    'domain'   => (string) $w['domain'],
    'client'   => (string) ($w['client_name'] ?? ''),
    'severity' => (int) $w['monitoring_enabled'] === 1 ? Status::severity($w['status'] ?? null) : Status::SEVERITY_NEUTRAL,
], ServiceFactory::websites()->searchAll([]));

Response::success('', $items);
