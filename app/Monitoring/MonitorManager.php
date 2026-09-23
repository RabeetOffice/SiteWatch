<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Core\App;
use App\Core\Lock;
use App\Notifications\NotificationManager;
use App\Repositories\ActivityRepository;
use App\Repositories\CheckRepository;
use App\Repositories\ConnectorRepository;
use App\Repositories\DailyStatsRepository;
use App\Repositories\HeartbeatRepository;
use App\Repositories\IncidentRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\WebsiteRepository;
use App\Services\ConnectorService;
use App\Services\MaintenanceService;
use App\Services\PerformanceService;
use Composer\CaBundle\CaBundle;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Orchestrates a monitoring cycle: lock, heartbeat, due websites, concurrent probes,
 * classification, incident processing, SSL refresh and housekeeping.
 */
final class MonitorManager
{
    public function __construct(
        private readonly WebsiteRepository $websites,
        private readonly WebsiteMonitor $monitor,
        private readonly StatusClassifier $classifier,
        private readonly IncidentManager $incidents,
        private readonly MonitoringScheduler $scheduler,
        private readonly SSLChecker $ssl,
        private readonly HeartbeatRepository $heartbeats,
        private readonly SettingsRepository $settings,
        private readonly NotificationManager $notifications,
        private readonly ActivityRepository $activity,
        private readonly MaintenanceService $maintenance,
        private readonly LoggerInterface $log,
        private readonly string $lockPath
    ) {
    }

    /**
     * Wire everything from the application container.
     */
    public static function create(): self
    {
        $db = App::db();
        $settings = App::settings();
        $config = App::config();
        $log = App::logger('monitor');

        $guard = new SsrfGuard((bool) $config->get('app.monitor.allow_private_targets', false));
        $caFile = self::caBundlePath();

        $monitor = new WebsiteMonitor($guard, [
            'connect_timeout' => $settings->getInt('connect_timeout', 10),
            'timeout'         => $settings->getInt('request_timeout', 30),
            'max_redirects'   => $settings->getInt('max_redirects', 10),
            'user_agent'      => (string) $config->get('app.monitor.user_agent'),
            'verify'          => $caFile ?? true,
            'body_limit'      => (int) $config->get('app.monitor.body_limit_bytes', 524288),
        ]);
        $classifier = new StatusClassifier(new ErrorDetector(), [
            'moderate' => $settings->getInt('moderate_threshold', 2000),
            'slow'     => $settings->getInt('slow_threshold', 5000),
            'critical' => $settings->getInt('critical_performance_threshold', 10000),
        ]);

        $websites = new WebsiteRepository($db);
        $checks = new CheckRepository($db);
        $incidentRepo = new IncidentRepository($db);
        $daily = new DailyStatsRepository($db);
        $activity = new ActivityRepository($db);
        $heartbeats = new HeartbeatRepository($db);
        $notifications = new NotificationManager($settings, new NotificationRepository($db), App::logger('notifications'));
        // Down alerts name the cause when the WordPress plugin reported a fatal error shortly before.
        $connector = new ConnectorRepository($db);
        $notifications->setCauseResolver(static function (int $websiteId) use ($connector): ?string {
            $row = $connector->latestFatal($websiteId, utc_now()->modify('-30 minutes')->format('Y-m-d H:i:s'));
            return $row !== null ? ConnectorService::describeError($row) : null;
        });
        $incidents = new IncidentManager($websites, $checks, $incidentRepo, $daily, $activity, $notifications, $settings, $log);
        // An outage is only confirmed when the WordPress plugin (if installed) does not report the site serving pages.
        $incidents->setInsideEvidence(static function (int $websiteId): ?array {
            return ConnectorService::create()->insideEvidence($websiteId);
        });
        $scheduler = new MonitoringScheduler($websites, $heartbeats, $settings);
        $ssl = new SSLChecker($guard, $caFile, min(15, $settings->getInt('connect_timeout', 10) + 5));
        $maintenance = new MaintenanceService($db, $settings, $heartbeats, $scheduler, App::logger('cron'));

        return new self(
            $websites, $monitor, $classifier, $incidents, $scheduler, $ssl, $heartbeats, $settings,
            $notifications, $activity, $maintenance, $log,
            rtrim((string) $config->get('app.paths.locks'), '/\\') . DIRECTORY_SEPARATOR . 'monitor.lock'
        );
    }

