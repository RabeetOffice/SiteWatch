<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Daily aggregates per website. Rebuilt from raw checks for the affected day after every check,
 * so long-term reports never need the raw check history.
 */
final class DailyStatsRepository extends BaseRepository
{
    /**
     * Recompute the aggregate row for a website + local date from raw checks.
     */
    public function rebuild(int $websiteId, string $localDate): void
    {
        $from = IncidentRepository::localDateToUtc($localDate, false);
        $to = IncidentRepository::localDateToUtc($localDate, true);
        if ($from === null || $to === null) {
            return;
        }
        $row = $this->db->fetch(
            'SELECT COUNT(*) AS total, SUM(is_up = 1) AS up, SUM(is_up = 0) AS down,
                    AVG(CASE WHEN is_failure = 0 THEN response_time END) AS avg_rt,
                    MIN(CASE WHEN is_failure = 0 THEN response_time END) AS min_rt,
                    MAX(CASE WHEN is_failure = 0 THEN response_time END) AS max_rt
             FROM website_checks WHERE website_id = :id AND checked_at BETWEEN :from AND :to',
            ['id' => $websiteId, 'from' => $from, 'to' => $to]
        ) ?? [];

        $incidents = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM incidents WHERE website_id = :id AND started_at BETWEEN :from AND :to',
            ['id' => $websiteId, 'from' => $from, 'to' => $to]
        );

