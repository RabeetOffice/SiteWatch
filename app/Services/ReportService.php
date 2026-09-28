<?php

declare(strict_types=1);

namespace App\Services;

use App\Monitoring\Status;
use App\Monitoring\StatusClassifier;
use App\Repositories\CheckRepository;
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
        private readonly IncidentRepository $incidents,
        private readonly ?CheckRepository $checks = null
    ) {
    }

    /** Response-time windows offered on the Performance page. */
    public const RESPONSE_WINDOWS = ['24h' => 'last 24 hours', '7d' => 'last 7 days', '30d' => 'last 30 days', '90d' => 'last 90 days'];

    /**
     * Response times per website. The 24-hour and 7-day windows read individual checks (so the trend is
     * fine-grained); longer windows and custom date ranges read the daily statistics, which are kept after
     * individual checks have been cleaned up.
     *
     * @param array{window?: string, client?: string, from?: string, to?: string} $criteria
     * @return array{window: string, window_label: string, range: ?array{from: string, to: string, days: int}, rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function responseTimeReport(array $criteria, int $slowThreshold): array
    {
        $window = (string) ($criteria['window'] ?? '24h');
        $custom = !empty($criteria['from']) || !empty($criteria['to']);
        if (!$custom && !isset(self::RESPONSE_WINDOWS[$window])) {
            $window = '24h';
        }
        $client = trim((string) ($criteria['client'] ?? ''));

        $range = null;
        $stats = [];
        if (!$custom && ($window === '24h' || $window === '7d') && $this->checks !== null) {
            $checks = $this->checks;
            $fromUtc = utc_now()->modify($window === '7d' ? '-7 days' : '-24 hours')->format('Y-m-d H:i:s');
            foreach ($checks->perWebsiteStats($fromUtc) as $id => $s) {
                $stats[$id] = [
                    'avg'    => $s['avg_rt'] !== null ? (int) round((float) $s['avg_rt']) : null,
                    'min'    => $s['min_rt'] !== null ? (int) $s['min_rt'] : null,
                    'max'    => $s['max_rt'] !== null ? (int) $s['max_rt'] : null,
                    'checks' => (int) $s['checks'],
                    'down'   => (int) $s['down'],
                ];
            }
            $bucket = $window === '7d' ? 21600 : 3600;
            $trendFor = static fn (int $id): array => array_map(static fn (array $p) => $p['avg'], $checks->responseSeries($id, $fromUtc, $bucket));
            $fleetFor = static fn (array $ids): array => $checks->fleetSeries($fromUtc, $window === '7d' ? 3600 : 900, $ids);
            $label = self::RESPONSE_WINDOWS[$window];
        } else {
            if ($custom) {
                $window = 'custom';
                $range = self::range($criteria['from'] ?? null, $criteria['to'] ?? null);
            } else {
                $days = ['24h' => 1, '7d' => 7, '30d' => 30, '90d' => 90][$window];
                $today = utc_now()->setTimezone(app_timezone());
                $range = self::range($today->modify('-' . ($days - 1) . ' days')->format('Y-m-d'), $today->format('Y-m-d'));
            }
            foreach ($this->dailyStats->perWebsiteRangeStats($range['from'], $range['to']) as $id => $s) {
                $stats[$id] = [
                    'avg'    => $s['avg'] !== null ? (int) round((float) $s['avg']) : null,
                    'min'    => $s['min'] !== null ? (int) $s['min'] : null,
                    'max'    => $s['max'] !== null ? (int) $s['max'] : null,
                    'checks' => (int) $s['total'],
                    'down'   => (int) $s['down'],
                ];
            }
            $daily = $this->dailyStats->perWebsiteDailyAverages($range['from'], $range['to']);
            $trendFor = static fn (int $id): array => array_values($daily[$id] ?? []);
            $dailyStats = $this->dailyStats;
            $fleetFor = static fn (array $ids): array => $dailyStats->fleetDailyAverages($range['from'], $range['to'], $ids);
            $label = $window === 'custom' ? $range['from'] . ' to ' . $range['to'] : self::RESPONSE_WINDOWS[$window];
        }

        $rows = [];
        $sum = 0.0;
        $weight = 0;
        $fastest = null;
        $slowest = null;
        $over = 0;
        foreach ($this->websites->all() as $w) {
            if ($client !== '' && (string) $w['client_name'] !== $client) {
                continue;
            }
            $id = (int) $w['id'];
            $s = $stats[$id] ?? ['avg' => null, 'min' => null, 'max' => null, 'checks' => 0, 'down' => 0];
            $status = (int) $w['monitoring_enabled'] === 1 ? (string) $w['status'] : Status::PAUSED;
            $rows[] = [
                'id'           => $id,
                'name'         => $w['name'],
                'domain'       => $w['domain'],
                'url'          => $w['url'],
                'client_name'  => $w['client_name'],
                'favicon_url'  => $w['favicon_url'],
                'status'       => $status,
                'status_label' => Status::label($status),
                'severity'     => Status::severity($status),
                'current'      => $w['last_response_time'] !== null ? (int) $w['last_response_time'] : null,
                'avg'          => $s['avg'],
                'min'          => $s['min'],
                'max'          => $s['max'],
                'checks'       => $s['checks'],
                'down'         => $s['down'],
                'trend'        => $s['checks'] > 0 ? $trendFor($id) : [],
                'urls'         => ['details' => base_url('admin/website-details.php?id=' . $id)],
            ];
            // Paused websites and averages of 0 ms (no real responses) say nothing about speed.
            if ($s['avg'] !== null && $s['avg'] > 0 && $status !== Status::PAUSED) {
                $sum += $s['avg'] * max(1, $s['checks']);
                $weight += max(1, $s['checks']);
                if ($fastest === null || $s['avg'] < $fastest['avg']) {
                    $fastest = ['name' => $w['name'], 'avg' => $s['avg']];
                }
                if ($slowest === null || $s['avg'] > $slowest['avg']) {
                    $slowest = ['name' => $w['name'], 'avg' => $s['avg']];
                }
                if ($s['avg'] >= $slowThreshold) {
                    $over++;
                }
            }
        }

        // Slowest first; websites without data last, by name.
        usort($rows, static function (array $a, array $b): int {
            if ($a['avg'] === null || $b['avg'] === null) {
                return [$a['avg'] === null, $a['name']] <=> [$b['avg'] === null, $b['name']];
            }
            return $b['avg'] <=> $a['avg'];
        });

        // How many websites fall in each speed band (healthy, moderate, slow, critical) by their average.
        $moderate = (int) setting('moderate_threshold', 2000);
        $critical = (int) setting('critical_performance_threshold', 10000);
        $spread = ['fast' => 0, 'moderate' => 0, 'slow' => 0, 'critical' => 0, 'none' => 0];
        foreach ($rows as $row) {
            $avg = $row['avg'] !== null && $row['avg'] > 0 && $row['status'] !== Status::PAUSED ? $row['avg'] : null;
            $spread[$avg === null ? 'none' : ($avg >= $critical ? 'critical' : ($avg >= $slowThreshold ? 'slow' : ($avg >= $moderate ? 'moderate' : 'fast')))]++;
        }

        return [
            'window'       => $window,
            'window_label' => $label,
            'range'        => $range,
            'rows'         => $rows,
            'fleet'        => $fleetFor(array_column($rows, 'id')),
            'spread'       => $spread,
            'thresholds'   => ['moderate' => $moderate, 'slow' => $slowThreshold, 'critical' => $critical],
            'summary'      => [
                'avg'            => $weight > 0 ? (int) round($sum / $weight) : null,
                'fastest'        => $fastest,
                'slowest'        => $slowest,
                'over_threshold' => $over,
                'websites'       => count($rows),
                'slow_threshold' => $slowThreshold,
            ],
        ];
    }

    /**
     * CSV rows for the response-time report.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{headers: list<string>, rows: list<list<mixed>>}
     */
    public static function exportResponseRows(array $rows): array
    {
        $headers = ['Website', 'Client', 'Domain', 'URL', 'Current Status', 'Current Response (ms)', 'Average (ms)', 'Fastest (ms)', 'Slowest (ms)', 'Checks', 'Failed Checks'];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [$r['name'], $r['client_name'], $r['domain'], $r['url'], $r['status_label'], $r['current'], $r['avg'], $r['min'], $r['max'], $r['checks'], $r['down']];
        }
        return ['headers' => $headers, 'rows' => $out];
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
    public function summarise(array $rows): array
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