    /**
     * CA bundle used for certificate verification: the newer of the system bundle and the one shipped
     * with composer/ca-bundle. Stale system bundles (e.g. XAMPP ships a 2022 file) would otherwise
     * reject sites signed by newer roots and create false SSL incidents.
     */
    public static function caBundlePath(): ?string
    {
        try {
            $candidates = [];
            $system = CaBundle::getSystemCaRootBundlePath();
            if (is_file($system)) {
                $candidates[] = $system;
            }
            $bundled = CaBundle::getBundledCaBundlePath();
            if (is_file($bundled)) {
                $candidates[] = $bundled;
            }
            if ($candidates === []) {
                return null;
            }
            usort($candidates, static fn (string $a, string $b): int => self::caBundleDate($b) <=> self::caBundleDate($a));
            return $candidates[0];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Timestamp of the Mozilla "Certificate data ... as of" header inside a CA bundle (0 when absent).
     */
    private static function caBundleDate(string $path): int
    {
        $head = @file_get_contents($path, false, null, 0, 600);
        if ($head !== false && preg_match('/as of:\s*([^\n\r]+)/', $head, $m)) {
            $ts = strtotime(trim($m[1]));
            if ($ts !== false) {
                return $ts;
            }
        }
        return (int) @filemtime($path);
    }

    // ------------------------------------------------------------------
    // Cron cycle
    // ------------------------------------------------------------------

    /**
     * Run one monitoring cycle. Safe to call every minute.
     *
     * @return array{skipped: bool, checked: int, failures: int, incidents_opened: int, incidents_resolved: int, ssl_checked: int, screenshots: int, duration_ms: int, message: string}
     */
    public function run(): array
    {
        $started = microtime(true);
        $summary = ['skipped' => false, 'checked' => 0, 'failures' => 0, 'incidents_opened' => 0, 'incidents_resolved' => 0, 'ssl_checked' => 0, 'screenshots' => 0, 'duration_ms' => 0, 'message' => ''];

        $lock = new Lock($this->lockPath);
        if (!$lock->acquire()) {
            $info = $lock->info();
            $summary['skipped'] = true;
            $summary['message'] = 'Another monitoring process is still running' . (isset($info['pid']) ? ' (pid ' . $info['pid'] . ', started ' . ($info['started_at'] ?? '?') . ' UTC)' : '') . '. Skipped.';
            $this->log->notice($summary['message']);
            return $summary;
        }

        $heartbeatId = $this->heartbeats->start('monitor');
        try {
            $due = $this->scheduler->due(max(1, (int) App::config()->get('app.monitor.max_due_per_run', 300)));
            $concurrency = max(1, min(50, $this->settings->getInt('concurrency', 15)));

            // Screenshots normally run on their own interval; only build the service when the
            // administrator asked for one on every check.
            $performance = null;
            $shotsEveryCheck = false;
            try {
                $performance = PerformanceService::create();
                $shotsEveryCheck = $performance->screenshotsEnabled()
                    && $performance->screenshotIntervalMinutes() === PerformanceService::SCREENSHOT_EVERY_CHECK
                    && $performance->screenshotProvider() !== 'pagespeed';
            } catch (Throwable $e) {
                $this->log->error('Screenshot service unavailable', ['error' => $e->getMessage()]);
            }
            $this->log->info('Monitoring run started', ['due' => count($due), 'concurrency' => $concurrency]);

            if ($due !== []) {
                $probes = $this->monitor->probeMany($due, $concurrency);
                foreach ($due as $website) {
                    $id = (int) $website['id'];
                    try {
                        $probe = $probes[$id] ?? ProbeResult::error((string) $website['url'], ProbeResult::ERROR_UNKNOWN, 'No probe result produced.', 0);
                        $result = $this->classifier->classify($probe, $website, utc_now()->format('Y-m-d H:i:s'));
                        $outcome = $this->incidents->process($website, $result, $this->scheduler->nextCheckAt($website));
                        $summary['checked']++;
                        if ($result->isFailure) {
                            $summary['failures']++;
                        }
                        if ($outcome['incident_opened'] !== null) {
                            $summary['incidents_opened']++;
                        }
                        if ($outcome['incident_resolved'] !== null) {
                            $summary['incidents_resolved']++;
                        }
                        $this->log->info('Checked', ['id' => $id, 'url' => $website['url'], 'status' => $result->status, 'http' => $result->httpStatus, 'ms' => $result->responseTime, 'ttfb' => $result->ttfb]);
                        if ($shotsEveryCheck) {
                            $performance?->captureAfterCheck($outcome['website']);
                            $summary['screenshots']++;
                        }
                    } catch (Throwable $e) {
                        // One malformed record must never stop the cycle; push its next check so it is not retried every minute.
                        $this->log->error('Website processing failed', ['id' => $id, 'url' => $website['url'] ?? '', 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
                        try {
                            $this->websites->update($id, ['next_check_at' => $this->scheduler->nextCheckAt($website), 'last_checked_at' => utc_now()->format('Y-m-d H:i:s')]);
                        } catch (Throwable) {
                            // ignore
                        }
                    }
                }
            }

            // Refresh stale SSL information for a few HTTPS websites each run.
            $summary['ssl_checked'] = $this->refreshStaleSsl(10);

            // Daily housekeeping when a separate cleanup cron is not configured.
            try {
                $this->maintenance->runIfDue();
            } catch (Throwable $e) {
                $this->log->error('Housekeeping failed', ['error' => $e->getMessage()]);
            }

            $summary['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
            $summary['message'] = sprintf('Checked %d website(s), %d failing, %d incident(s) opened, %d resolved in %s.', $summary['checked'], $summary['failures'], $summary['incidents_opened'], $summary['incidents_resolved'], format_ms($summary['duration_ms']));
            $this->heartbeats->finish($heartbeatId, 'completed', $summary['checked'], $summary['failures'], $summary['message']);
            $this->log->info('Monitoring run finished', $summary);
        } catch (Throwable $e) {
            $summary['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
            $summary['message'] = 'Monitoring run failed: ' . $e->getMessage();
            $this->heartbeats->finish($heartbeatId, 'failed', $summary['checked'], $summary['failures'], $summary['message']);
            $this->log->error('Monitoring run failed', ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
        } finally {
            $lock->release();
        }
        return $summary;
    }

    // ------------------------------------------------------------------
    // On-demand checks
    // ------------------------------------------------------------------

    /**
     * Immediately check a single website (Check Now). Runs the full state machine.
     *
     * @param array<string, mixed> $website
     * @return array{result: CheckResult, website: array<string, mixed>, incident_opened: ?int, incident_resolved: ?int, ssl: ?array<string, mixed>}
     */
    public function checkNow(array $website, string $source = 'manual'): array
    {
        $sslInfo = null;
        $checks = StatusClassifier::enabledChecks($website);
        if ($checks['ssl'] && str_starts_with(strtolower((string) $website['url']), 'https://')) {
            try {
                $sslInfo = $this->checkSsl($website);
                $website = $this->websites->find((int) $website['id']) ?? $website;
            } catch (Throwable $e) {
                $this->log->error('SSL check failed', ['id' => $website['id'], 'error' => $e->getMessage()]);
            }
        }

        $probe = $this->monitor->probe((string) $website['url']);
        $result = $this->classifier->classify($probe, $website, utc_now()->format('Y-m-d H:i:s'))->withSource($source);
        $outcome = $this->incidents->process($website, $result, $this->scheduler->nextCheckAt($website));

        return [
            'result'            => $result,
            'website'           => $outcome['website'],
            'incident_opened'   => $outcome['incident_opened'],
            'incident_resolved' => $outcome['incident_resolved'],
            'ssl'               => $sslInfo,
        ];
    }

    /**
     * Check several websites right now (bulk "Check Now"), concurrently, through the full state machine.
     *
     * @param array<int, array<string, mixed>> $websites
     * @return array{checked: int, failing: int, incidents_opened: int, incidents_resolved: int, results: array<int, CheckResult>}
     */
    public function checkMany(array $websites, string $source = 'manual'): array
    {
        $summary = ['checked' => 0, 'failing' => 0, 'incidents_opened' => 0, 'incidents_resolved' => 0, 'results' => []];
        if ($websites === []) {
            return $summary;
        }
        $concurrency = max(1, min(50, $this->settings->getInt('concurrency', 15)));
        $probes = $this->monitor->probeMany($websites, $concurrency);
        foreach ($websites as $website) {
            $id = (int) $website['id'];
            try {
                $probe = $probes[$id] ?? ProbeResult::error((string) $website['url'], ProbeResult::ERROR_UNKNOWN, 'No probe result produced.', 0);
                $result = $this->classifier->classify($probe, $website, utc_now()->format('Y-m-d H:i:s'))->withSource($source);
                $outcome = $this->incidents->process($website, $result, $this->scheduler->nextCheckAt($website));
                $summary['checked']++;
                $summary['results'][$id] = $result;
                if ($result->isFailure) {
                    $summary['failing']++;
                }
                if ($outcome['incident_opened'] !== null) {
                    $summary['incidents_opened']++;
                }
                if ($outcome['incident_resolved'] !== null) {
                    $summary['incidents_resolved']++;
                }
            } catch (Throwable $e) {
                $this->log->error('Bulk check failed for website', ['id' => $id, 'error' => $e->getMessage()]);
            }
        }
        return $summary;
    }

    /**
     * Inspect the certificate of a website, store the result and raise expiry alerts once per threshold.
     *
     * @param array<string, mixed> $website
     * @return array<string, mixed> SSL information
     */
    public function checkSsl(array $website): array
    {
        $id = (int) $website['id'];
        $info = $this->ssl->check((string) $website['url']);
        $now = utc_now()->format('Y-m-d H:i:s');

        $update = [
            'ssl_valid'      => $info['valid'] ? 1 : 0,
            'ssl_expires_at' => $info['expires_at'],
            'ssl_issuer'     => $info['issuer'],
            'ssl_error'      => $info['error'] !== null ? mb_substr((string) $info['error'], 0, 255) : null,
            'ssl_checked_at' => $now,
        ];

        $days = $info['days_remaining'];
        $level = null;
        if ($days !== null) {
            $level = $days < 0 ? 0 : ($days <= 7 ? 7 : ($days <= 14 ? 14 : ($days <= 30 ? 30 : null)));
        }
        $lastLevel = isset($website['ssl_alert_level']) ? (int) $website['ssl_alert_level'] : null;

        if ($level !== null && ($lastLevel === null || $level < $lastLevel)) {
            $update['ssl_alert_level'] = $level;
            $this->activity->log(
                'ssl.warning',
                sprintf('%s: SSL certificate %s', $website['name'], $days < 0 ? 'has expired' : "expires in {$days} day" . ($days === 1 ? '' : 's')),
                $id,
                null,
                ['days_remaining' => $days, 'expires_at' => $info['expires_at']]
            );
            try {
                $this->notifications->sslExpiry(array_merge($website, $update), $days, $info);
            } catch (Throwable $e) {
                $this->log->error('SSL notification failed', ['id' => $id, 'error' => $e->getMessage()]);
            }
        } elseif ($level === null && $lastLevel !== null) {
            $update['ssl_alert_level'] = null; // certificate renewed
            $this->activity->log('ssl.renewed', sprintf('%s: SSL certificate renewed (expires %s)', $website['name'], format_date($info['expires_at'])), $id);
        }

        $this->websites->update($id, $update);
        $info['days_remaining'] = $days;
        return $info;
    }

    /**
     * Refresh SSL information for websites whose data is older than the configured interval.
     */
    public function refreshStaleSsl(int $limit): int
    {
        $count = 0;
        try {
            $due = $this->websites->sslDue($limit, max(1, $this->settings->getInt('ssl_check_interval_hours', 12)));
        } catch (Throwable $e) {
            $this->log->error('SSL due query failed', ['error' => $e->getMessage()]);
            return 0;
        }
        foreach ($due as $website) {
            if (!StatusClassifier::enabledChecks($website)['ssl']) {
                // SSL monitoring disabled for this site: mark as checked so it is not selected every run.
                $this->websites->update((int) $website['id'], ['ssl_checked_at' => utc_now()->format('Y-m-d H:i:s')]);
                continue;
            }
            try {
                $this->checkSsl($website);
                $count++;
            } catch (Throwable $e) {
                $this->log->error('SSL check failed', ['id' => $website['id'], 'url' => $website['url'], 'error' => $e->getMessage()]);
                try {
                    $this->websites->update((int) $website['id'], ['ssl_checked_at' => utc_now()->format('Y-m-d H:i:s'), 'ssl_error' => mb_substr($e->getMessage(), 0, 255)]);
                } catch (Throwable) {
                    // ignore
                }
            }
        }
        return $count;
    }

    /**
     * Full SSL pass (cron/ssl-check.php).
     *
     * @return array{checked: int, warnings: int}
     */
    public function runSslChecks(): array
    {
        $heartbeatId = $this->heartbeats->start('ssl-check');
        $checked = 0;
        $warnings = 0;
        try {
            foreach ($this->websites->all() as $website) {
                if ((int) $website['monitoring_enabled'] !== 1 || !str_starts_with(strtolower((string) $website['url']), 'https://')) {
                    continue;
                }
                if (!StatusClassifier::enabledChecks($website)['ssl']) {
                    continue;
                }
                try {
                    $info = $this->checkSsl($website);
                    $checked++;
                    if (!$info['valid'] || ($info['days_remaining'] !== null && $info['days_remaining'] <= 30)) {
                        $warnings++;
                    }
                } catch (Throwable $e) {
                    $this->log->error('SSL check failed', ['id' => $website['id'], 'error' => $e->getMessage()]);
                }
            }
            $this->heartbeats->finish($heartbeatId, 'completed', $checked, $warnings, "SSL checked {$checked} website(s), {$warnings} warning(s).");
        } catch (Throwable $e) {
            $this->heartbeats->finish($heartbeatId, 'failed', $checked, $warnings, $e->getMessage());
            throw $e;
        }
        return ['checked' => $checked, 'warnings' => $warnings];
    }

    public function scheduler(): MonitoringScheduler
    {
        return $this->scheduler;
    }

    public function notifications(): NotificationManager
    {
        return $this->notifications;
    }

    public function maintenance(): MaintenanceService
    {
        return $this->maintenance;
    }
}
