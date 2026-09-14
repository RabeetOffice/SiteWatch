<?php

declare(strict_types=1);

namespace App\Repositories;

final class NotificationRepository extends BaseRepository
{
    public function log(
        string $channel,
        string $event,
        string $status,
        ?int $websiteId = null,
        ?int $incidentId = null,
        ?string $recipient = null,
        ?string $subject = null,
        ?string $error = null
    ): int {
        return $this->db->insert('notifications', [
            'website_id'    => $websiteId,
            'incident_id'   => $incidentId,
            'channel'       => mb_substr($channel, 0, 20),
            'event'         => mb_substr($event, 0, 40),
            'recipient'     => $recipient !== null ? mb_substr($recipient, 0, 255) : null,
            'subject'       => $subject !== null ? mb_substr($subject, 0, 255) : null,
            'status'        => $status,
            'error_message' => $error !== null ? mb_substr($error, 0, 500) : null,
            'created_at'    => $this->now(),
        ]);
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function recent(int $limit, int $offset = 0, ?int $websiteId = null): array
    {
        $where = '1=1';
        $params = [];
        if ($websiteId !== null) {
            $where = 'n.website_id = :wid';
            $params['wid'] = $websiteId;
        }
        $rows = $this->db->fetchAll(
            "SELECT n.*, w.name AS website_name, w.domain
             FROM notifications n LEFT JOIN websites w ON w.id = n.website_id
             WHERE {$where}
             ORDER BY n.created_at DESC, n.id DESC
             LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $params
        );
        $total = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM notifications n WHERE {$where}", $params);
        return ['rows' => $rows, 'total' => $total];
    }

    public function countSince(string $fromUtc, ?string $status = null): int
    {
        $sql = 'SELECT COUNT(*) FROM notifications WHERE created_at >= :from';
        $params = ['from' => $fromUtc];
        if ($status !== null) {
            $sql .= ' AND status = :status';
            $params['status'] = $status;
        }
        return (int) $this->db->fetchColumn($sql, $params);
    }

    public function purgeOlderThan(string $cutoffUtc, int $batchSize = 5000, int $maxBatches = 100): int
    {
        $deleted = 0;
        for ($i = 0; $i < $maxBatches; $i++) {
            $count = $this->db->query(
                'DELETE FROM notifications WHERE created_at < :cutoff ORDER BY id ASC LIMIT ' . max(100, $batchSize),
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
