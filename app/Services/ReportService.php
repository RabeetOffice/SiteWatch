<?php

declare(strict_types=1);

namespace App\Services;

use App\Monitoring\Status;
use App\Monitoring\StatusClassifier;
use App\Repositories\DailyStatsRepository;
use App\Repositories\IncidentRepository;
use App\Repositories\WebsiteRepository;

/**
 * Uptime / performance reports over a local date range.
 */
final class ReportService
{
    public function __construct(
        private readonly WebsiteRepository $websites,
        private readonly DailyStatsRepository $dailyStats,
        private readonly IncidentRepository $incidents
    ) {
    }

    /**
     * Normalise a requested date range (local dates, inclusive). Defaults to the last 30 days.
     *
     * @return array{from: string, to: string, days: int}
     */
    public static function range(?string $from, ?string $to, int $maxDays = 366): array
    {
        $tz = app_timezone();
        $today = utc_now()->setTimezone($tz);
        $toDate = self::parseDate($to, $tz) ?? $today;
        $fromDate = self::parseDate($from, $tz) ?? $toDate->modify('-29 days');
        if ($fromDate > $toDate) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }
        if ($toDate > $today) {
            $toDate = $today;
        }
        $days = (int) $fromDate->diff($toDate)->days + 1;
        if ($days > $maxDays) {
            $fromDate = $toDate->modify('-' . ($maxDays - 1) . ' days');
            $days = $maxDays;
        }
        return ['from' => $fromDate->format('Y-m-d'), 'to' => $toDate->format('Y-m-d'), 'days' => $days];
    }

    private static function parseDate(?string $value, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if ($value === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value, $tz);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array{website_id?: int, client?: string, from?: string, to?: string} $criteria
     * @return array{range: array{from: string, to: string, days: int}, rows: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public function uptimeReport(array $criteria): array
    {
        $range = self::range($criteria['from'] ?? null, $criteria['to'] ?? null);
        $fromUtc = IncidentRepository::localDateToUtc($range['from'], false) ?? $range['from'] . ' 00:00:00';
        $toUtc = IncidentRepository::localDateToUtc($range['to'], true) ?? $range['to'] . ' 23:59:59';

        $stats = $this->dailyStats->perWebsiteRangeStats($range['from'], $range['to']);
        $downtime = $this->incidents->downtimeSecondsPerWebsite($fromUtc, $toUtc);

        $websites = $this->websites->all();
        $rows = [];
        foreach ($websites as $w) {
            if (!empty($criteria['website_id']) && (int) $w['id'] !== (int) $criteria['website_id']) {
                continue;
            }
            if (!empty($criteria['client']) && $w['client_name'] !== $criteria['client']) {
                continue;
            }
            $s = $stats[(int) $w['id']] ?? ['total' => 0, 'up' => 0, 'down' => 0, 'uptime' => null, 'avg' => null, 'min' => null, 'max' => null, 'incidents' => 0, 'days' => 0];
            $sslDays = StatusClassifier::sslDaysRemaining($w);
            $ssl = WebsiteService::presentSsl($w, $sslDays, str_starts_with(strtolower((string) $w['url']), 'https://'), StatusClassifier::enabledChecks($w)['ssl']);
            $rows[] = [
                'id'                => (int) $w['id'],
                'name'              => $w['name'],
                'client_name'       => $w['client_name'],
                'domain'            => $w['domain'],
                'url'               => $w['url'],
                'favicon_url'       => $w['favicon_url'],
                'status'            => (int) $w['monitoring_enabled'] === 1 ? $w['status'] : Status::PAUSED,
                'status_label'      => Status::label((int) $w['monitoring_enabled'] === 1 ? $w['status'] : Status::PAUSED),
                'severity'          => Status::severity((int) $w['monitoring_enabled'] === 1 ? $w['status'] : Status::PAUSED),
                'checks'            => $s['total'],
                'uptime'            => $s['uptime'],
                'uptime_label'      => format_uptime($s['uptime']),
                'downtime_seconds'  => $downtime[(int) $w['id']] ?? 0,
                'downtime_label'    => format_duration($downtime[(int) $w['id']] ?? 0, '0 sec'),
                'incidents'         => $s['incidents'],
                'avg_response'      => $s['avg'],
                'avg_response_label' => format_ms($s['avg']),
                'min_response'      => $s['min'],
                'min_response_label' => format_ms($s['min']),
                'max_response'      => $s['max'],
                'max_response_label' => format_ms($s['max']),
                'ssl'               => $ssl,
                'days_with_data'    => $s['days'],
            ];
        }

        $summary = $this->summarise($rows);
        return ['range' => $range, 'rows' => $rows, 'summary' => $summary];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function summarise(array $rows): array
    {
        $checks = 0;
        $weightedUp = 0.0;
        $incidents = 0;
        $downtime = 0;
        $avgSum = 0.0;
        $avgCount = 0;
        $slowest = null;
        $slowestSite = null;
        foreach ($rows as $row) {
            $checks += (int) $row['checks'];
            if ($row['uptime'] !== null) {
                $weightedUp += (float) $row['uptime'] * (int) $row['checks'];
            }
            $incidents += (int) $row['incidents'];
            $downtime += (int) $row['downtime_seconds'];
            if ($row['avg_response'] !== null) {
                $avgSum += (float) $row['avg_response'] * (int) $row['checks'];
                $avgCount += (int) $row['checks'];
            }
            if ($row['max_response'] !== null && ($slowest === null || $row['max_response'] > $slowest)) {
                $slowest = (int) $row['max_response'];
                $slowestSite = $row['name'];
            }
        }
        $uptime = $checks > 0 ? round($weightedUp / $checks, 3) : null;
        $avg = $avgCount > 0 ? round($avgSum / $avgCount) : null;
        return [
            'websites'          => count($rows),
            'checks'            => $checks,
            'uptime'            => $uptime,
            'uptime_label'      => format_uptime($uptime),
            'incidents'         => $incidents,
            'downtime_seconds'  => $downtime,
            'downtime_label'    => format_duration($downtime, '0 sec'),
            'avg_response'      => $avg,
            'avg_response_label' => format_ms($avg),
            'slowest_response'  => $slowest,
            'slowest_label'     => format_ms($slowest),
            'slowest_site'      => $slowestSite,
        ];
    }

    /**
     * CSV rows for the uptime report.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{headers: array<int, string>, rows: array<int, array<int, mixed>>}
     */
    public static function exportRows(array $rows): array
    {
        $headers = ['Website', 'Client', 'Domain', 'URL', 'Current Status', 'Checks', 'Uptime %', 'Downtime (sec)', 'Downtime', 'Incidents', 'Avg Response (ms)', 'Min Response (ms)', 'Max Response (ms)', 'SSL Status', 'SSL Expires (UTC)'];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                $r['name'], $r['client_name'], $r['domain'], $r['url'], $r['status_label'], $r['checks'],
                $r['uptime'] !== null ? number_format((float) $r['uptime'], 3) : '', $r['downtime_seconds'], $r['downtime_label'],
                $r['incidents'], $r['avg_response'], $r['min_response'], $r['max_response'], $r['ssl']['label'], $r['ssl']['expires_at'],
            ];
        }
        return ['headers' => $headers, 'rows' => $out];
    }

    /**
     * JSON representation of an incident row (joined with website columns).
     *
     * @param array<string, mixed> $i
     * @return array<string, mixed>
     */
    public static function presentIncident(array $i): array
    {
        $open = ($i['status'] ?? 'OPEN') === 'OPEN';
        $duration = $open
            ? max(0, time() - strtotime($i['started_at'] . ' UTC'))
            : (int) ($i['duration_seconds'] ?? 0);
        $diagnostics = null;
        if (!empty($i['diagnostics'])) {
            $decoded = json_decode((string) $i['diagnostics'], true);
            $diagnostics = is_array($decoded) ? $decoded : null;
        }
        return [
            'id'                  => (int) $i['id'],
            'website_id'          => (int) $i['website_id'],
            'website_name'        => $i['website_name'] ?? '',
            'client_name'         => $i['client_name'] ?? '',
            'domain'              => $i['domain'] ?? '',
            'url'                 => $i['url'] ?? '',
            'favicon_url'         => $i['favicon_url'] ?? null,
            'type'                => $i['type'],
            'type_label'          => Status::incidentTypeLabel($i['type']),
            'title'               => $i['title'],
            'error_message'       => $i['error_message'],
            'http_status'         => isset($i['http_status']) ? (int) $i['http_status'] : null,
            'response_time'       => isset($i['response_time']) ? (int) $i['response_time'] : null,
            'status'              => $i['status'],
            'is_open'             => $open,
            'started_at'          => $i['started_at'],
            'started_label'       => format_datetime($i['started_at']),
            'started_ago'         => time_ago($i['started_at']),
            'confirmed_at'        => $i['confirmed_at'] ?? null,
            'resolved_at'         => $i['resolved_at'] ?? null,
            'resolved_label'      => format_datetime($i['resolved_at'] ?? null, 'j M Y · g:i A', $open ? 'Ongoing' : '—'),
            'duration_seconds'    => $duration,
            'duration_label'      => format_duration($duration),
            'notified_at'         => $i['notified_at'] ?? null,
            'recovery_notified_at' => $i['recovery_notified_at'] ?? null,
            'resolved_http_status' => $i['resolved_http_status'] ?? null,
            'resolved_response_time' => $i['resolved_response_time'] ?? null,
            'diagnostics'         => $diagnostics,
            'urls'                => ['website' => base_url('admin/website-details.php?id=' . (int) $i['website_id'])],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{headers: array<int, string>, rows: array<int, array<int, mixed>>}
     */
    public static function exportIncidents(array $rows): array
    {
        $headers = ['ID', 'Website', 'Client', 'Domain', 'Type', 'Title', 'Status', 'HTTP Status', 'Error', 'Started (UTC)', 'Resolved (UTC)', 'Duration (sec)', 'Duration'];
        $out = [];
        foreach ($rows as $i) {
            $p = self::presentIncident($i);
            $out[] = [$p['id'], $p['website_name'], $p['client_name'], $p['domain'], $p['type_label'], $p['title'], $p['status'], $p['http_status'], $p['error_message'], $p['started_at'], $p['resolved_at'], $p['duration_seconds'], $p['duration_label']];
        }
        return ['headers' => $headers, 'rows' => $out];
    }
}
