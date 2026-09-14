<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Notifications\NotificationManager;
use App\Repositories\ActivityRepository;
use App\Repositories\CheckRepository;
use App\Repositories\DailyStatsRepository;
use App\Repositories\IncidentRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\WebsiteRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The incident lifecycle state machine.
 *
 *   failure #1..(N-1)  -> SUSPECTED_DOWN (no incident, no alert, not counted as downtime)
 *   failure #N         -> confirmed: incident opened (started_at = first failure), ONE alert sent
 *   further failures   -> incident stays open, root cause updated, no duplicate alerts
 *   success #1..(M-1)  -> pending recovery (status unchanged)
 *   success #M         -> incident resolved, ONE recovery alert sent
 *
 * N = failure threshold, M = recovery threshold (global settings with per-website overrides).
 */
final class IncidentManager
{
    public function __construct(
        private readonly WebsiteRepository $websites,
        private readonly CheckRepository $checks,
        private readonly IncidentRepository $incidents,
        private readonly DailyStatsRepository $dailyStats,
        private readonly ActivityRepository $activity,
        private readonly NotificationManager $notifications,
        private readonly SettingsRepository $settings,
        private readonly LoggerInterface $log
    ) {
    }

    /**
     * Persist a check result and advance the website's state machine.
     *
     * @param array<string, mixed> $website Current website row
     * @return array{website: array<string, mixed>, status: string, check_id: int, incident_opened: ?int, incident_resolved: ?int, notified: array<int, string>}
     */
    public function process(array $website, CheckResult $result, ?string $nextCheckAt = null): array
    {
        $websiteId = (int) $website['id'];
        $now = $result->checkedAt !== '' ? $result->checkedAt : utc_now()->format('Y-m-d H:i:s');

        $failureThreshold = max(1, (int) ($website['failure_threshold'] ?: $this->settings->getInt('failure_threshold', 3)));
        $recoveryThreshold = max(1, (int) ($website['recovery_threshold'] ?: $this->settings->getInt('recovery_threshold', 2)));

        $open = $this->incidents->openFor($websiteId);
        $prevStatus = (string) ($website['status'] ?? Status::PENDING);
        $failureCount = (int) ($website['failure_count'] ?? 0);
        $successCount = (int) ($website['success_count'] ?? 0);

        $events = ['incident_opened' => null, 'incident_resolved' => null, 'notified' => []];
        $isUp = 1;
        $newStatus = $result->status;
        $confirmNow = false;
        $resolveNow = false;

        if ($result->isFailure) {
            $failureCount++;
            $successCount = 0;
            if ($open !== null) {
                $isUp = 0; // ongoing confirmed downtime
            } elseif ($failureCount >= $failureThreshold) {
                $isUp = 0;
                $confirmNow = true;
            } else {
                $newStatus = Status::SUSPECTED_DOWN;
            }
        } else {
            $successCount++;
            $failureCount = 0;
            if ($open !== null) {
                if ($successCount >= $recoveryThreshold) {
                    $resolveNow = true;
                } else {
                    $newStatus = $prevStatus; // pending recovery: keep showing the failure until confirmed
                }
            }
        }

        // 1. Persist the raw check ------------------------------------------------------
        $checkId = $this->checks->record([
            'website_id'         => $websiteId,
            'status'             => $result->status,
            'is_failure'         => $result->isFailure ? 1 : 0,
            'is_up'              => $isUp,
            'http_status'        => $result->httpStatus,
            'response_time'      => $result->responseTime,
            'error_type'         => $result->errorType,
            'error_message'      => $result->errorMessage,
            'redirect_count'     => $result->redirectCount,
            'final_url'          => $result->finalUrl,
            'ssl_days_remaining' => $result->sslDaysRemaining,
            'source'             => $result->source,
            'checked_at'         => $now,
        ]);

        $touchedDates = [];
        $localToday = to_local($now)?->format('Y-m-d');
        if ($localToday !== null) {
            $touchedDates[] = $localToday;
        }

        // 2. Confirmation: open an incident ----------------------------------------------
        if ($confirmNow) {
            $marked = $this->checks->markRecentFailuresAsDown($websiteId, $failureCount);
            $touchedDates = array_merge($touchedDates, $marked['dates']);
            $startedAt = $marked['earliest'] ?? $now;

            $incidentId = $this->incidents->create([
                'website_id'    => $websiteId,
                'type'          => Status::incidentType($result->status),
                'title'         => self::incidentTitle($result->status),
                'error_message' => $result->errorMessage,
                'http_status'   => $result->httpStatus,
                'response_time' => $result->responseTime,
                'diagnostics'   => json_encode(array_merge($result->diagnostics, [
                    'first_status'      => $result->status,
                    'failures_to_confirm' => $failureCount,
                    'source'            => $result->source,
                ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'started_at'    => $startedAt,
                'confirmed_at'  => $now,
            ]);
            $open = $this->incidents->openFor($websiteId);
            $events['incident_opened'] = $incidentId;
            $this->activity->log(
                'incident.opened',
                sprintf('Incident opened for %s: %s', $website['name'], Status::label($result->status)),
                $websiteId,
                null,
                ['incident_id' => $incidentId, 'status' => $result->status, 'http_status' => $result->httpStatus, 'message' => $result->errorMessage]
            );
            $this->log->warning('Incident opened', ['website_id' => $websiteId, 'url' => $website['url'], 'status' => $result->status, 'message' => $result->errorMessage]);
        } elseif ($result->isFailure && $open !== null) {
            // Update the open incident with the latest root cause (no new alert).
            if ((string) $open['error_message'] !== (string) $result->errorMessage || (int) ($open['http_status'] ?? 0) !== (int) ($result->httpStatus ?? 0)) {
                $diag = json_decode((string) ($open['diagnostics'] ?? ''), true) ?: [];
                $diag['latest'] = ['status' => $result->status, 'http_status' => $result->httpStatus, 'message' => $result->errorMessage, 'at' => $now];
                $this->incidents->update((int) $open['id'], [
                    'error_message' => $result->errorMessage,
                    'http_status'   => $result->httpStatus,
                    'response_time' => $result->responseTime,
                    'diagnostics'   => json_encode($diag, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]);
            }
        }

        // 3. Recovery: resolve the incident ----------------------------------------------
        $resolvedIncident = null;
        if ($resolveNow && $open !== null) {
            $this->incidents->resolve((int) $open['id'], $now, $result->httpStatus, $result->responseTime);
            $resolvedIncident = $this->incidents->find((int) $open['id']) ?? $open;
            $events['incident_resolved'] = (int) $open['id'];
            $this->activity->log(
                'incident.resolved',
                sprintf('%s recovered after %s', $website['name'], format_duration((int) ($resolvedIncident['duration_seconds'] ?? 0))),
                $websiteId,
                null,
                ['incident_id' => (int) $open['id'], 'duration_seconds' => $resolvedIncident['duration_seconds'] ?? null]
            );
            $this->log->info('Incident resolved', ['website_id' => $websiteId, 'url' => $website['url'], 'duration' => $resolvedIncident['duration_seconds'] ?? null]);
        }

        // 4. Update the website row ------------------------------------------------------
        $update = [
            'status'              => $newStatus,
            'failure_count'       => $failureCount,
            'success_count'       => $successCount,
            'last_http_status'    => $result->httpStatus,
            'last_response_time'  => $result->responseTime,
            'last_error_type'     => $result->errorType,
            'last_error_message'  => $result->errorMessage !== null ? mb_substr($result->errorMessage, 0, 500) : null,
            'last_final_url'      => $result->finalUrl !== null ? mb_substr($result->finalUrl, 0, 2048) : null,
            'last_redirect_count' => $result->redirectCount,
            'last_checked_at'     => $now,
        ];
        if ($nextCheckAt !== null) {
            $update['next_check_at'] = $nextCheckAt;
        }
        if (!$result->isFailure) {
            $update['last_online_at'] = $now;
        }
        if ($confirmNow) {
            $update['last_down_at'] = $open['started_at'] ?? $now;
        }
        if ($newStatus !== $prevStatus) {
            $update['previous_status'] = $prevStatus;
            $update['last_status_change_at'] = $now;
        }
        $this->websites->update($websiteId, $update);
        $website = array_merge($website, $update);

        // 5. Daily aggregates -------------------------------------------------------------
        foreach (array_unique($touchedDates) as $date) {
            try {
                $this->dailyStats->rebuild($websiteId, $date);
            } catch (Throwable $e) {
                $this->log->error('Daily stats rebuild failed', ['website_id' => $websiteId, 'date' => $date, 'error' => $e->getMessage()]);
            }
        }

        // 6. Warning transitions worth an activity entry ---------------------------------
        if (!$result->isFailure && $newStatus !== $prevStatus && Status::isWarning($newStatus) && $newStatus !== Status::SUSPECTED_DOWN) {
            $this->activity->log(
                'website.warning',
                sprintf('%s: %s', $website['name'], $result->errorMessage ?? Status::label($newStatus)),
                $websiteId,
                null,
                ['status' => $newStatus, 'response_time' => $result->responseTime]
            );
        }

        // 7. Notifications (never allowed to break monitoring) --------------------------
        try {
            if ($confirmNow && $open !== null && empty($open['notified_at'])) {
                if ($this->notifications->incidentOpened($website, $open, $result)) {
                    $this->incidents->update((int) $open['id'], ['notified_at' => utc_now()->format('Y-m-d H:i:s')]);
                    $events['notified'][] = 'down';
                }
            }
            if ($resolveNow && $resolvedIncident !== null && empty($resolvedIncident['recovery_notified_at'])) {
                if ($this->notifications->incidentResolved($website, $resolvedIncident, $result)) {
                    $this->incidents->update((int) $resolvedIncident['id'], ['recovery_notified_at' => utc_now()->format('Y-m-d H:i:s')]);
                    $events['notified'][] = 'recovery';
                }
            }
        } catch (Throwable $e) {
            $this->log->error('Notification dispatch failed', ['website_id' => $websiteId, 'error' => $e->getMessage()]);
        }

        return [
            'website'           => $website,
            'status'            => $newStatus,
            'check_id'          => $checkId,
            'incident_opened'   => $events['incident_opened'],
            'incident_resolved' => $events['incident_resolved'],
            'notified'          => $events['notified'],
        ];
    }

    public static function incidentTitle(string $status): string
    {
        return match ($status) {
            Status::DOWN                 => 'Website Down',
            Status::DNS_ERROR            => 'DNS Resolution Failure',
            Status::TIMEOUT              => 'Connection Timeout',
            Status::HTTP_500             => 'HTTP 500 Internal Server Error',
            Status::HTTP_502             => 'HTTP 502 Bad Gateway',
            Status::HTTP_503             => 'HTTP 503 Service Unavailable',
            Status::HTTP_504             => 'HTTP 504 Gateway Timeout',
            Status::HTTP_ERROR           => 'HTTP Error Response',
            Status::CRITICAL_ERROR       => 'WordPress Critical Error',
            Status::DATABASE_ERROR       => 'Database Connection Error',
            Status::SSL_ERROR            => 'SSL Certificate Error',
            Status::REDIRECT_ERROR       => 'Redirect Problem',
            Status::MAINTENANCE          => 'Stuck in Maintenance Mode',
            Status::CRITICAL_PERFORMANCE => 'Critical Performance Degradation',
            default                      => Status::label($status),
        };
    }
}
