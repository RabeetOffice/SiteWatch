<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Brand kits for client reports (report_brands).
 */
final class ReportBrandRepository extends BaseRepository
{
    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM report_brands WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->db->fetchAll(
            'SELECT b.*, (SELECT COUNT(*) FROM client_reports r WHERE r.brand_id = b.id) AS report_count
             FROM report_brands b ORDER BY b.name ASC, b.id ASC'
        );
    }

    /**
     * The brand linked to a client, if any (the newest when several are).
     *
     * @return array<string, mixed>|null
     */
    public function forClient(string $client): ?array
    {
        if ($client === '') {
            return null;
        }
        return $this->db->fetch('SELECT * FROM report_brands WHERE client_name = :c ORDER BY id DESC LIMIT 1', ['c' => $client]);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $now = $this->now();
        return $this->db->insert('report_brands', $data + ['created_at' => $now, 'updated_at' => $now]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update('report_brands', $data + ['updated_at' => $this->now()], 'id = :id', ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->delete('report_brands', 'id = :id', ['id' => $id]);
    }
}
