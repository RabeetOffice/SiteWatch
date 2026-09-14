<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Repositories\HeartbeatRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\WebsiteRepository;
use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Decides which websites are due, computes the next check time, and reports the health of the
 * monitoring engine itself based on heartbeats.
 */
final class MonitoringScheduler
{
    public function __construct(
        private readonly WebsiteRepository $websites,
        private readonly HeartbeatRepository $heartbeats,
        private readonly SettingsRepository $settings
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function due(int $limit): array
    {
        return $this->websites->due($limit);
    }

    /**
     * Next check timestamp (UTC) for a website based on its interval.
     *
     * @param array<string, mixed> $website
     */
    public function nextCheckAt(array $website, ?DateTimeImmutable $from = null): string
    {
        $interval = (int) ($website['check_interval'] ?? 0);
        if (!in_array($interval, WebsiteRepository::INTERVALS, true)) {
            $interval = max(1, $this->settings->getInt('default_check_interval', 5));
        }
        $from ??= utc_now();
        return $from->modify("+{$interval} minutes")->format('Y-m-d H:i:s');
    }

    /**
     * Has a cron-expression schedule come due since it last ran?
     */
    public function isDue(string $cronExpression, ?string $lastRunUtc): bool
    {
        try {
            $cron = new CronExpression($cronExpression);
        } catch (\Throwable) {
            return false;
        }
        $tz = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now', $tz);
        $previous = $cron->getPreviousRunDate($now, 0, true, 'UTC');
        if ($lastRunUtc === null) {
            return true;
        }
        $last = new DateTimeImmutable($lastRunUtc, $tz);
        return $previous > $last;
    }

    /**
     * Monitoring engine health derived from heartbeats.
     *
     * @return array{state: string, running: bool, label: string, last_run_at: ?string, last_run_ago: string, last_finished_at: ?string, last_duration_ms: ?int, last_websites_checked: int, last_status: ?string, message: ?string, threshold_minutes: int}
     */
    public function engineStatus(): array
    {
        $threshold = max(1, $this->settings->getInt('heartbeat_threshold_minutes', 3));
        $last = $this->heartbeats->latest('monitor');

        if ($last === null) {
            return [
                'state'                 => 'never',
                'running'               => false,
                'label'                 => 'Not Running',
                'last_run_at'           => null,
                'last_run_ago'          => 'never',
                'last_finished_at'      => null,
                'last_duration_ms'      => null,
                'last_websites_checked' => 0,
                'last_status'           => null,
                'message'               => 'The monitoring cron job has never run. Configure the cron job (see README).',
                'threshold_minutes'     => $threshold,
            ];
        }

        $age = time() - strtotime($last['started_at'] . ' UTC');
        $isRunningNow = $last['status'] === 'running' && $age < 20 * 60;
        $recent = $age <= $threshold * 60;

        if ($last['status'] === 'failed' && $recent) {
            $state = 'problem';
            $label = 'Problem Detected';
            $message = 'The last monitoring run failed: ' . ($last['message'] ?? 'unknown error');
        } elseif ($recent || $isRunningNow) {
            $state = 'running';
            $label = 'Running';
            $message = null;
        } else {
            $state = 'stopped';
            $label = 'Not Running';
            $message = 'No monitoring run for ' . format_duration($age) . '. Check the cron job configuration.';
        }

        return [
            'state'                 => $state,
            'running'               => $state === 'running',
            'label'                 => $label,
            'last_run_at'           => $last['started_at'],
            'last_run_ago'          => time_ago($last['started_at']),
            'last_finished_at'      => $last['finished_at'],
            'last_duration_ms'      => $last['duration_ms'] !== null ? (int) $last['duration_ms'] : null,
            'last_websites_checked' => (int) $last['websites_checked'],
            'last_status'           => $last['status'],
            'message'               => $message,
            'threshold_minutes'     => $threshold,
        ];
    }
}
