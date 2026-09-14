<?php

declare(strict_types=1);

namespace App\Services;

use App\Monitoring\MonitoringScheduler;
use App\Monitoring\Status;
use App\Monitoring\UptimeCalculator;
use App\Repositories\ActivityRepository;
use App\Repositories\CheckRepository;
use App\Repositories\DailyStatsRepository;
use App\Repositories\IncidentRepository;
use App\Repositories\WebsiteRepository;

/**
 * Aggregated numbers for the dashboard. Every figure comes from the database.
 */
final class DashboardService
{
    public function __construct(
        private readonly WebsiteRepository $websites,
        private readonly IncidentRepository $incidents,
        private readonly CheckRepository $checks,
        private readonly DailyStatsRepository $dailyStats,
        private readonly ActivityRepository $activity,
        private readonly MonitoringScheduler $scheduler,
        private readonly UptimeCalculator $uptime
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        $counts = $this->websites->statusCounts();
        $total = array_sum($counts);
        $paused = $counts[Status::PAUSED] ?? 0;
        $pending = $counts[Status::PENDING] ?? 0;

        $online = 0;
        $down = 0;
        $warnings = 0;
        foreach ($counts as $status => $count) {
            if ($status === Status::PAUSED || $status === Status::PENDING) {
                continue;
            }
            match (Status::severity($status)) {
                Status::SEVERITY_OK      => $online += $count,
                Status::SEVERITY_DOWN    => $down += $count,
                Status::SEVERITY_WARNING => $warnings += $count,
                default                  => null,
            };
        }

        $avgResponse = $this->websites->averageResponseTime();
        $global = $this->uptime->global();
        $engine = $this->scheduler->engineStatus();

        return [
            'kpi' => [
                'total'               => $total,
                'online'              => $online,
                'down'                => $down,
                'warnings'            => $warnings,
                'paused'              => $paused,
                'pending'             => $pending,
                'avg_response'        => $avgResponse !== null ? (int) round($avgResponse) : null,
                'avg_response_label'  => format_ms($avgResponse),
                'open_incidents'      => $this->incidents->countOpen(),
                'ssl_expiring'        => $this->websites->sslExpiringCount(30),
            ],
            'health' => [
                'healthy'  => $online,
                'warning'  => $warnings,
                'down'     => $down,
                'paused'   => $paused + $pending,
                'monitored' => $total - $paused,
            ],
            'overview' => [
                'uptime_30d'            => $global['uptime_30d'],
                'uptime_30d_label'      => format_uptime($global['uptime_30d']),
                'avg_response_24h'      => $global['avg_24h'],
                'avg_response_24h_label' => format_ms($global['avg_24h']),
                'incidents_month'       => $global['incidents_month'],
                'incidents_24h'         => $this->incidents->countSince(utc_now()->modify('-24 hours')->format('Y-m-d H:i:s')),
            ],
            'engine'       => $engine,
            'generated_at' => utc_now()->format('Y-m-d H:i:s'),
            'local_time'   => format_datetime(utc_now()->format('Y-m-d H:i:s')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function charts(): array
    {
        // Response time trend: last 24 hours, 30-minute buckets, fleet average.
        $series = $this->checks->globalResponseSeries(utc_now()->modify('-24 hours')->format('Y-m-d H:i:s'), 1800);
        $responseTrend = ['labels' => [], 'values' => [], 'timestamps' => []];
        foreach ($series as $point) {
            $local = (new \DateTimeImmutable('@' . $point['t']))->setTimezone(app_timezone());
            $responseTrend['labels'][] = $local->format('H:i');
            $responseTrend['timestamps'][] = $local->format('j M · g:i A');
            $responseTrend['values'][] = $point['avg'];
        }

        // Uptime trend: last 30 days, fleet daily uptime.
        $from = UptimeCalculator::localDate(-29);
        $to = UptimeCalculator::localDate();
        $daily = [];
        foreach ($this->dailyStats->globalDaily($from, $to) as $row) {
            $daily[$row['date']] = $row;
        }
        $uptimeTrend = ['labels' => [], 'values' => [], 'dates' => []];
        $cursor = new \DateTimeImmutable($from, app_timezone());
        for ($i = 0; $i < 30; $i++) {
            $date = $cursor->modify("+{$i} days")->format('Y-m-d');
            $uptimeTrend['labels'][] = $cursor->modify("+{$i} days")->format('j M');
            $uptimeTrend['dates'][] = $date;
            $uptimeTrend['values'][] = $daily[$date]['uptime'] ?? null;
        }

        // Incidents per day, last 30 days.
        $perDay = $this->incidents->perDay(30);
        $incidents = ['labels' => [], 'values' => [], 'dates' => []];
        foreach ($perDay as $date => $count) {
            $incidents['labels'][] = (new \DateTimeImmutable($date, app_timezone()))->format('j M');
            $incidents['dates'][] = $date;
            $incidents['values'][] = $count;
        }

        // Status distribution.
        $counts = $this->websites->statusCounts();
        $distribution = ['Online' => 0, 'Warning' => 0, 'Down' => 0, 'Paused' => 0];
        foreach ($counts as $status => $count) {
            if ($status === Status::PAUSED || $status === Status::PENDING) {
                $distribution['Paused'] += $count;
                continue;
            }
            match (Status::severity($status)) {
                Status::SEVERITY_OK      => $distribution['Online'] += $count,
                Status::SEVERITY_DOWN    => $distribution['Down'] += $count,
                Status::SEVERITY_WARNING => $distribution['Warning'] += $count,
                default                  => $distribution['Paused'] += $count,
            };
        }

        return [
            'response_trend'      => $responseTrend,
            'uptime_trend'        => $uptimeTrend,
            'incidents_30d'       => $incidents,
            'status_distribution' => ['labels' => array_keys($distribution), 'values' => array_values($distribution)],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function recentIncidents(int $limit = 6): array
    {
        $out = [];
        foreach ($this->incidents->recent($limit) as $row) {
            $out[] = ReportService::presentIncident($row);
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    public function recentActivity(int $limit = 8): array
    {
        $out = [];
        foreach ($this->activity->recent($limit) as $row) {
            $out[] = ActivityService::present($row);
        }
        return $out;
    }
}
