<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Screenshot history. The rows point at files under storage/screenshots; deleting a row must always be
 * paired with deleting its file, which PerformanceService does.
 */
final class ScreenshotRepository extends BaseRepository
{
    /**
     * @param array{path: string, mime: string, bytes: int} $stored
     */
    public function record(int $websiteId, string $provider, array $stored): int
    {
        return $this->db->insert('website_screenshots', [
            'website_id'  => $websiteId,
            'provider'    => mb_substr($provider, 0, 20),
            'status'      => 'ok',
            'path'        => $stored['path'],
            'mime'        => $stored['mime'],
            'bytes'       => $stored['bytes'],
            'captured_at' => $this->now(),
        ]);
    }

    public function recordFailure(int $websiteId, string $provider, string $error): int
    {
        return $this->db->insert('website_screenshots', [
            'website_id'    => $websiteId,
            'provider'      => mb_substr($provider, 0, 20),
            'status'        => 'failed',
            'error_message' => mb_substr($error, 0, 500),
            'captured_at'   => $this->now(),
        ]);
    }

    public function latest(int $websiteId): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM website_screenshots WHERE website_id = :wid AND status = 'ok' ORDER BY id DESC LIMIT 1",
            ['wid' => $websiteId]
        );
    }

    /** The newest attempt of any kind, so a failing capture is visible. */
    public function lastAttempt(int $websiteId): ?array
    {
        return $this->db->fetch(
            'SELECT * FROM website_screenshots WHERE website_id = :wid ORDER BY id DESC LIMIT 1',
            ['wid' => $websiteId]
        );
    }

    public function find(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM website_screenshots WHERE id = :id', ['id' => $id]);
    }

    /**
     * Stored captures for one website, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function historyFor(int $websiteId, int $limit = 12): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM website_screenshots WHERE website_id = :wid AND status = 'ok'
             ORDER BY id DESC LIMIT " . max(1, $limit),
            ['wid' => $websiteId]
        );
    }

    /**
     * Latest successful capture for every website, for the gallery.
     *
     * @return array<int, array<string, mixed>> keyed by website id
     */
    public function latestPerWebsite(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT s.* FROM website_screenshots s
             JOIN (
                SELECT website_id, MAX(id) AS id FROM website_screenshots
                WHERE status = 'ok'
                GROUP BY website_id
             ) latest ON latest.id = s.id"
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['website_id']] = $row;
        }
        return $out;
    }

    /**
     * Websites whose screenshot is missing or older than the configured interval.
     *
     * @return array<int, array<string, mixed>>
     */
    public function due(int $limit, int $maxAgeMinutes): array
    {
        $cutoff = utc_now()->modify('-' . max(1, $maxAgeMinutes) . ' minutes')->format('Y-m-d H:i:s');
        return $this->db->fetchAll(
            'SELECT * FROM websites
             WHERE monitoring_enabled = 1
               AND (screenshot_captured_at IS NULL OR screenshot_captured_at <= :cutoff)
             ORDER BY screenshot_captured_at IS NULL DESC, screenshot_captured_at ASC
             LIMIT ' . max(1, $limit),
            ['cutoff' => $cutoff]
        );
    }

    /**
     * Rows older than the cutoff, or beyond the per-website keep count. Returned rather than deleted so
     * the caller can remove each file before the row that points at it disappears.
     *
     * @return array<int, array<string, mixed>>
     */
    public function expired(string $cutoffUtc, int $keepPerWebsite, int $limit = 2000): array
    {
        $old = $this->db->fetchAll(
            'SELECT id, path FROM website_screenshots WHERE captured_at < :cutoff ORDER BY id ASC LIMIT ' . max(1, $limit),
            ['cutoff' => $cutoffUtc]
        );
        $surplus = $this->db->fetchAll(
            'SELECT s.id, s.path FROM website_screenshots s
             WHERE (
                SELECT COUNT(*) FROM website_screenshots newer
                WHERE newer.website_id = s.website_id AND newer.id > s.id
             ) >= :keep
             ORDER BY s.id ASC LIMIT ' . max(1, $limit),
            ['keep' => max(1, $keepPerWebsite)]
        );

        $byId = [];
        foreach (array_merge($old, $surplus) as $row) {
            $byId[(int) $row['id']] = $row;
        }
        return array_values($byId);
    }

    /** @param array<int, int> $ids */
    public function deleteMany(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        [$placeholders, $params] = $this->in($ids, 'shot');
        return $this->db->query("DELETE FROM website_screenshots WHERE id IN ({$placeholders})", $params)->rowCount();
    }
}