        $total = (int) ($row['total'] ?? 0);
        $up = (int) ($row['up'] ?? 0);
        $this->db->query(
            'INSERT INTO daily_stats (website_id, stat_date, total_checks, successful_checks, failed_checks, uptime_percentage,
                                      average_response_time, min_response_time, max_response_time, incident_count, updated_at)
             VALUES (:wid, :d, :total, :up, :down, :uptime, :avg, :min, :max, :inc, :now)
             ON DUPLICATE KEY UPDATE total_checks = VALUES(total_checks), successful_checks = VALUES(successful_checks),
                failed_checks = VALUES(failed_checks), uptime_percentage = VALUES(uptime_percentage),
                average_response_time = VALUES(average_response_time), min_response_time = VALUES(min_response_time),
                max_response_time = VALUES(max_response_time), incident_count = VALUES(incident_count), updated_at = VALUES(updated_at)',
            [
                'wid'    => $websiteId,
                'd'      => $localDate,
                'total'  => $total,
                'up'     => $up,
                'down'   => (int) ($row['down'] ?? 0),
                'uptime' => $total > 0 ? round($up / $total * 100, 3) : null,
                'avg'    => isset($row['avg_rt']) ? (int) round((float) $row['avg_rt']) : null,
                'min'    => isset($row['min_rt']) ? (int) $row['min_rt'] : null,
                'max'    => isset($row['max_rt']) ? (int) $row['max_rt'] : null,
                'inc'    => $incidents,
                'now'    => $this->now(),
            ]
        );
    }

    /**
     * Aggregated statistics for one website over a local date range (inclusive).
     *
     * @return array{total:int, up:int, down:int, uptime:?float, avg:?float, min:?int, max:?int, incidents:int, days:int}
     */
    public function rangeStats(int $websiteId, string $fromDate, string $toDate): array
    {
        $row = $this->db->fetch(
            'SELECT SUM(total_checks) AS total, SUM(successful_checks) AS up, SUM(failed_checks) AS down,
                    SUM(average_response_time * total_checks) / NULLIF(SUM(total_checks), 0) AS avg_rt,
                    MIN(min_response_time) AS min_rt, MAX(max_response_time) AS max_rt,
                    SUM(incident_count) AS incidents, COUNT(*) AS days
             FROM daily_stats WHERE website_id = :id AND stat_date BETWEEN :from AND :to',
            ['id' => $websiteId, 'from' => $fromDate, 'to' => $toDate]
        ) ?? [];
        return $this->format($row);
    }

    /**
     * Aggregated statistics across all websites for a date range.
     *
     * @return array{total:int, up:int, down:int, uptime:?float, avg:?float, min:?int, max:?int, incidents:int, days:int}
     */
    public function globalRangeStats(string $fromDate, string $toDate): array
    {
        $row = $this->db->fetch(
            'SELECT SUM(total_checks) AS total, SUM(successful_checks) AS up, SUM(failed_checks) AS down,
                    SUM(average_response_time * total_checks) / NULLIF(SUM(total_checks), 0) AS avg_rt,
                    MIN(min_response_time) AS min_rt, MAX(max_response_time) AS max_rt,
                    SUM(incident_count) AS incidents, COUNT(DISTINCT stat_date) AS days
             FROM daily_stats WHERE stat_date BETWEEN :from AND :to',
            ['from' => $fromDate, 'to' => $toDate]
        ) ?? [];
        return $this->format($row);
    }

    /**
     * Per-website aggregates for a date range (reports).
     *
     * @return array<int, array{total:int, up:int, down:int, uptime:?float, avg:?float, min:?int, max:?int, incidents:int, days:int}>
     */
    public function perWebsiteRangeStats(string $fromDate, string $toDate): array
    {
        $rows = $this->db->fetchAll(
            'SELECT website_id, SUM(total_checks) AS total, SUM(successful_checks) AS up, SUM(failed_checks) AS down,
                    SUM(average_response_time * total_checks) / NULLIF(SUM(total_checks), 0) AS avg_rt,
                    MIN(min_response_time) AS min_rt, MAX(max_response_time) AS max_rt,
                    SUM(incident_count) AS incidents, COUNT(*) AS days
             FROM daily_stats WHERE stat_date BETWEEN :from AND :to GROUP BY website_id',
            ['from' => $fromDate, 'to' => $toDate]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['website_id']] = $this->format($row);
        }
        return $out;
    }

    /**
     * Daily rows for a website (timeline bars / uptime trend).
     *
     * @return array<int, array<string, mixed>>
     */
    public function daily(int $websiteId, string $fromDate, string $toDate): array
    {
        return $this->db->fetchAll(
            'SELECT stat_date, total_checks, successful_checks, failed_checks, uptime_percentage,
                    average_response_time, min_response_time, max_response_time, incident_count
             FROM daily_stats WHERE website_id = :id AND stat_date BETWEEN :from AND :to ORDER BY stat_date ASC',
            ['id' => $websiteId, 'from' => $fromDate, 'to' => $toDate]
        );
    }

    /**
     * Global daily uptime / response trend across all websites.
     *
     * @return array<int, array{date:string, uptime:?float, avg:?float, checks:int, incidents:int}>
     */
    public function globalDaily(string $fromDate, string $toDate): array
    {
        $rows = $this->db->fetchAll(
            'SELECT stat_date, SUM(total_checks) AS total, SUM(successful_checks) AS up,
                    SUM(average_response_time * total_checks) / NULLIF(SUM(total_checks), 0) AS avg_rt,
                    SUM(incident_count) AS incidents
             FROM daily_stats WHERE stat_date BETWEEN :from AND :to GROUP BY stat_date ORDER BY stat_date ASC',
            ['from' => $fromDate, 'to' => $toDate]
        );
        return array_map(static fn (array $r): array => [
            'date'      => (string) $r['stat_date'],
            'uptime'    => (int) $r['total'] > 0 ? round((int) $r['up'] / (int) $r['total'] * 100, 3) : null,
            'avg'       => $r['avg_rt'] === null ? null : round((float) $r['avg_rt']),
            'checks'    => (int) $r['total'],
            'incidents' => (int) $r['incidents'],
        ], $rows);
    }

    /**
     * Lifetime uptime for a website (all daily rows).
     *
     * @return array{total:int, up:int, down:int, uptime:?float, avg:?float, min:?int, max:?int, incidents:int, days:int}
     */
    public function lifetime(int $websiteId): array
    {
        $row = $this->db->fetch(
            'SELECT SUM(total_checks) AS total, SUM(successful_checks) AS up, SUM(failed_checks) AS down,
                    SUM(average_response_time * total_checks) / NULLIF(SUM(total_checks), 0) AS avg_rt,
                    MIN(min_response_time) AS min_rt, MAX(max_response_time) AS max_rt,
                    SUM(incident_count) AS incidents, COUNT(*) AS days
             FROM daily_stats WHERE website_id = :id',
            ['id' => $websiteId]
        ) ?? [];
        return $this->format($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{total:int, up:int, down:int, uptime:?float, avg:?float, min:?int, max:?int, incidents:int, days:int}
     */
    private function format(array $row): array
    {
        $total = (int) ($row['total'] ?? 0);
        $up = (int) ($row['up'] ?? 0);
        return [
            'total'     => $total,
            'up'        => $up,
            'down'      => (int) ($row['down'] ?? 0),
            'uptime'    => $total > 0 ? round($up / $total * 100, 3) : null,
            'avg'       => isset($row['avg_rt']) ? round((float) $row['avg_rt']) : null,
            'min'       => isset($row['min_rt']) ? (int) $row['min_rt'] : null,
            'max'       => isset($row['max_rt']) ? (int) $row['max_rt'] : null,
            'incidents' => (int) ($row['incidents'] ?? 0),
            'days'      => (int) ($row['days'] ?? 0),
        ];
    }
}
