<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Monitoring\Status;

final class WebsiteRepository extends BaseRepository
{
    public const TYPES = ['wordpress', 'woocommerce', 'other'];
    public const INTERVALS = [1, 2, 5, 10, 15, 30];

    public const SORTS = ['status', 'response', 'uptime', 'last_checked', 'client', 'name', 'newest', 'oldest'];
    public const FILTERS = ['all', 'online', 'down', 'critical', 'warning', 'slow', 'ssl_expiring', 'paused'];

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM websites WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function findByUrl(string $normalizedUrl): ?array
    {
        return $this->db->fetch('SELECT * FROM websites WHERE url_hash = :h LIMIT 1', ['h' => hash('sha256', $normalizedUrl)]);
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function findMany(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        [$placeholders, $params] = $this->in($ids);
        return $this->db->fetchAll("SELECT * FROM websites WHERE id IN ($placeholders)", $params);
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->fetchAll('SELECT * FROM websites ORDER BY name ASC');
    }

    /** @return array<int, array{id:int,name:string,client_name:string,domain:string}> */
    public function options(): array
    {
        return $this->db->fetchAll('SELECT id, name, client_name, domain FROM websites ORDER BY name ASC');
    }

    /** @return array<int, string> */
    public function clients(): array
    {
        return $this->db->fetchColumnAll("SELECT DISTINCT client_name FROM websites WHERE client_name <> '' ORDER BY client_name ASC");
    }

    public function count(): int
    {
        return (int) $this->db->fetchColumn('SELECT COUNT(*) FROM websites');
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $now = $this->now();
        $data['url_hash'] = hash('sha256', (string) $data['url']);
        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        return $this->db->insert('websites', $data);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): int
    {
        if (isset($data['url'])) {
            $data['url_hash'] = hash('sha256', (string) $data['url']);
        }
        $data['updated_at'] = $this->now();
        return $this->db->update('websites', $data, 'id = :id', ['id' => $id]);
    }

    public function delete(int $id): int
    {
        return $this->db->delete('websites', 'id = :id', ['id' => $id]);
    }

    /** @param array<int, int> $ids */
    public function deleteMany(array $ids): int
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return 0;
        }
        [$placeholders, $params] = $this->in($ids);
        return $this->db->query("DELETE FROM websites WHERE id IN ($placeholders)", $params)->rowCount();
    }

    // ------------------------------------------------------------------
    // Scheduling
    // ------------------------------------------------------------------

    /**
     * Websites that are due for a check, oldest due first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function due(int $limit): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM websites
             WHERE monitoring_enabled = 1 AND (next_check_at IS NULL OR next_check_at <= :now)
             ORDER BY next_check_at IS NULL DESC, next_check_at ASC, id ASC
             LIMIT ' . max(1, $limit),
            ['now' => $this->now()]
        );
    }

    /**
     * HTTPS websites whose certificate information is stale.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sslDue(int $limit, int $maxAgeHours): array
    {
        $cutoff = utc_now()->modify('-' . max(1, $maxAgeHours) . ' hours')->format('Y-m-d H:i:s');
        return $this->db->fetchAll(
            "SELECT * FROM websites
             WHERE monitoring_enabled = 1 AND url LIKE 'https://%'
               AND (ssl_checked_at IS NULL OR ssl_checked_at <= :cutoff)
             ORDER BY ssl_checked_at IS NULL DESC, ssl_checked_at ASC
             LIMIT " . max(1, $limit),
            ['cutoff' => $cutoff]
        );
    }

    // ------------------------------------------------------------------
    // Listing / search
    // ------------------------------------------------------------------

    /**
     * @param array{q?: string, filter?: string, client?: string, sort?: string, dir?: string} $criteria
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function search(array $criteria, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($criteria);
        $orderBy = $this->buildOrder($criteria['sort'] ?? 'status', $criteria['dir'] ?? '');

        $d30 = utc_now()->setTimezone(app_timezone())->modify('-30 days')->format('Y-m-d');
        $params['d30'] = $d30;

        $sql = "SELECT w.*,
                    (SELECT ROUND(SUM(ds.successful_checks) / NULLIF(SUM(ds.total_checks), 0) * 100, 3)
                       FROM daily_stats ds WHERE ds.website_id = w.id AND ds.stat_date >= :d30) AS uptime_30d,
                    (SELECT COUNT(*) FROM incidents i WHERE i.website_id = w.id AND i.status = 'OPEN') AS open_incidents
                FROM websites w
                WHERE {$where}
                ORDER BY {$orderBy}
                LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset);

        $rows = $this->db->fetchAll($sql, $params);
        unset($params['d30']);
        $total = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM websites w WHERE {$where}", $params);

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * IDs matching a search (used for bulk selection / export).
     *
     * @param array{q?: string, filter?: string, client?: string} $criteria
     * @return array<int, array<string, mixed>>
     */
    public function searchAll(array $criteria): array
    {
        [$where, $params] = $this->buildWhere($criteria);
        return $this->db->fetchAll("SELECT * FROM websites w WHERE {$where} ORDER BY w.name ASC", $params);
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
            // Native prepared statements cannot reuse a named placeholder, so each comparison gets its own.
            $where[] = '(w.name LIKE :q1 OR w.domain LIKE :q2 OR w.client_name LIKE :q3 OR w.url LIKE :q4)';
            foreach ([1, 2, 3, 4] as $i) {
                $params['q' . $i] = $this->like($q);
            }
        }

        $client = trim((string) ($criteria['client'] ?? ''));
        if ($client !== '') {
            $where[] = 'w.client_name = :client';
            $params['client'] = $client;
        }

        $filter = (string) ($criteria['filter'] ?? 'all');
        switch ($filter) {
            case 'online':
                $where[] = "w.monitoring_enabled = 1 AND w.status = 'ONLINE'";
                break;
            case 'down':
                [$ph, $p] = $this->in(Status::withSeverity(Status::SEVERITY_DOWN), 'st');
                $where[] = "w.monitoring_enabled = 1 AND w.status IN ($ph)";
                $params += $p;
                break;
            case 'critical':
                $where[] = "w.monitoring_enabled = 1 AND w.status IN ('CRITICAL_ERROR','DATABASE_ERROR')";
                break;
            case 'warning':
                [$ph, $p] = $this->in(Status::withSeverity(Status::SEVERITY_WARNING), 'st');
                $where[] = "w.monitoring_enabled = 1 AND w.status IN ($ph)";
                $params += $p;
                break;
            case 'slow':
                $where[] = "w.monitoring_enabled = 1 AND w.status IN ('SLOW','CRITICAL_PERFORMANCE')";
                break;
            case 'ssl_expiring':
                $where[] = '(w.ssl_valid = 0 OR (w.ssl_expires_at IS NOT NULL AND w.ssl_expires_at <= :ssl_cutoff))';
                $params['ssl_cutoff'] = utc_now()->modify('+30 days')->format('Y-m-d H:i:s');
                break;
            case 'paused':
                $where[] = 'w.monitoring_enabled = 0';
                break;
            default:
                break;
        }

        return [implode(' AND ', $where), $params];
    }

    private function buildOrder(string $sort, string $dir): string
    {
        $dir = strtolower($dir) === 'asc' ? 'ASC' : (strtolower($dir) === 'desc' ? 'DESC' : '');
        return match ($sort) {
            'response'     => 'w.last_response_time IS NULL ASC, w.last_response_time ' . ($dir ?: 'DESC') . ', w.name ASC',
            'uptime'       => 'uptime_30d IS NULL ASC, uptime_30d ' . ($dir ?: 'ASC') . ', w.name ASC',
            'last_checked' => 'w.last_checked_at IS NULL ASC, w.last_checked_at ' . ($dir ?: 'DESC') . ', w.name ASC',
            'client'       => 'w.client_name ' . ($dir ?: 'ASC') . ', w.name ASC',
            'name'         => 'w.name ' . ($dir ?: 'ASC'),
            'newest'       => 'w.created_at DESC, w.id DESC',
            'oldest'       => 'w.created_at ASC, w.id ASC',
            default        => Status::sqlPriorityExpression('w.status') . ' ' . ($dir ?: 'ASC') . ', w.name ASC',
        };
    }

    // ------------------------------------------------------------------
    // Aggregates for the dashboard
    // ------------------------------------------------------------------

    /**
     * @return array<string, int> status => count (only monitored websites)
     */
    public function statusCounts(): array
    {
        $rows = $this->db->fetchAll('SELECT status, COUNT(*) AS c FROM websites GROUP BY status');
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['status']] = (int) $row['c'];
        }
        return $out;
    }

    public function averageResponseTime(): ?float
    {
        $value = $this->db->fetchColumn(
            "SELECT AVG(last_response_time) FROM websites
             WHERE monitoring_enabled = 1 AND last_response_time IS NOT NULL AND status NOT IN ('PAUSED','PENDING')"
        );
        return $value === null ? null : (float) $value;
    }

    public function sslExpiringCount(int $days = 30): int
    {
        return (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM websites WHERE monitoring_enabled = 1 AND (ssl_valid = 0 OR (ssl_expires_at IS NOT NULL AND ssl_expires_at <= :cutoff))',
            ['cutoff' => utc_now()->modify("+{$days} days")->format('Y-m-d H:i:s')]
        );
    }

    /**
     * Lightweight rows for live status refresh (id, status, response, last checked...).
     *
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function liveStatus(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        [$placeholders, $params] = $this->in($ids);
        return $this->db->fetchAll(
            "SELECT id, status, monitoring_enabled, last_http_status, last_response_time, last_error_type,
                    last_error_message, last_checked_at, ssl_valid, ssl_expires_at, failure_count, success_count
             FROM websites WHERE id IN ($placeholders)",
            $params
        );
    }
}
