<?php

declare(strict_types=1);

/**
 * Incident lifecycle integration test (uses the configured database).
 *
 *   php tests/lifecycle.php
 *
 * Creates a temporary website pointing at the fixture server, drives it through
 * failure confirmation -> incident -> recovery, and verifies counters, incident rows,
 * notification deduplication, uptime accounting and pause/resume behaviour.
 */

use App\Core\App;
use App\Monitoring\MonitorManager;
use App\Monitoring\SsrfGuard;
use App\Monitoring\Status;
use App\Repositories\ActivityRepository;
use App\Repositories\CheckRepository;
use App\Repositories\DailyStatsRepository;
use App\Repositories\IncidentRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\WebsiteRepository;
use App\Services\WebsiteService;
use Tests\Fixtures\FixtureServer;

// The fixture server lives on 127.0.0.1, so private targets must be allowed for this test only.
putenv('MONITOR_ALLOW_PRIVATE=true');
$_ENV['MONITOR_ALLOW_PRIVATE'] = 'true';
$_SERVER['MONITOR_ALLOW_PRIVATE'] = 'true';

require dirname(__DIR__) . '/bootstrap.php';

$server = new FixtureServer();
$server->start();
$base = $server->baseUrl();

$db = App::db();
$websites = new WebsiteRepository($db);
$checks = new CheckRepository($db);
$incidents = new IncidentRepository($db);
$daily = new DailyStatsRepository($db);
$notifications = new NotificationRepository($db);
$service = new WebsiteService($websites, new ActivityRepository($db), App::settings(), new SsrfGuard(true));
$manager = MonitorManager::create();

$pass = 0;
$fail = 0;
$assert = static function (bool $condition, string $message, string $detail = '') use (&$pass, &$fail): void {
    $condition ? $pass++ : $fail++;
    printf("  [%s] %s%s\n", $condition ? 'PASS' : 'FAIL', $message, $detail !== '' ? " ({$detail})" : '');
};

// Clean up leftovers from previous runs.
$existing = $websites->findByUrl($base . '/dynamic');
if ($existing !== null) {
    $websites->delete((int) $existing['id']);
}

echo "\nIncident lifecycle\n";
$validated = $service->validate(['name' => 'Lifecycle Test', 'client_name' => 'QA', 'url' => $base . '/dynamic', 'type' => 'wordpress', 'check_interval' => 1], null, false);
$assert($validated['errors'] === [], 'Website validates', json_encode($validated['errors']));
$website = $service->create($validated['data']);
$id = (int) $website['id'];
$assert($website['status'] === Status::PENDING, 'New website starts as PENDING');

$server->setMode('500');
$r1 = $manager->checkNow($websites->find($id));
$assert($r1['result']->status === Status::HTTP_500 && $r1['website']['status'] === Status::SUSPECTED_DOWN, 'Failure #1 -> SUSPECTED_DOWN', $r1['website']['status']);
$assert((int) $r1['website']['failure_count'] === 1 && $r1['incident_opened'] === null, 'No incident after one failure');

$r2 = $manager->checkNow($websites->find($id));
$assert($r2['website']['status'] === Status::SUSPECTED_DOWN && (int) $r2['website']['failure_count'] === 2 && $r2['incident_opened'] === null, 'Failure #2 -> still suspected, no incident');

$r3 = $manager->checkNow($websites->find($id));
$assert($r3['website']['status'] === Status::HTTP_500 && $r3['incident_opened'] !== null, 'Failure #3 -> confirmed HTTP_500, incident opened', (string) $r3['website']['status']);
$incident = $incidents->openFor($id);
$assert($incident !== null && $incident['type'] === 'HTTP_ERROR' && (int) $incident['http_status'] === 500, 'Incident has type HTTP_ERROR and HTTP 500');
$firstCheck = $db->fetch('SELECT checked_at FROM website_checks WHERE website_id = :id ORDER BY id ASC LIMIT 1', ['id' => $id]);
$assert($incident !== null && $incident['started_at'] === $firstCheck['checked_at'], 'Incident started_at equals the first failed check', ($incident['started_at'] ?? '') . ' vs ' . ($firstCheck['checked_at'] ?? ''));
$downChecks = (int) $db->fetchColumn('SELECT COUNT(*) FROM website_checks WHERE website_id = :id AND is_up = 0', ['id' => $id]);
$assert($downChecks === 3, 'All three failures retro-actively counted as downtime', "is_up=0 rows: {$downChecks}");
$notifCount = (int) $db->fetchColumn("SELECT COUNT(*) FROM notifications WHERE website_id = :id AND event = 'down'", ['id' => $id]);
$assert($notifCount === 1, 'Exactly one down notification attempt logged', "rows: {$notifCount}");

$r4 = $manager->checkNow($websites->find($id));
$stillOpen = $incidents->openFor($id);
$notifCount2 = (int) $db->fetchColumn("SELECT COUNT(*) FROM notifications WHERE website_id = :id AND event = 'down'", ['id' => $id]);
$assert($r4['incident_opened'] === null && $stillOpen !== null && (int) $stillOpen['id'] === (int) $incident['id'], 'Failure #4 keeps the same incident open');
$assert($notifCount2 === 1, 'No duplicate down notification on repeated failures', "rows: {$notifCount2}");

