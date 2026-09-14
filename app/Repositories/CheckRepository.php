<?php

declare(strict_types=1);

namespace App\Repositories;

final class CheckRepository extends BaseRepository
{
    /** @param array<string, mixed> $data */
    public function record(array $data): int
    {
        $data['checked_at'] ??= $this->now();
        if (isset($data['error_message'])) {
            $data['error_message'] = mb_substr((string) $data['error_message'], 0, 500);
        }
        if (isset($data['final_url'])) {
            $data['final_url'] = mb_substr((string) $data['final_url'], 0, 2048);
        }
        return $this->db->insert('website_checks', $data);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM website_checks WHERE id = :id', ['id' => $id]);
    }

    /**
     * Latest checks for a website, newest first.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function recent(int $websiteId, int $limit, int $offset = 0, ?string $onlyFailures = null): array
    {
        $where = 'website_id = :id';
        $params = ['id' => $websiteId];
        if ($onlyFailures === 'failures') {
            $where .= ' AND is_failure = 1';
        }
        $rows = $this->db->fetchAll(
            "SELECT id, status, is_failure, is_up, http_status, response_time, error_type, error_message,
                    redirect_count, final_url, source, checked_at
             FROM website_checks WHERE {$where}
             ORDER BY checked_at DESC, id DESC
             LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $params
        );
        $total = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM website_checks WHERE {$where}", $params);
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * The most recent N checks for the visual timeline (oldest first).
     *
     * @return array<int, array<string, mixed>>
     */
    public function timeline(int $websiteId, int $limit = 120): array
    {
        $rows = $this->db->fetchAll(
            'SELECT status, is_failure, is_up, http_status, response_time, error_type, checked_at
             FROM website_checks WHERE website_id = :id
             ORDER BY checked_at DESC, id DESC LIMIT ' . max(1, $limit),
            ['id' => $websiteId]
        );
        return array_reverse($rows);
    }

    /**
     * Flag the most recent consecutive failures of a website as confirmed downtime.
     * Returns the distinct local dates touched (so daily stats can be rebuilt) and the
     * timestamp of the earliest failure in the streak (the real start of the incident).
     *
     * @return array{dates: array<int, string>, earliest: ?string}
     */
    public function markRecentFailuresAsDown(int $websiteId, int $count): array
    {
        if ($count <= 0) {
            return ['dates' => [], 'earliest' => null];
        }
        $rows = $this->db->fetchAll(
            'SELECT id, checked_at FROM website_checks WHERE website_id = :id AND is_failure = 1
             ORDER BY checked_at DESC, id DESC LIMIT ' . $count,
            ['id' => $websiteId]
        );
        if ($rows === []) {
            return ['dates' => [], 'earliest' => null];
        }
        [$placeholders, $params] = $this->in(array_map(static fn ($r) => (int) $r['id'], $rows));
        $this->db->query("UPDATE website_checks SET is_up = 0 WHERE id IN ($placeholders)", $params);

        $dates = [];
        $earliest = null;
        foreach ($rows as $row) {
            $checkedAt = (string) $row['checked_at'];
            if ($earliest === null || $checkedAt < $earliest) {
                $earliest = $checkedAt;
            }
            $local = to_local($checkedAt);
            if ($local) {
                $dates[$local->format('Y-m-d')] = true;
            }
        }
        return ['dates' => array_keys($dates), 'earliest' => $earliest];
    }

    // ------------------------------------------------------------------
    // Aggregations
    // ------------------------------------------------------------------

    /**
     * Uptime and response statistics from raw checks in a time window.
     *
     * @return array{total:int, up:int, down:int, uptime:?float, avg:?float, min:?int, max:?int}
     */
    public function windowStats(int $websiteId, string $fromUtc, ?string $toUtc = null): array
    {
        $row = $this->db->fetch(
            'SELECT COUNT(*) AS total, SUM(is_up = 1) AS up, SUM(is_up = 0) AS down,
                    AVG(CASE WHEN is_failure = 0 THEN response_time END) AS avg_rt,
                    MIN(CASE WHEN is_failure = 0 THEN response_time END) AS min_rt,
                    MAX(CASE WHEN is_failure = 0 THEN response_time END) AS max_rt
             FROM website_checks
             WHERE website_id = :id AND checked_at >= :from AND checked_at <= :to',
            ['id' => $websiteId, 'from' => $fromUtc, 'to' => $toUtc ?? $this->now()]
        ) ?? [];
        $total = (int) ($row['total'] ?? 0);
        $up = (int) ($row['up'] ?? 0);
        return [
            'total'  => $total,
            'up'     => $up,
            'down'   => (int) ($row['down'] ?? 0),
            'uptime' => $total > 0 ? round($up / $total * 100, 3) : null,
            'avg'    => isset($row['avg_rt']) ? (float) $row['avg_rt'] : null,
            'min'    => isset($row['min_rt']) ? (int) $row['min_rt'] : null,
            'max'    => isset($row['max_rt']) ? (int) $row['max_rt'] : null,
        ];
    }

