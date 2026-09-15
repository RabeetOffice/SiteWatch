<?php

declare(strict_types=1);

namespace App\Repositories;

final class ActivityRepository extends BaseRepository
{
    /** @param array<string, mixed>|null $context */
    public function log(string $action, string $description, ?int $websiteId = null, ?int $userId = null, ?array $context = null, ?string $ip = null): int
    {
        return $this->db->insert('activity_logs', [
            'user_id'     => $userId,
            'website_id'  => $websiteId,
            'action'      => mb_substr($action, 0, 50),
            'description' => mb_substr($description, 0, 500),
            'context'     => $context === null ? null : json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'ip'          => $ip !== null ? substr($ip, 0, 45) : null,
            'created_at'  => $this->now(),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function recent(int $limit = 10, ?int $websiteId = null): array
    {
        $sql = 'SELECT a.*, u.name AS user_name, w.name AS website_name, w.domain
                FROM activity_logs a
                LEFT JOIN users u ON u.id = a.user_id
                LEFT JOIN websites w ON w.id = a.website_id';
        $params = [];
        if ($websiteId !== null) {
            $sql .= ' WHERE a.website_id = :wid';
            $params['wid'] = $websiteId;
        }
        $sql .= ' ORDER BY a.created_at DESC, a.id DESC LIMIT ' . max(1, $limit);
        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @param array{q?: string, action?: string, website_id?: int} $criteria
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function search(array $criteria, int $limit, int $offset): array
    {
        $where = ['1=1'];
        $params = [];
        $q = trim((string) ($criteria['q'] ?? ''));
        if ($q !== '') {
            // Native prepared statements cannot reuse a named placeholder, so each comparison gets its own.
            $where[] = '(a.description LIKE :q1 OR w.name LIKE :q2 OR w.domain LIKE :q3 OR a.action LIKE :q4)';
            foreach ([1, 2, 3, 4] as $i) {
                $params['q' . $i] = $this->like($q);
            }
        }
        $action = trim((string) ($criteria['action'] ?? ''));
        if ($action !== '') {
            $where[] = 'a.action LIKE :action';
            $params['action'] = $this->like($action);
        }
        if (!empty($criteria['website_id'])) {
            $where[] = 'a.website_id = :wid';
            $params['wid'] = (int) $criteria['website_id'];
        }
        $whereSql = implode(' AND ', $where);
        $rows = $this->db->fetchAll(
            "SELECT a.*, u.name AS user_name, w.name AS website_name, w.domain
             FROM activity_logs a
             LEFT JOIN users u ON u.id = a.user_id
             LEFT JOIN websites w ON w.id = a.website_id
             WHERE {$whereSql}
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $params
        );
        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM activity_logs a LEFT JOIN websites w ON w.id = a.website_id WHERE {$whereSql}",
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    }

    public function purgeOlderThan(string $cutoffUtc, int $batchSize = 5000, int $maxBatches = 100): int
    {
        $deleted = 0;
        for ($i = 0; $i < $maxBatches; $i++) {
            $count = $this->db->query(
                'DELETE FROM activity_logs WHERE created_at < :cutoff ORDER BY id ASC LIMIT ' . max(100, $batchSize),
                ['cutoff' => $cutoffUtc]
            )->rowCount();
            $deleted += $count;
            if ($count < $batchSize) {
                break;
            }
        }
        return $deleted;
    }
}
