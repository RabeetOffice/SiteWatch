<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Countries\CountryClassifier;
use App\Countries\Probe;

/**
 * Country availability results: country_status (latest per website and country) and country_checks (history).
 */
final class CountryCheckRepository extends BaseRepository
{
    /**
     * Latest result of every website and country.
     *
     * @return array<int, array<string, array<string, mixed>>> website id => country => row
     */
    public function matrix(): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT * FROM country_status') as $row) {
            $out[(int) $row['website_id']][(string) $row['country']] = $row;
        }
        return $out;
    }

    /**
     * @return array<string, array<string, mixed>> country => row
     */
    public function forWebsite(int $websiteId): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT * FROM country_status WHERE website_id = :id', ['id' => $websiteId]) as $row) {
            $out[(string) $row['country']] = $row;
        }
        return $out;
    }

    /**
     * Store one run. Returns the countries whose problem became confirmed on this run and has not been alerted,
     * and the countries that recovered after an alerted problem.
     *
     * @param array<string, array{result: string, probe: ?Probe, confirmed: bool}> $results
     * @return array{problems: list<array<string, mixed>>, recovered: list<array<string, mixed>>}
     */
    public function saveRun(int $websiteId, array $results): array
    {
        $now = $this->now();
        $previous = $this->forWebsite($websiteId);
        $problems = [];
        $recovered = [];

        foreach ($results as $country => $r) {
            $probe = $r['probe'];
            $result = $r['result'];
            $old = $previous[$country] ?? null;
            $isProblem = in_array($result, CountryClassifier::PROBLEMS, true);
            // "No test server" says nothing about the site: keep the last real result and its streak.
            if ($result === CountryClassifier::NO_PROBE && $old !== null && $old['result'] !== CountryClassifier::NO_PROBE) {
                $this->db->query('UPDATE country_status SET checked_at = :now WHERE website_id = :id AND country = :c', ['now' => $now, 'id' => $websiteId, 'c' => $country]);
                continue;
            }
            $problemRuns = $isProblem ? (int) ($old['problem_runs'] ?? 0) + 1 : 0;
            $changed = $old === null || $old['result'] !== $result;
            $alertedAt = $isProblem ? ($old['alerted_at'] ?? null) : null;

            $row = [
                'website_id'   => $websiteId,
                'country'      => $country,
                'result'       => $result,
                'status_code'  => $probe?->statusCode,
                'response_ms'  => $probe?->responseMs,
                'error'        => $probe?->error,
                'network'      => $probe?->network,
                'city'         => $probe?->city,
                'probe_type'   => $probe?->type,
                'provider'     => $probe?->provider,
                'confirmed'    => $r['confirmed'] ? 1 : 0,
                'problem_runs' => $problemRuns,
                'checked_at'   => $now,
                'changed_at'   => $changed ? $now : (string) $old['changed_at'],
                'alerted_at'   => $alertedAt,
            ];
            $columns = array_keys($row);
            $updates = implode(', ', array_map(static fn (string $c): string => "`{$c}` = VALUES(`{$c}`)", array_diff($columns, ['website_id', 'country'])));
            $this->db->query(
                'INSERT INTO country_status (`' . implode('`, `', $columns) . '`) VALUES (:' . implode(', :', $columns) . ') ON DUPLICATE KEY UPDATE ' . $updates,
                $row
            );

            if ($result !== CountryClassifier::NO_PROBE) {
                $this->db->insert('country_checks', [
                    'website_id'  => $websiteId,
                    'country'     => $country,
                    'result'      => $result,
                    'status_code' => $probe?->statusCode,
                    'response_ms' => $probe?->responseMs,
                    'resolved_ip' => $probe?->resolvedIp,
                    'error'       => $probe?->error,
                    'network'     => $probe?->network,
                    'asn'         => $probe?->asn,
                    'city'        => $probe?->city,
                    'probe_type'  => $probe?->type,
                    'provider'    => $probe?->provider,
                    'checked_at'  => $now,
                ]);
            }

            // Alert once two runs in a row agree, so one bad probe never sends an alert.
            if ($isProblem && $alertedAt === null && $problemRuns >= 2) {
                $problems[] = $row + ['name' => \App\Countries\Countries::name($country)];
            }
            if (!$isProblem && $old !== null && !empty($old['alerted_at']) && $result !== CountryClassifier::DOWN) {
                $recovered[] = $row + ['name' => \App\Countries\Countries::name($country), 'was' => $old['result']];
            }
        }

        $this->db->query('UPDATE websites SET country_checked_at = :now WHERE id = :id', ['now' => $now, 'id' => $websiteId]);
        return ['problems' => $problems, 'recovered' => $recovered];
    }

    /**
     * @param list<string> $countries
     */
    public function markAlerted(int $websiteId, array $countries): void
    {
        if ($countries === []) {
            return;
        }
        [$in, $params] = $this->in($countries, 'c');
        $this->db->query("UPDATE country_status SET alerted_at = :now WHERE website_id = :id AND country IN ({$in})", $params + ['now' => $this->now(), 'id' => $websiteId]);
    }

    /**
     * Websites due for a check: never checked, or checked before $beforeUtc. Oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function due(string $beforeUtc, int $limit): array
    {
        $limit = max(1, $limit);
        return $this->db->fetchAll(
            "SELECT * FROM websites WHERE monitoring_enabled = 1 AND (country_checked_at IS NULL OR country_checked_at < :before)
             ORDER BY country_checked_at IS NOT NULL, country_checked_at ASC LIMIT {$limit}",
            ['before' => $beforeUtc]
        );
    }

    /**
     * Recent history of one website and country, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $websiteId, string $country, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        return $this->db->fetchAll(
            "SELECT * FROM country_checks WHERE website_id = :id AND country = :c ORDER BY checked_at DESC LIMIT {$limit}",
            ['id' => $websiteId, 'c' => $country]
        );
    }

    public function purgeOlderThan(string $cutoffUtc): int
    {
        return $this->db->query('DELETE FROM country_checks WHERE checked_at < :cut', ['cut' => $cutoffUtc])->rowCount();
    }

    /** Remove rows of countries that are no longer checked. */
    public function forgetCountriesExcept(array $countries): void
    {
        if ($countries === []) {
            return;
        }
        [$in, $params] = $this->in($countries, 'c');
        $this->db->query("DELETE FROM country_status WHERE country NOT IN ({$in})", $params);
    }
}
