<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domains\DomainName;

/**
 * Stored domain registration and hosting details (domain_info, one row per website).
 */
final class DomainRepository extends BaseRepository
{
    public const FILTERS = ['all', 'expiring', 'expired', 'failed', 'unchecked'];
    public const SORTS = ['expiry', 'age', 'domain', 'provider', 'checked'];

    private const LIST_COLUMNS = 'w.id AS website_id, w.name, w.url, w.domain AS website_domain, w.client_name, w.favicon_url,
        d.host, d.domain, d.registrar, d.registered_at, d.expires_at, d.whois_source, d.whois_error,
        d.ip_address, d.asn, d.hosting_provider, d.cdn, d.country_code, d.hosting_error, d.checked_at';

    /** @return array<string, mixed>|null */
    public function findForWebsite(int $websiteId): ?array
    {
        return $this->db->fetch('SELECT * FROM domain_info WHERE website_id = :id LIMIT 1', ['id' => $websiteId]);
    }

    /**
     * Insert or replace the details for a website.
     *
     * @param array<string, mixed> $inspection DomainInspector::inspect() output
     */
    public function save(int $websiteId, array $inspection): void
    {
        $registration = (array) $inspection['registration'];
        $hosting = (array) $inspection['hosting'];
        $now = $this->now();
        $clip = static fn (mixed $value, int $length): ?string => $value === null || $value === '' ? null : mb_substr((string) $value, 0, $length);

        $row = [
            'host'             => mb_substr((string) $inspection['host'], 0, 253),
            'domain'           => mb_substr((string) ($inspection['domain'] ?: DomainName::registrable((string) $inspection['host'])), 0, 253),
            'registrar'        => $clip($registration['registrar'] ?? null, 190),
            'registered_at'    => $registration['registered_at'] ?? null,
            'expires_at'       => $registration['expires_at'] ?? null,
            'whois_source'     => $registration['source'] ?? null,
            'whois_error'      => $clip($registration['error'] ?? null, 255),
            'ip_address'       => $clip($hosting['primary_ip'] ?? null, 45),
            'asn'              => isset($hosting['asn']) ? (int) $hosting['asn'] : null,
            'hosting_provider' => $clip($hosting['provider'] ?? null, 120),
            'cdn'              => $clip($hosting['cdn'] ?? null, 60),
            'country_code'     => $clip($hosting['country_code'] ?? null, 2),
            'hosting_error'    => $clip($hosting['error'] ?? null, 255),
            'details'          => json_encode($inspection, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'checked_at'       => (string) ($inspection['checked_at'] ?? $now),
            'updated_at'       => $now,
        ];

        // One atomic upsert: the cron and a user's on-demand refresh may save the same website at the same moment.
        $row += ['website_id' => $websiteId, 'created_at' => $now];
        $columns = array_keys($row);
        $quote = fn (string $column): string => $this->db->quoteIdentifier($column);
        $updates = array_diff($columns, ['website_id', 'created_at']);
        $this->db->query(
            'INSERT INTO domain_info (' . implode(', ', array_map($quote, $columns)) . ')
             VALUES (' . implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)) . ')
             ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn (string $c): string => $quote($c) . ' = VALUES(' . $quote($c) . ')', $updates)),
            $row
        );
    }

    /**
     * Websites whose details are missing, older than $maxAgeHours, or were collected for a different host.
     *
     * @return array<int, array<string, mixed>>
     */
    public function stale(int $maxAgeHours, int $limit): array
    {
        return $this->db->fetchAll(
            'SELECT w.* FROM websites w
             LEFT JOIN domain_info d ON d.website_id = w.id
             WHERE d.id IS NULL OR d.checked_at < :cutoff OR d.host <> w.domain
             ORDER BY d.checked_at IS NOT NULL, d.checked_at ASC, w.id ASC
             LIMIT ' . max(1, $limit),
            ['cutoff' => utc_now()->modify('-' . max(1, $maxAgeHours) . ' hours')->format('Y-m-d H:i:s')]
        );
    }

    /**
     * @param array{q?: string, filter?: string, sort?: string, provider?: string, country?: string} $criteria
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function search(array $criteria, int $warningDays, int $limit, int $offset): array
    {
        [$whereSql, $params] = $this->where($criteria, $warningDays);
        $order = match ($criteria['sort'] ?? 'expiry') {
            'age'      => 'd.registered_at IS NULL, d.registered_at ASC, w.name ASC',
            'domain'   => 'w.domain ASC',
            'provider' => 'd.hosting_provider IS NULL, d.hosting_provider ASC, w.name ASC',
            'checked'  => 'd.checked_at IS NULL, d.checked_at DESC, w.name ASC',
            default    => 'd.expires_at IS NULL, d.expires_at ASC, w.name ASC',
        };
        $rows = $this->db->fetchAll(
            'SELECT ' . self::LIST_COLUMNS . ' FROM websites w LEFT JOIN domain_info d ON d.website_id = w.id
             WHERE ' . $whereSql . ' ORDER BY ' . $order . ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $params
        );
        $total = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM websites w LEFT JOIN domain_info d ON d.website_id = w.id WHERE ' . $whereSql,
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Portfolio figures and the hosting-provider / country breakdown for the Domains page.
     *
     * @return array{total: int, checked: int, expiring: int, expired: int, failed: int, avg_age_days: ?int, providers: array<int, array{name: string, count: int}>, countries: array<int, array{code: string, count: int}>, behind_cdn: int}
     */
    public function summary(int $warningDays): array
    {
        $now = $this->now();
        $soon = utc_now()->modify('+' . $warningDays . ' days')->format('Y-m-d H:i:s');
        $row = $this->db->fetch(
            'SELECT COUNT(*) AS total,
                    COALESCE(SUM(d.id IS NOT NULL), 0) AS checked,
                    COALESCE(SUM(d.expires_at >= :now1 AND d.expires_at < :soon), 0) AS expiring,
                    COALESCE(SUM(d.expires_at < :now2), 0) AS expired,
                    COALESCE(SUM(d.whois_error IS NOT NULL OR d.hosting_error IS NOT NULL), 0) AS failed,
                    AVG(CASE WHEN d.registered_at IS NOT NULL THEN TIMESTAMPDIFF(DAY, d.registered_at, :now3) END) AS avg_age_days
             FROM websites w
             LEFT JOIN domain_info d ON d.website_id = w.id',
            ['now1' => $now, 'soon' => $soon, 'now2' => $now, 'now3' => $now]
        ) ?? [];

        $providers = $this->db->fetchAll(
            'SELECT d.hosting_provider AS name, COUNT(*) AS c
             FROM domain_info d INNER JOIN websites w ON w.id = d.website_id
             WHERE d.hosting_provider IS NOT NULL
             GROUP BY d.hosting_provider
             ORDER BY c DESC, d.hosting_provider ASC
             LIMIT 10'
        );
        // Countries of CDN-fronted sites describe the CDN edge, not the server, so they are left out.
        $countries = $this->db->fetchAll(
            'SELECT d.country_code AS code, COUNT(*) AS c
             FROM domain_info d INNER JOIN websites w ON w.id = d.website_id
             WHERE d.country_code IS NOT NULL AND d.cdn IS NULL
             GROUP BY d.country_code
             ORDER BY c DESC, d.country_code ASC
             LIMIT 10'
        );
        $behindCdn = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM domain_info d INNER JOIN websites w ON w.id = d.website_id WHERE d.cdn IS NOT NULL AND d.hosting_provider IS NULL'
        );

        return [
            'total'        => (int) ($row['total'] ?? 0),
            'checked'      => (int) ($row['checked'] ?? 0),
            'expiring'     => (int) ($row['expiring'] ?? 0),
            'expired'      => (int) ($row['expired'] ?? 0),
            'failed'       => (int) ($row['failed'] ?? 0),
            'avg_age_days' => isset($row['avg_age_days']) ? (int) round((float) $row['avg_age_days']) : null,
            'providers'    => array_map(static fn (array $p): array => ['name' => (string) $p['name'], 'count' => (int) $p['c']], $providers),
            'countries'    => array_map(static fn (array $c): array => ['code' => (string) $c['code'], 'count' => (int) $c['c']], $countries),
            'behind_cdn'   => $behindCdn,
        ];
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function where(array $criteria, int $warningDays): array
    {
        $where = ['1=1'];
        $params = [];

        $q = trim((string) ($criteria['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(w.name LIKE :q1 OR w.domain LIKE :q2 OR w.client_name LIKE :q3 OR d.registrar LIKE :q4 OR d.hosting_provider LIKE :q5)';
            foreach ([1, 2, 3, 4, 5] as $i) {
                $params['q' . $i] = $this->like($q);
            }
        }

        $now = $this->now();
        switch ($criteria['filter'] ?? 'all') {
            case 'expiring':
                $where[] = 'd.expires_at >= :fnow AND d.expires_at < :fsoon';
                $params['fnow'] = $now;
                $params['fsoon'] = utc_now()->modify('+' . $warningDays . ' days')->format('Y-m-d H:i:s');
                break;
            case 'expired':
                $where[] = 'd.expires_at < :fnow';
                $params['fnow'] = $now;
                break;
            case 'failed':
                $where[] = '(d.whois_error IS NOT NULL OR d.hosting_error IS NOT NULL)';
                break;
            case 'unchecked':
                $where[] = 'd.id IS NULL';
                break;
        }

        $provider = trim((string) ($criteria['provider'] ?? ''));
        if ($provider !== '') {
            $where[] = 'd.hosting_provider = :provider';
            $params['provider'] = $provider;
        }
        $country = strtoupper(trim((string) ($criteria['country'] ?? '')));
        if (preg_match('/^[A-Z]{2}$/', $country)) {
            $where[] = 'd.country_code = :country AND d.cdn IS NULL';
            $params['country'] = $country;
        }

        return [implode(' AND ', $where), $params];
    }
}
