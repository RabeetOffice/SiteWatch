<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Monitoring\MonitoringScheduler;
use App\Repositories\ActivityRepository;
use App\Repositories\CheckRepository;
use App\Repositories\HeartbeatRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\VitalsRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Data retention housekeeping. Runs from cron/cleanup.php, or automatically once a day from the
 * monitoring cycle when no separate cleanup cron has been configured.
 */
final class MaintenanceService
{
    /** Daily at 03:00 UTC when piggy-backing on the monitor cron. */
    private const AUTO_SCHEDULE = '0 3 * * *';

    public function __construct(
        private readonly Database $db,
        private readonly SettingsRepository $settings,
        private readonly HeartbeatRepository $heartbeats,
        private readonly MonitoringScheduler $scheduler,
        private readonly LoggerInterface $log
    ) {
    }

    /**
     * @return array{connector: int, checks: int, activity: int, notifications: int, heartbeats: int, vitals: int, screenshots: int, login_attempts: int, remember_tokens: int, duration_ms: int}
     */
    public function cleanup(): array
    {
        $started = microtime(true);
        $heartbeatId = $this->heartbeats->start('cleanup');
        $result = ['connector' => 0, 'checks' => 0, 'activity' => 0, 'notifications' => 0, 'heartbeats' => 0, 'vitals' => 0, 'screenshots' => 0, 'login_attempts' => 0, 'remember_tokens' => 0, 'duration_ms' => 0];

        try {
            $checkDays = $this->clampRetention($this->settings->getInt('check_retention_days', 30), 7, 365);
            // 0 keeps the activity log forever.
            $activityDays = $this->settings->getInt('activity_retention_days', 30);
            $activityDays = $activityDays <= 0 ? 0 : $this->clampRetention($activityDays, 7, 30);
            $notificationDays = $this->clampRetention($this->settings->getInt('notification_retention_days', 90), 7, 3650);

            $checks = new CheckRepository($this->db);
            $result['checks'] = $checks->purgeOlderThan(utc_now()->modify("-{$checkDays} days")->format('Y-m-d H:i:s'));

            if ($activityDays > 0) {
                $activity = new ActivityRepository($this->db);
                $result['activity'] = $activity->purgeOlderThan(utc_now()->modify("-{$activityDays} days")->format('Y-m-d H:i:s'));
            }

            $notifications = new NotificationRepository($this->db);
            $result['notifications'] = $notifications->purgeOlderThan(utc_now()->modify("-{$notificationDays} days")->format('Y-m-d H:i:s'));

            try {
                $result['connector'] = ConnectorService::create()->purge();
            } catch (Throwable $e) {
                $this->log->warning('Connector event cleanup failed', ['error' => $e->getMessage()]);
            }

            $vitalsDays = $this->clampRetention($this->settings->getInt('vitals_retention_days', 180), 7, 3650);
            $result['vitals'] = (new VitalsRepository($this->db))->purgeOlderThan(utc_now()->modify("-{$vitalsDays} days")->format('Y-m-d H:i:s'));

            // Screenshots are files as well as rows, so this goes through the service that owns both.
            try {
                $result['screenshots'] = PerformanceService::create()->pruneScreenshots();
            } catch (Throwable $e) {
                $this->log->warning('Screenshot cleanup failed', ['error' => $e->getMessage()]);
            }

            $result['heartbeats'] = $this->heartbeats->purgeOlderThan(utc_now()->modify('-14 days')->format('Y-m-d H:i:s'));
            $result['login_attempts'] = $this->db->delete('login_attempts', 'created_at < :c', ['c' => utc_now()->modify('-2 days')->format('Y-m-d H:i:s')]);
            $result['remember_tokens'] = $this->db->delete('remember_tokens', 'expires_at < :c', ['c' => utc_now()->format('Y-m-d H:i:s')]);

            $result['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
            $message = sprintf(
                'Removed %d checks, %d activity entries, %d notifications, %d vitals runs, %d screenshots, %d heartbeats.',
                $result['checks'], $result['activity'], $result['notifications'], $result['vitals'], $result['screenshots'], $result['heartbeats']
            );
            $this->heartbeats->finish($heartbeatId, 'completed', 0, 0, $message);
            $this->log->info('Cleanup finished', $result);
        } catch (Throwable $e) {
            $this->heartbeats->finish($heartbeatId, 'failed', 0, 0, $e->getMessage());
            $this->log->error('Cleanup failed', ['error' => $e->getMessage()]);
            throw $e;
        }
        return $result;
    }

    /**
     * Run cleanup if the daily schedule has come due since the last run.
     *
     * @return array<string, int>|null
     */
    public function runIfDue(): ?array
    {
        $last = $this->heartbeats->latest('cleanup');
        $lastRun = $last['started_at'] ?? null;
        if ($lastRun !== null && time() - strtotime($lastRun . ' UTC') < 20 * 3600) {
            return null;
        }
        if (!$this->scheduler->isDue(self::AUTO_SCHEDULE, $lastRun)) {
            return null;
        }
        return $this->cleanup();
    }

    private function clampRetention(int $days, int $min, int $max): int
    {
        return max($min, min($max, $days));
    }
}