    /**
     * Response-time series bucketed by N seconds for a single website.
     *
     * @return array<int, array{t:int, avg:?float, max:?int, checks:int, down:int}>
     */
    public function responseSeries(int $websiteId, string $fromUtc, int $bucketSeconds): array
    {
        $bucket = max(60, $bucketSeconds);
        $rows = $this->db->fetchAll(
            "SELECT FLOOR(UNIX_TIMESTAMP(checked_at) / {$bucket}) * {$bucket} AS t,
                    AVG(CASE WHEN is_failure = 0 THEN response_time END) AS avg_rt,
                    MAX(CASE WHEN is_failure = 0 THEN response_time END) AS max_rt,
                    COUNT(*) AS checks, SUM(is_up = 0) AS down
             FROM website_checks
             WHERE website_id = :id AND checked_at >= :from
             GROUP BY t ORDER BY t ASC",
            ['id' => $websiteId, 'from' => $fromUtc]
        );
        return array_map(static fn (array $r): array => [
            't'      => (int) $r['t'],
            'avg'    => $r['avg_rt'] === null ? null : round((float) $r['avg_rt']),
            'max'    => $r['max_rt'] === null ? null : (int) $r['max_rt'],
            'checks' => (int) $r['checks'],
            'down'   => (int) $r['down'],
        ], $rows);
    }

    /**
     * Global average response-time series across all websites (dashboard chart).
     *
     * @return array<int, array{t:int, avg:?float, checks:int}>
     */
    public function globalResponseSeries(string $fromUtc, int $bucketSeconds): array
    {
        $bucket = max(60, $bucketSeconds);
        $rows = $this->db->fetchAll(
            "SELECT FLOOR(UNIX_TIMESTAMP(checked_at) / {$bucket}) * {$bucket} AS t,
                    AVG(CASE WHEN is_failure = 0 THEN response_time END) AS avg_rt,
                    COUNT(*) AS checks
             FROM website_checks
             WHERE checked_at >= :from
             GROUP BY t ORDER BY t ASC",
            ['from' => $fromUtc]
        );
        return array_map(static fn (array $r): array => [
            't'      => (int) $r['t'],
            'avg'    => $r['avg_rt'] === null ? null : round((float) $r['avg_rt']),
            'checks' => (int) $r['checks'],
        ], $rows);
    }

    /**
     * Per-website response statistics over a window (Response Times page).
     *
     * @return array<int, array<string, mixed>> keyed by website_id
     */
    public function perWebsiteStats(string $fromUtc): array
    {
        $rows = $this->db->fetchAll(
            'SELECT website_id, COUNT(*) AS checks,
                    AVG(CASE WHEN is_failure = 0 THEN response_time END) AS avg_rt,
                    MIN(CASE WHEN is_failure = 0 THEN response_time END) AS min_rt,
                    MAX(CASE WHEN is_failure = 0 THEN response_time END) AS max_rt,
                    SUM(is_up = 0) AS down
             FROM website_checks WHERE checked_at >= :from GROUP BY website_id',
            ['from' => $fromUtc]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['website_id']] = $r;
        }
        return $out;
    }

    /**
     * Delete checks older than the cutoff in batches. Returns the number of rows removed.
     */
    public function purgeOlderThan(string $cutoffUtc, int $batchSize = 5000, int $maxBatches = 200): int
    {
        $deleted = 0;
        for ($i = 0; $i < $maxBatches; $i++) {
            $count = $this->db->query(
                'DELETE FROM website_checks WHERE checked_at < :cutoff ORDER BY id ASC LIMIT ' . max(100, $batchSize),
                ['cutoff' => $cutoffUtc]
            )->rowCount();
            $deleted += $count;
            if ($count < $batchSize) {
                break;
            }
        }
        return $deleted;
    }

    public function count(): int
    {
        return (int) $this->db->fetchColumn('SELECT COUNT(*) FROM website_checks');
    }
}
