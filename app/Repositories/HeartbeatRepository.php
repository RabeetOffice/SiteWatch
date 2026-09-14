<?php

declare(strict_types=1);

namespace App\Repositories;

final class HeartbeatRepository extends BaseRepository
{
    public function start(string $process, ?string $message = null): int
    {
        return $this->db->insert('monitor_heartbeats', [
            'process'    => $process,
            'started_at' => $this->now(),
            'status'     => 'running',
            'message'    => $message,
            'hostname'   => substr((string) gethostname(), 0, 190),
        ]);
    }

    public function finish(int $id, string $status, int $websitesChecked = 0, int $failures = 0, ?string $message = null): void
    {
        $row = $this->db->fetch('SELECT started_at FROM monitor_heartbeats WHERE id = :id', ['id' => $id]);
        $duration = null;
        if ($row !== null) {
            $duration = max(0, (int) round((microtime(true) - strtotime($row['started_at'] . ' UTC')) * 1000));
        }
        $this->db->update('monitor_heartbeats', [
            'finished_at'      => $this->now(),
            'status'           => $status,
            'websites_checked' => $websitesChecked,
            'failures'         => $failures,
            'duration_ms'      => $duration,
            'message'          => $message !== null ? mb_substr($message, 0, 500) : null,
        ], 'id = :id', ['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function latest(string $process, bool $completedOnly = false): ?array
    {
        $where = 'process = :p' . ($completedOnly ? " AND status IN ('completed','skipped')" : '');
        return $this->db->fetch(
            "SELECT * FROM monitor_heartbeats WHERE {$where} ORDER BY started_at DESC, id DESC LIMIT 1",
            ['p' => $process]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function recent(string $process, int $limit = 20): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM monitor_heartbeats WHERE process = :p ORDER BY started_at DESC, id DESC LIMIT ' . max(1, $limit),
            ['p' => $process]
        );
    }

    public function purgeOlderThan(string $cutoffUtc): int
    {
        return $this->db->delete('monitor_heartbeats', 'started_at < :cutoff', ['cutoff' => $cutoffUtc]);
    }
}
