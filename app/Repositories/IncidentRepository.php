<?php

declare(strict_types=1);

namespace App\Repositories;

final class IncidentRepository extends BaseRepository
{
    public const TYPES = ['DOWNTIME', 'WORDPRESS_CRITICAL', 'DATABASE_ERROR', 'HTTP_ERROR', 'TIMEOUT', 'DNS_ERROR', 'SSL_ERROR', 'REDIRECT_ERROR', 'MAINTENANCE', 'PERFORMANCE'];

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetch(
            'SELECT i.*, w.name AS website_name, w.client_name, w.domain, w.url
             FROM incidents i JOIN websites w ON w.id = i.website_id WHERE i.id = :id',
            ['id' => $id]
        );
    }

    /** @return array<string, mixed>|null */
    public function openFor(int $websiteId): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM incidents WHERE website_id = :id AND status = 'OPEN' ORDER BY started_at DESC LIMIT 1",
            ['id' => $websiteId]
        );
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $now = $this->now();
        $data['status'] ??= 'OPEN';
        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        if (isset($data['error_message'])) {
            $data['error_message'] = mb_substr((string) $data['error_message'], 0, 1000);
        }
        if (isset($data['title'])) {
            $data['title'] = mb_substr((string) $data['title'], 0, 190);
        }
        return $this->db->insert('incidents', $data);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): int
    {
        $data['updated_at'] = $this->now();
        return $this->db->update('incidents', $data, 'id = :id', ['id' => $id]);
    }

    public function resolve(int $id, string $resolvedAtUtc, ?int $httpStatus, ?int $responseTime): void
    {
        $incident = $this->db->fetch('SELECT started_at FROM incidents WHERE id = :id', ['id' => $id]);
        if ($incident === null) {
            return;
        }
        $duration = max(0, strtotime($resolvedAtUtc . ' UTC') - strtotime($incident['started_at'] . ' UTC'));
        $this->update($id, [
            'status'                 => 'RESOLVED',
            'resolved_at'            => $resolvedAtUtc,
            'duration_seconds'       => $duration,
            'resolved_http_status'   => $httpStatus,
            'resolved_response_time' => $responseTime,
        ]);
    }

    // ------------------------------------------------------------------
    // Queries
    // ------------------------------------------------------------------

    public function countOpen(): int
    {
        return (int) $this->db->fetchColumn("SELECT COUNT(*) FROM incidents WHERE status = 'OPEN'");
    }

    public function countSince(string $fromUtc, ?int $websiteId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM incidents WHERE started_at >= :from';
        $params = ['from' => $fromUtc];
        if ($websiteId !== null) {
            $sql .= ' AND website_id = :wid';
            $params['wid'] = $websiteId;
        }
        return (int) $this->db->fetchColumn($sql, $params);
    }

    /**
     * Incidents per local calendar day for the last N days.
     *
     * @return array<string, int> 'Y-m-d' => count
     */
    public function perDay(int $days = 30): array
    {
        $tz = app_timezone();
        $start = utc_now()->setTimezone($tz)->modify('-' . ($days - 1) . ' days')->setTime(0, 0);
        $rows = $this->db->fetchAll(
            'SELECT started_at FROM incidents WHERE started_at >= :from',
            ['from' => $start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')]
        );
        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $out[$start->modify("+{$i} days")->format('Y-m-d')] = 0;
        }
        foreach ($rows as $row) {
            $day = to_local((string) $row['started_at'])?->format('Y-m-d');
            if ($day !== null && isset($out[$day])) {
                $out[$day]++;
            }
        }
        return $out;
    }

    /**
     * Total confirmed downtime seconds inside a window, per website (overlap-aware).
     *
     * @return array<int, int> website_id => seconds
     */
    public function downtimeSecondsPerWebsite(string $fromUtc, string $toUtc): array
    {
        $rows = $this->db->fetchAll(
            'SELECT website_id,
                    SUM(GREATEST(0, TIMESTAMPDIFF(SECOND, GREATEST(started_at, :from1), LEAST(COALESCE(resolved_at, :now1), :to1)))) AS secs
             FROM incidents
             WHERE started_at <= :to2 AND (resolved_at IS NULL OR resolved_at >= :from2)
             GROUP BY website_id',
            ['from1' => $fromUtc, 'now1' => $this->now(), 'to1' => $toUtc, 'to2' => $toUtc, 'from2' => $fromUtc]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['website_id']] = (int) $row['secs'];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $criteria q, website_id, client, type, status, from, to
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function search(array $criteria, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($criteria);
        $rows = $this->db->fetchAll(
            "SELECT i.*, w.name AS website_name, w.client_name, w.domain, w.url, w.favicon_url
             FROM incidents i JOIN websites w ON w.id = i.website_id
             WHERE {$where}
             ORDER BY (i.status = 'OPEN') DESC, i.started_at DESC, i.id DESC
             LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $params
        );
        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM incidents i JOIN websites w ON w.id = i.website_id WHERE {$where}",
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array<int, array<string, mixed>>
     */
    public function searchAll(array $criteria, int $limit = 10000): array
    {
        [$where, $params] = $this->buildWhere($criteria);
        return $this->db->fetchAll(
            "SELECT i.*, w.name AS website_name, w.client_name, w.domain, w.url
             FROM incidents i JOIN websites w ON w.id = i.website_id
             WHERE {$where} ORDER BY i.started_at DESC LIMIT " . max(1, $limit),
            $params
        );
    }

    /**
     * Recent incidents for the dashboard.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 8): array
    {
        return $this->db->fetchAll(
            "SELECT i.*, w.name AS website_name, w.client_name, w.domain, w.favicon_url
             FROM incidents i JOIN websites w ON w.id = i.website_id
             ORDER BY (i.status = 'OPEN') DESC, i.started_at DESC LIMIT " . max(1, $limit)
        );
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(array $criteria): array
    {
        $where = ['1=1'];
        $params = [];

        $q = trim((string) ($criteria['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(w.name LIKE :q OR w.domain LIKE :q OR w.client_name LIKE :q OR i.title LIKE :q OR i.error_message LIKE :q)';
            $params['q'] = $this->like($q);
        }
        if (!empty($criteria['website_id'])) {
            $where[] = 'i.website_id = :wid';
            $params['wid'] = (int) $criteria['website_id'];
        }
        $client = trim((string) ($criteria['client'] ?? ''));
        if ($client !== '') {
            $where[] = 'w.client_name = :client';
            $params['client'] = $client;
        }
        $type = strtoupper(trim((string) ($criteria['type'] ?? '')));
        if ($type !== '' && in_array($type, self::TYPES, true)) {
            $where[] = 'i.type = :type';
            $params['type'] = $type;
        }
        $status = strtoupper(trim((string) ($criteria['status'] ?? '')));
        if (in_array($status, ['OPEN', 'RESOLVED'], true)) {
            $where[] = 'i.status = :status';
            $params['status'] = $status;
        }
        if (!empty($criteria['from'])) {
            $from = self::localDateToUtc((string) $criteria['from'], false);
            if ($from !== null) {
                $where[] = 'i.started_at >= :from';
                $params['from'] = $from;
            }
        }
        if (!empty($criteria['to'])) {
            $to = self::localDateToUtc((string) $criteria['to'], true);
            if ($to !== null) {
                $where[] = 'i.started_at <= :to';
                $params['to'] = $to;
            }
        }
        return [implode(' AND ', $where), $params];
    }

    /**
     * Convert a local Y-m-d date (application timezone) to a UTC boundary timestamp.
     */
    public static function localDateToUtc(string $date, bool $endOfDay): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        try {
            $dt = new \DateTimeImmutable($date . ($endOfDay ? ' 23:59:59' : ' 00:00:00'), app_timezone());
        } catch (\Throwable) {
            return null;
        }
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
