<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * SiteWatch Connector data: one connector_sites row per website with a connection key, and the errors and
 * activity the WordPress plugin reports (connector_events).
 */
final class ConnectorRepository extends BaseRepository
{
    /** @return array<string, mixed>|null */
    public function find(int $websiteId): ?array
    {
        return $this->db->fetch('SELECT * FROM connector_sites WHERE website_id = :id', ['id' => $websiteId]);
    }

    /** Create or replace the connection key for a website. Existing reports are kept. */
    public function saveSecret(int $websiteId, string $encryptedSecret): void
    {
        $this->db->query(
            'INSERT INTO connector_sites (website_id, secret, key_created_at) VALUES (:id, :secret, :now)
             ON DUPLICATE KEY UPDATE secret = VALUES(secret), key_created_at = VALUES(key_created_at)',
            ['id' => $websiteId, 'secret' => $encryptedSecret, 'now' => $this->now()]
        );
    }

    public function delete(int $websiteId): void
    {
        $this->db->delete('connector_sites', 'website_id = :id', ['id' => $websiteId]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $websiteId, array $data): void
    {
        $this->db->update('connector_sites', $data, 'website_id = :id', ['id' => $websiteId]);
    }

    /**
     * Store an event unless the plugin already delivered it. Fatal errors with a known fingerprint in the last
     * 7 days are merged into the existing row instead.
     *
     * @param array<string, mixed> $event
     * @return array{id: int, is_new: bool, first_of_fingerprint: bool}
     */
    public function storeEvent(int $websiteId, array $event): array
    {
        $existing = $this->db->fetch(
            'SELECT id FROM connector_events WHERE website_id = :w AND event_uid = :u',
            ['w' => $websiteId, 'u' => $event['event_uid']]
        );
        if ($existing !== null) {
            return ['id' => (int) $existing['id'], 'is_new' => false, 'first_of_fingerprint' => false];
        }

        if ($event['fingerprint'] !== null) {
            $match = $this->db->fetch(
                'SELECT id, occurrences FROM connector_events
                 WHERE website_id = :w AND fingerprint = :f AND last_occurred_at >= :since
                 ORDER BY id DESC LIMIT 1',
                ['w' => $websiteId, 'f' => $event['fingerprint'], 'since' => utc_now()->modify('-7 days')->format('Y-m-d H:i:s')]
            );
            if ($match !== null) {
                // The plugin sends a running total, so a re-delivered report does not inflate the count.
                $occurrences = $event['total_count'] !== null
                    ? max((int) $match['occurrences'], (int) $event['total_count'])
                    : (int) $match['occurrences'] + max(1, (int) $event['new_count']);
                $this->db->update('connector_events', [
                    'occurrences'      => $occurrences,
                    'last_occurred_at' => $event['last_occurred_at'],
                    'data'             => $event['data'],
                    'received_at'      => $this->now(),
                ], 'id = :id', ['id' => (int) $match['id']]);
                return ['id' => (int) $match['id'], 'is_new' => false, 'first_of_fingerprint' => false];
            }
        }

        $id = $this->db->insert('connector_events', [
            'website_id'       => $websiteId,
            'event_uid'        => $event['event_uid'],
            'type'             => $event['type'],
            'severity'         => $event['severity'],
            'title'            => $event['title'],
            'data'             => $event['data'],
            'fingerprint'      => $event['fingerprint'],
            'occurrences'      => max(1, (int) ($event['total_count'] ?? $event['new_count'])),
            'occurred_at'      => $event['occurred_at'],
            'last_occurred_at' => $event['last_occurred_at'],
            'received_at'      => $this->now(),
        ]);
        return ['id' => $id, 'is_new' => true, 'first_of_fingerprint' => $event['fingerprint'] !== null];
    }

    public function markNotified(int $eventId): void
    {
        $this->db->update('connector_events', ['notified_at' => $this->now()], 'id = :id', ['id' => $eventId]);
    }

    /** @return array<string, mixed>|null */
    public function event(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM connector_events WHERE id = :id', ['id' => $id]);
    }

    /**
     * @param array<int, string>|null $types Only these types (null: all)
     * @return array<int, array<string, mixed>>
     */
    public function events(int $websiteId, ?array $types, int $limit, ?array $excludeTypes = null): array
    {
        $where = ['website_id = :w'];
        $params = ['w' => $websiteId];
        if ($types !== null) {
            [$ph, $p] = $this->in($types, 't');
            $where[] = "type IN ($ph)";
            $params += $p;
        }
        if ($excludeTypes !== null) {
            [$ph, $p] = $this->in($excludeTypes, 'x');
            $where[] = "type NOT IN ($ph)";
            $params += $p;
        }
        return $this->db->fetchAll(
            'SELECT * FROM connector_events WHERE ' . implode(' AND ', $where) . ' ORDER BY last_occurred_at DESC, id DESC LIMIT ' . max(1, $limit),
            $params
        );
    }

    /**
     * The most recent fatal error reported for a website since $sinceUtc (used to explain a down alert).
     *
     * @return array<string, mixed>|null
     */
    public function latestFatal(int $websiteId, string $sinceUtc): ?array
    {
        return $this->db->fetch(
            "SELECT * FROM connector_events WHERE website_id = :w AND type = 'fatal_error' AND last_occurred_at >= :since
             ORDER BY last_occurred_at DESC LIMIT 1",
            ['w' => $websiteId, 'since' => $sinceUtc]
        );
    }

    /**
     * Connection overview for the dashboard.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allWithWebsites(): array
    {
        return $this->db->fetchAll(
            'SELECT c.website_id, c.connected_at, c.last_seen_at, c.last_reason, c.plugin_version, c.wp_version, c.php_version,
                    c.updates_pending, c.security_issues, c.vuln_count, w.name, w.domain, w.client_name
             FROM connector_sites c JOIN websites w ON w.id = c.website_id
             ORDER BY w.name ASC'
        );
    }

    /**
     * Recent errors and security events across every site, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentImportant(int $limit): array
    {
        return $this->db->fetchAll(
            "SELECT e.*, w.name AS website_name FROM connector_events e JOIN websites w ON w.id = e.website_id
             WHERE e.severity = 'critical' AND e.last_occurred_at >= :since
             ORDER BY e.last_occurred_at DESC LIMIT " . max(1, $limit),
            ['since' => utc_now()->modify('-7 days')->format('Y-m-d H:i:s')]
        );
    }

    // ------------------------------------------------------------------
    // Known vulnerabilities
    // ------------------------------------------------------------------

    /**
     * Connected sites whose vulnerability report is missing, older than their health snapshot, older than
     * $staleBeforeUtc, or incomplete and older than $retryBeforeUtc. Oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function dueForVulnerabilityScan(string $staleBeforeUtc, string $retryBeforeUtc, int $limit): array
    {
        return $this->db->fetchAll(
            "SELECT c.website_id, c.snapshot, c.snapshot_at, c.vuln_report, c.vuln_checked_at
             FROM connector_sites c
             WHERE c.snapshot_at IS NOT NULL AND c.connected_at IS NOT NULL
               AND (c.last_reason IS NULL OR c.last_reason <> 'disconnect')
               AND (c.vuln_checked_at IS NULL OR c.vuln_checked_at < c.snapshot_at OR c.vuln_checked_at < :stale
                    OR (c.vuln_incomplete = 1 AND c.vuln_checked_at < :retry))
             ORDER BY c.vuln_checked_at IS NOT NULL, c.vuln_checked_at ASC LIMIT " . max(1, $limit),
            ['stale' => $staleBeforeUtc, 'retry' => $retryBeforeUtc]
        );
    }

    /**
     * Cached feed answers for these (component, slug) pairs, keyed "component:slug".
     *
     * @param array<int, array{0: string, 1: string}> $keys
     * @return array<string, array{data: ?string, status: string, fetched_at: string}>
     */
    public function feedEntries(array $keys): array
    {
        $rows = [];
        foreach (array_chunk(array_values($keys), 200) as $chunk) {
            $where = [];
            $params = [];
            foreach ($chunk as $i => [$component, $slug]) {
                $where[] = "(component = :c{$i} AND slug = :s{$i})";
                $params["c{$i}"] = $component;
                $params["s{$i}"] = $slug;
            }
            foreach ($this->db->fetchAll('SELECT component, slug, data, status, fetched_at FROM vulnerability_feed WHERE ' . implode(' OR ', $where), $params) as $row) {
                $rows[$row['component'] . ':' . $row['slug']] = $row;
            }
        }
        return $rows;
    }

    public function saveFeedEntry(string $component, string $slug, ?string $json, string $status): void
    {
        $this->db->query(
            'INSERT INTO vulnerability_feed (component, slug, data, status, fetched_at) VALUES (:c, :s, :d, :st, :at)
             ON DUPLICATE KEY UPDATE data = IF(VALUES(status) = \'ok\', VALUES(data), data), status = VALUES(status), fetched_at = VALUES(fetched_at)',
            ['c' => $component, 's' => $slug, 'd' => $json, 'st' => $status, 'at' => $this->now()]
        );
    }

    // ------------------------------------------------------------------
    // Remote action commands
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $data */
    public function createCommand(array $data): int
    {
        return $this->db->insert('connector_commands', $data);
    }

    /** @return array<string, mixed>|null */
    public function command(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM connector_commands WHERE id = :id', ['id' => $id]);
    }

    /**
     * Commands the site has not answered yet and that have not expired, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function openCommands(int $websiteId, string $nowUtc): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM connector_commands WHERE website_id = :w AND status IN ('pending', 'sent') AND expires_at > :now ORDER BY id ASC LIMIT 10",
            ['w' => $websiteId, 'now' => $nowUtc]
        );
    }

    /** @param array<int, int> $ids */
    public function markCommandsSent(array $ids): void
    {
        [$ph, $params] = $this->in($ids, 'c');
        $this->db->query("UPDATE connector_commands SET status = 'sent', sent_at = :now WHERE status = 'pending' AND id IN ($ph)", $params + ['now' => $this->now()]);
    }

    public function finishCommand(int $id, string $status, string $resultJson): void
    {
        $this->db->update('connector_commands', ['status' => $status, 'result' => $resultJson, 'finished_at' => $this->now()], 'id = :id', ['id' => $id]);
    }

    public function expireCommands(string $nowUtc): int
    {
        return $this->db->query(
            "UPDATE connector_commands SET status = 'expired', finished_at = :now1 WHERE status IN ('pending', 'sent') AND expires_at <= :now2",
            ['now1' => $nowUtc, 'now2' => $nowUtc]
        )->rowCount();
    }

    /** @return array<int, array<string, mixed>> */
    public function recentCommands(int $websiteId, int $limit): array
    {
        return $this->db->fetchAll(
            'SELECT c.*, u.name AS requested_by_name FROM connector_commands c LEFT JOIN users u ON u.id = c.requested_by
             WHERE c.website_id = :w ORDER BY c.id DESC LIMIT ' . max(1, $limit),
            ['w' => $websiteId]
        );
    }

    public function purgeCommandsOlderThan(string $cutoffUtc): int
    {
        return $this->db->delete('connector_commands', 'created_at < :c', ['c' => $cutoffUtc]);
    }

    // ------------------------------------------------------------------
    // Daily inside views (page speed, PHP warnings)
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $row */
    public function saveDaily(array $row): void
    {
        $columns = array_keys($row);
        $this->db->query(
            'INSERT INTO connector_daily (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')
             ON DUPLICATE KEY UPDATE received_at = VALUES(received_at)',
            $row
        );
    }

    /** @return array<int, array<string, mixed>> Oldest first. */
    public function daily(int $websiteId, string $sinceUtc): array
    {
        return $this->db->fetchAll(
            'SELECT id, period_start, period_end, requests, p50_front, p95_front, p50_admin, p95_admin, queries_front,
                    warnings_total, warnings_places, deprecations
             FROM connector_daily WHERE website_id = :w AND period_end >= :since ORDER BY period_end ASC LIMIT 400',
            ['w' => $websiteId, 'since' => $sinceUtc]
        );
    }

    /** @return array<string, mixed>|null One stored report with its full JSON. */
    public function dailyOne(int $websiteId, int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM connector_daily WHERE website_id = :w AND id = :id', ['w' => $websiteId, 'id' => $id]);
    }

    public function purgeDailyOlderThan(string $cutoffUtc): int
    {
        return $this->db->delete('connector_daily', 'period_end < :c', ['c' => $cutoffUtc]);
    }

    public function purgeFeedOlderThan(string $cutoffUtc): int
    {
        return $this->db->delete('vulnerability_feed', 'fetched_at < :c', ['c' => $cutoffUtc]);
    }

    public function purgeOlderThan(string $cutoffUtc, int $batchSize = 5000, int $maxBatches = 50): int
    {
        $deleted = 0;
        for ($i = 0; $i < $maxBatches; $i++) {
            $count = $this->db->query(
                'DELETE FROM connector_events WHERE last_occurred_at < :cutoff ORDER BY id ASC LIMIT ' . max(100, $batchSize),
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
