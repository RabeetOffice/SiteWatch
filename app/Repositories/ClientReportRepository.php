<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Saved client reports and their share links (client_reports).
 */
final class ClientReportRepository extends BaseRepository
{
    private const SELECT = 'SELECT r.*, b.name AS brand_name, u.name AS created_by_name
        FROM client_reports r
        LEFT JOIN report_brands b ON b.id = r.brand_id
        LEFT JOIN users u ON u.id = r.created_by';

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetch(self::SELECT . ' WHERE r.id = :id LIMIT 1', ['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function findByToken(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            return null;
        }
        return $this->db->fetch(self::SELECT . ' WHERE r.token = :t LIMIT 1', ['t' => $token]);
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->db->fetchAll(self::SELECT . ' ORDER BY r.updated_at DESC, r.id DESC');
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $now = $this->now();
        return $this->db->insert('client_reports', $data + ['token' => self::newToken(), 'created_at' => $now, 'updated_at' => $now]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update('client_reports', $data + ['updated_at' => $this->now()], 'id = :id', ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->delete('client_reports', 'id = :id', ['id' => $id]);
    }

    /** Replace the share token, so the old link stops working. */
    public function regenerateToken(int $id): string
    {
        $token = self::newToken();
        $this->update($id, ['token' => $token]);
        return $token;
    }

    public function recordView(int $id): void
    {
        $this->db->query(
            'UPDATE client_reports SET view_count = view_count + 1, last_viewed_at = :now WHERE id = :id',
            ['now' => $this->now(), 'id' => $id]
        );
    }

    public static function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}
