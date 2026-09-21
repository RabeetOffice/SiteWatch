<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Performance\VitalsResult;

/**
 * Core Web Vitals history. One row per website per strategy per PageSpeed run.
 */
final class VitalsRepository extends BaseRepository
{
    public function record(int $websiteId, VitalsResult $result): int
    {
        return $this->db->insert('website_vitals', [
            'website_id'        => $websiteId,
            'strategy'          => $result->strategy,
            'status'            => 'ok',
            'performance_score' => $result->lab['score'] ?? null,
            'lab_ttfb'          => $result->lab['ttfb'] ?? null,
            'lab_fcp'           => $result->lab['fcp'] ?? null,
            'lab_lcp'           => $result->lab['lcp'] ?? null,
            'lab_cls'           => $result->lab['cls'] ?? null,
            'lab_tbt'           => $result->lab['tbt'] ?? null,
            'lab_speed_index'   => $result->lab['speed_index'] ?? null,
            'lab_tti'           => $result->lab['tti'] ?? null,
            'field_source'      => $result->fieldSource,
            'field_ttfb'        => $result->field['ttfb'] ?? null,
            'field_fcp'         => $result->field['fcp'] ?? null,
            'field_lcp'         => $result->field['lcp'] ?? null,
            'field_cls'         => $result->field['cls'] ?? null,
            'field_inp'         => $result->field['inp'] ?? null,
            'field_verdict'     => $result->fieldVerdict,
            'fetched_at'        => $result->fetchedAt,
        ]);
    }

    public function recordFailure(int $websiteId, string $strategy, string $error): int
    {
        return $this->db->insert('website_vitals', [
            'website_id'    => $websiteId,
            'strategy'      => $strategy,
            'status'        => 'failed',
            'error_message' => mb_substr($error, 0, 500),
            'fetched_at'    => $this->now(),
        ]);
    }

    /**
     * Most recent successful run per strategy for one website.
     *
     * @return array<string, array<string, mixed>> keyed by strategy
     */
    public function latestForWebsite(int $websiteId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT v.* FROM website_vitals v
             JOIN (
                SELECT strategy, MAX(id) AS id FROM website_vitals
                WHERE website_id = :wid AND status = 'ok'
                GROUP BY strategy
             ) latest ON latest.id = v.id",
            ['wid' => $websiteId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['strategy']] = $row;
        }
        return $out;
    }

    /** The newest run of any kind, so a failure is visible rather than silently leaving stale numbers. */
    public function lastAttempt(int $websiteId): ?array
    {
        return $this->db->fetch(
            'SELECT * FROM website_vitals WHERE website_id = :wid ORDER BY id DESC LIMIT 1',
            ['wid' => $websiteId]
        );
    }

    /**
     * Successful runs for one website and strategy, oldest first, for trend charts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function history(int $websiteId, string $strategy, int $days = 30, int $limit = 200): array
    {
        $since = utc_now()->modify('-' . max(1, $days) . ' days')->format('Y-m-d H:i:s');
        $rows = $this->db->fetchAll(
            "SELECT * FROM website_vitals
             WHERE website_id = :wid AND strategy = :s AND status = 'ok' AND fetched_at >= :since
             ORDER BY fetched_at DESC, id DESC
             LIMIT " . max(1, $limit),
            ['wid' => $websiteId, 's' => $strategy, 'since' => $since]
        );
        return array_reverse($rows);
    }

    /**
     * Latest successful run for every website on one strategy, for the fleet overview.
     *
     * @return array<int, array<string, mixed>> keyed by website id
     */
    public function latestPerWebsite(string $strategy): array
    {
        $rows = $this->db->fetchAll(
            "SELECT v.* FROM website_vitals v
             JOIN (
                SELECT website_id, MAX(id) AS id FROM website_vitals
                WHERE strategy = :s AND status = 'ok'
                GROUP BY website_id
             ) latest ON latest.id = v.id",
            ['s' => $strategy]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['website_id']] = $row;
        }
        return $out;
    }

    /**
     * Websites whose vitals are missing or older than the configured interval.
     *
     * @return array<int, array<string, mixed>>
     */
    public function due(int $limit, int $maxAgeHours): array
    {
        $cutoff = utc_now()->modify('-' . max(1, $maxAgeHours) . ' hours')->format('Y-m-d H:i:s');
        return $this->db->fetchAll(
            'SELECT * FROM websites
             WHERE monitoring_enabled = 1
               AND (vitals_checked_at IS NULL OR vitals_checked_at <= :cutoff)
             ORDER BY vitals_checked_at IS NULL DESC, vitals_checked_at ASC
             LIMIT ' . max(1, $limit),
            ['cutoff' => $cutoff]
        );
    }

    public function purgeOlderThan(string $cutoffUtc, int $batchSize = 5000, int $maxBatches = 100): int
    {
        $deleted = 0;
        for ($i = 0; $i < $maxBatches; $i++) {
            $count = $this->db->query(
                'DELETE FROM website_vitals WHERE fetched_at < :cutoff ORDER BY id ASC LIMIT ' . max(100, $batchSize),
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