$server->setMode('critical');
$r5 = $manager->checkNow($websites->find($id));
$updated = $incidents->openFor($id);
$assert($r5['website']['status'] === Status::CRITICAL_ERROR && $updated !== null && str_contains((string) $updated['error_message'], 'critical'), 'Root cause changes to CRITICAL_ERROR during the incident', (string) $r5['website']['status']);

$server->setMode('ok');
$r6 = $manager->checkNow($websites->find($id));
$assert($r6['result']->status === Status::ONLINE && $r6['website']['status'] === Status::CRITICAL_ERROR && (int) $r6['website']['success_count'] === 1 && $r6['incident_resolved'] === null, 'Success #1 -> pending recovery, status unchanged', (string) $r6['website']['status']);

$r7 = $manager->checkNow($websites->find($id));
$assert($r7['website']['status'] === Status::ONLINE && $r7['incident_resolved'] !== null, 'Success #2 -> ONLINE, incident resolved');
$resolved = $incidents->find((int) $incident['id']);
$assert($resolved !== null && $resolved['status'] === 'RESOLVED' && $resolved['resolved_at'] !== null && (int) $resolved['duration_seconds'] >= 0, 'Incident marked RESOLVED with duration');
$recoveryNotif = (int) $db->fetchColumn("SELECT COUNT(*) FROM notifications WHERE website_id = :id AND event = 'recovery'", ['id' => $id]);
$assert($recoveryNotif === 1, 'Exactly one recovery notification attempt logged', "rows: {$recoveryNotif}");
$assert($resolved !== null && $resolved['recovery_notified_at'] === null && $resolved['notified_at'] === null, 'notified_at stays NULL when no channel is enabled (skipped)');

$stats = $checks->windowStats($id, utc_now()->modify('-1 hour')->format('Y-m-d H:i:s'));
$assert($stats['total'] === 7 && $stats['down'] === 5 && $stats['up'] === 2, 'Uptime accounting: 7 checks, 5 down, 2 up', json_encode($stats));
$today = to_local(utc_now()->format('Y-m-d H:i:s'))->format('Y-m-d');
$dailyRow = $db->fetch('SELECT * FROM daily_stats WHERE website_id = :id AND stat_date = :d', ['id' => $id, 'd' => $today]);
$assert($dailyRow !== null && (int) $dailyRow['total_checks'] === 7 && (int) $dailyRow['failed_checks'] === 5 && (int) $dailyRow['incident_count'] === 1, 'daily_stats rebuilt (7 checks, 5 failed, 1 incident)', json_encode($dailyRow ? array_intersect_key($dailyRow, array_flip(['total_checks', 'failed_checks', 'incident_count', 'uptime_percentage'])) : null));

echo "\nSingle blip does not create an incident\n";
$server->setMode('500');
$b1 = $manager->checkNow($websites->find($id));
$server->setMode('ok');
$b2 = $manager->checkNow($websites->find($id));
$assert($b1['website']['status'] === Status::SUSPECTED_DOWN && $b2['website']['status'] === Status::ONLINE && $b1['incident_opened'] === null && $b2['incident_opened'] === null, 'Temporary one-check failure: suspected then online, no incident');
$blipUp = (int) $db->fetchColumn('SELECT is_up FROM website_checks WHERE id = :cid', ['cid' => $b1['result']->checkedAt ? $db->fetchColumn('SELECT id FROM website_checks WHERE website_id = :id AND is_failure = 1 ORDER BY id DESC LIMIT 1', ['id' => $id]) : 0]);
$assert($blipUp === 1, 'Unconfirmed blip is not counted as downtime');
$openCount = (int) $db->fetchColumn("SELECT COUNT(*) FROM incidents WHERE website_id = :id AND status = 'OPEN'", ['id' => $id]);
$assert($openCount === 0, 'No open incidents remain');

echo "\nPause / resume\n";
$paused = $service->pause($websites->find($id));
$assert($paused['status'] === Status::PAUSED && (int) $paused['monitoring_enabled'] === 0, 'Pause sets PAUSED and disables monitoring');
$dueIds = array_map(static fn ($w) => (int) $w['id'], $websites->due(1000));
$assert(!in_array($id, $dueIds, true), 'Paused website is never selected as due');
$resumed = $service->resume($websites->find($id));
$assert($resumed['status'] === Status::PENDING && (int) $resumed['monitoring_enabled'] === 1 && (int) $resumed['failure_count'] === 0, 'Resume re-enables monitoring with reset counters');
$dueIds = array_map(static fn ($w) => (int) $w['id'], $websites->due(1000));
$assert(in_array($id, $dueIds, true), 'Resumed website is due immediately');

echo "\nCron cycle\n";
$server->setMode('ok');
$summary = $manager->run();
$assert(!$summary['skipped'] && $summary['checked'] >= 1, 'Monitor run processed due websites', $summary['message']);
$after = $websites->find($id);
$assert($after['status'] === Status::ONLINE && $after['next_check_at'] > utc_now()->format('Y-m-d H:i:s'), 'Cron check scheduled next_check_at in the future');
$engine = $manager->scheduler()->engineStatus();
$assert($engine['running'] === true, 'Engine heartbeat reports Running', $engine['label']);

echo "\nCleanup\n";
$service->delete($websites->find($id));
$assert($websites->find($id) === null, 'Test website deleted');
$leftChecks = (int) $db->fetchColumn('SELECT COUNT(*) FROM website_checks WHERE website_id = :id', ['id' => $id]);
$assert($leftChecks === 0, 'Checks removed by cascade');

$server->stop();
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
