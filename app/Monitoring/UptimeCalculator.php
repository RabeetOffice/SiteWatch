<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Repositories\CheckRepository;
use App\Repositories\DailyStatsRepository;
use DateTimeImmutable;

/**
 * Uptime methodology (documented in README):
 *
 *   uptime% = up_checks / total_checks * 100
 *
 * - A check counts as "down" only when it belongs to a CONFIRMED incident (is_up = 0). The one or
 *   two failures before the failure threshold is reached are retro-actively marked as down when
 *   the incident is confirmed; isolated blips that never become an incident do not reduce uptime.
 * - Paused websites and periods without checks contribute nothing (no data ≠ downtime).
 * - The rolling 24h figure is computed from raw checks; 7/30/90-day and lifetime figures come
 *   from the daily_stats aggregates (calendar days in the application timezone), so they remain
 *   available after raw checks have been purged by the retention policy.
 */
final class UptimeCalculator
{
    public function __construct(
        private readonly CheckRepository $checks,
        private readonly DailyStatsRepository $dailyStats
    ) {
    }

    /**
     * @return array{uptime_24h: ?float, uptime_7d: ?float, uptime_30d: ?float, uptime_90d: ?float, uptime_all: ?float,
     *               avg_24h: ?float, avg_7d: ?float, avg_30d: ?float, min_24h: ?int, max_24h: ?int, checks_24h: int,
     *               incidents_30d: int, incidents_month: int}
     */
    public function forWebsite(int $websiteId): array
    {
        $w24 = $this->checks->windowStats($websiteId, utc_now()->modify('-24 hours')->format('Y-m-d H:i:s'));
        $d7 = $this->dailyStats->rangeStats($websiteId, self::localDate(-6), self::localDate());
        $d30 = $this->dailyStats->rangeStats($websiteId, self::localDate(-29), self::localDate());
        $d90 = $this->dailyStats->rangeStats($websiteId, self::localDate(-89), self::localDate());
        $all = $this->dailyStats->lifetime($websiteId);
        $month = $this->dailyStats->rangeStats($websiteId, self::monthStart(), self::localDate());

        return [
            'uptime_24h'      => $w24['uptime'],
            'uptime_7d'       => $d7['uptime'],
            'uptime_30d'      => $d30['uptime'],
            'uptime_90d'      => $d90['uptime'],
            'uptime_all'      => $all['uptime'],
            'avg_24h'         => $w24['avg'] !== null ? round($w24['avg']) : null,
            'avg_7d'          => $d7['avg'],
            'avg_30d'         => $d30['avg'],
            'min_24h'         => $w24['min'],
            'max_24h'         => $w24['max'],
            'checks_24h'      => $w24['total'],
            'incidents_30d'   => $d30['incidents'],
            'incidents_month' => $month['incidents'],
        ];
    }

    /**
     * Fleet-wide figures for the dashboard.
     *
     * @return array{uptime_30d: ?float, uptime_24h: ?float, avg_24h: ?float, incidents_month: int}
     */
    public function global(): array
    {
        $d30 = $this->dailyStats->globalRangeStats(self::localDate(-29), self::localDate());
        $today = $this->dailyStats->globalRangeStats(self::localDate(), self::localDate());
        $month = $this->dailyStats->globalRangeStats(self::monthStart(), self::localDate());
        return [
            'uptime_30d'      => $d30['uptime'],
            'uptime_24h'      => $today['uptime'],
            'avg_24h'         => $today['avg'],
            'incidents_month' => $month['incidents'],
        ];
    }

    /**
     * Local calendar date N days from today (application timezone).
     */
    public static function localDate(int $offsetDays = 0): string
    {
        $now = utc_now()->setTimezone(app_timezone());
        return $now->modify(($offsetDays >= 0 ? '+' : '') . $offsetDays . ' days')->format('Y-m-d');
    }

    public static function monthStart(): string
    {
        return utc_now()->setTimezone(app_timezone())->format('Y-m-01');
    }

    public static function localDateTime(): DateTimeImmutable
    {
        return utc_now()->setTimezone(app_timezone());
    }
}
