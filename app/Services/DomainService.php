<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Domains\DomainInspector;
use App\Domains\DomainName;
use App\Repositories\DomainRepository;
use App\Repositories\SettingsRepository;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Domain registration and hosting details for monitored websites, plus ad-hoc lookups of any domain.
 */
final class DomainService
{
    /** Domains expiring within this many days are flagged. */
    public const EXPIRY_WARNING_DAYS = 30;

    public function __construct(
        private readonly DomainRepository $domains,
        private readonly SettingsRepository $settings,
        private readonly DomainInspector $inspector
    ) {
    }

    public function maxAgeHours(): int
    {
        return max(1, $this->settings->getInt('domain_check_interval_hours', 24));
    }

    /**
     * Stored details for a website (presented), or null when it has never been checked.
     *
     * @param array<string, mixed> $website
     * @return array<string, mixed>|null
     */
    public function forWebsite(array $website): ?array
    {
        $row = $this->domains->findForWebsite((int) $website['id']);
        $details = $row !== null ? json_decode((string) $row['details'], true) : null;
        if (!is_array($details)) {
            return null;
        }
        return self::present($details) + ['stale' => $this->isStale($website, $row)];
    }

    /**
     * @param array<string, mixed> $website
     * @param array<string, mixed>|null $row
     */
    public function isStale(array $website, ?array $row): bool
    {
        if ($row === null || empty($row['checked_at'])) {
            return true;
        }
        if (strtolower((string) $row['host']) !== strtolower((string) $website['domain'])) {
            return true;
        }
        return strtotime($row['checked_at'] . ' UTC') < time() - $this->maxAgeHours() * 3600;
    }

    /**
     * Look up and store fresh details for a monitored website.
     *
     * @param array<string, mixed> $website
     * @return array<string, mixed>
     */
    public function refresh(array $website): array
    {
        $inspection = $this->inspector->inspect((string) $website['domain']);
        $this->domains->save((int) $website['id'], $inspection);
        return self::present($inspection) + ['stale' => false];
    }

    /**
     * Look up any host without storing anything.
     *
     * @return array<string, mixed>
     */
    public function lookup(string $host): array
    {
        return self::present($this->inspector->inspect($host));
    }

    /**
     * Refresh every website whose details are missing or stale (cron/domain-check.php).
     *
     * @return array{checked: int, failed: int}
     */
    public function refreshStale(int $limit = 1000, int $pauseMs = 500): array
    {
        $checked = 0;
        $failed = 0;
        foreach ($this->domains->stale($this->maxAgeHours(), $limit) as $website) {
            try {
                $result = $this->refresh($website);
                $checked++;
                if ($result['registration']['error'] !== null || $result['hosting']['error'] !== null) {
                    $failed++;
                }
            } catch (Throwable $e) {
                $failed++;
                App::logger('cron')->warning('Domain check failed', ['website_id' => (int) $website['id'], 'error' => $e->getMessage()]);
            }
            if ($pauseMs > 0) {
                usleep($pauseMs * 1000); // stay well inside registry rate limits
            }
        }
        return ['checked' => $checked, 'failed' => $failed];
    }

    // ------------------------------------------------------------------
    // Presentation
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $inspection DomainInspector::inspect() output
     * @return array<string, mixed>
     */
    public static function present(array $inspection): array
    {
        $reg = (array) ($inspection['registration'] ?? []);
        $host = (array) ($inspection['hosting'] ?? []);
        $age = self::age($reg['registered_at'] ?? null);
        $expiry = self::expiry($reg['expires_at'] ?? null);

        $countryCode = $host['country_code'] ?? null;
        $cdn = $host['cdn'] ?? null;
        $provider = $host['provider'] ?? null;
        // Behind a CDN (or on an anycast address) the location is an edge node, not the server.
        $edge = !empty($host['anycast']) || ($cdn !== null && $provider === null);
        $place = array_values(array_unique(array_filter([$host['city'] ?? null, $host['region'] ?? null, self::countryName($countryCode)])));
        $location = $edge
            ? 'Global edge network' . ($cdn !== null ? ' (' . $cdn . ')' : '')
            : ($place !== [] ? implode(', ', $place) : null);

        $domain = (string) ($inspection['domain'] ?? ($reg['domain'] ?? ''));

        return [
            'host'           => (string) ($inspection['host'] ?? ''),
            'domain'         => $domain,
            'domain_display' => DomainName::toUnicode($domain),
            'checked_at'     => $inspection['checked_at'] ?? null,
            'checked_label'  => format_datetime($inspection['checked_at'] ?? null),
            'registration'   => [
                'found'              => (bool) ($reg['found'] ?? false),
                'source'             => $reg['source'] ?? null,
                'source_label'       => match ($reg['source'] ?? null) { 'rdap' => 'RDAP', 'whois' => 'WHOIS', default => null },
                'server'             => $reg['server'] ?? null,
                'error'              => $reg['error'] ?? null,
                'registrar'          => $reg['registrar'] ?? null,
                'registrar_url'      => $reg['registrar_url'] ?? null,
                'registrar_iana_id'  => $reg['registrar_iana_id'] ?? null,
                'abuse_email'        => $reg['abuse_email'] ?? null,
                'registered_at'      => $reg['registered_at'] ?? null,
                'registered_label'   => format_date($reg['registered_at'] ?? null),
                'age_days'           => $age['days'],
                'age_label'          => $age['label'],
                'expires_at'         => $reg['expires_at'] ?? null,
                'expires_label'      => format_date($reg['expires_at'] ?? null),
                'expires_days'       => $expiry['days'],
                'expiry_label'       => $expiry['label'],
                'expiry_tone'        => $expiry['tone'],
                'updated_at'         => $reg['updated_at'] ?? null,
                'updated_label'      => format_date($reg['updated_at'] ?? null),
                'registrant'         => $reg['registrant'] ?? null,
                'registrant_country' => $reg['registrant_country'] ?? null,
                'nameservers'        => array_values((array) ($reg['nameservers'] ?? [])),
                'statuses'           => array_values((array) ($reg['statuses'] ?? [])),
                'dnssec'             => $reg['dnssec'] ?? null,
                'has_raw'            => ($reg['raw'] ?? null) !== null,
                'raw'                => $reg['raw'] ?? null,
            ],
            'hosting'        => [
                'error'          => $host['error'] ?? null,
                'provider'       => $provider,
                'cdn'            => $cdn,
                'organisation'   => $host['organisation'] ?? null,
                'asn'            => isset($host['asn']) ? (int) $host['asn'] : null,
                'as_name'        => $host['as_name'] ?? null,
                'network'        => $host['network'] ?? null,
                'primary_ip'     => $host['primary_ip'] ?? null,
                'ips'            => array_values((array) ($host['ips'] ?? [])),
                'ipv6'           => array_values((array) ($host['ipv6'] ?? [])),
                'reverse_dns'    => $host['reverse_dns'] ?? null,
                'cname'          => array_values((array) ($host['cname'] ?? [])),
                'server'         => $host['server'] ?? null,
                'powered_by'     => $host['powered_by'] ?? null,
                'http_status'    => $host['http_status'] ?? null,
                'country_code'   => $countryCode,
                'country'        => self::countryName($countryCode),
                'region'         => $host['region'] ?? null,
                'city'           => $host['city'] ?? null,
                'edge'           => $edge,
                'location_label' => $location,
                'nameservers'    => array_values((array) ($host['nameservers'] ?? [])),
                'dns_provider'   => $host['dns_provider'] ?? null,
                'mx'             => array_values((array) ($host['mx'] ?? [])),
                'email_provider' => $host['email_provider'] ?? null,
                'notes'          => array_values((array) ($host['notes'] ?? [])),
            ],
        ];
    }

    /**
     * One row of the Domains table (DomainRepository::search()).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function presentRow(array $row, int $maxAgeHours): array
    {
        $checked = !empty($row['checked_at']);
        $age = self::age($row['registered_at'] ?? null);
        $expiry = self::expiry($row['expires_at'] ?? null);
        $stale = !$checked
            || strtolower((string) $row['host']) !== strtolower((string) $row['website_domain'])
            || strtotime($row['checked_at'] . ' UTC') < time() - $maxAgeHours * 3600;

        return [
            'website_id'       => (int) $row['website_id'],
            'name'             => (string) $row['name'],
            'client_name'      => (string) ($row['client_name'] ?? ''),
            'url'              => (string) $row['url'],
            'host'             => (string) $row['website_domain'],
            'favicon_url'      => $row['favicon_url'] ?? null,
            'domain'           => (string) ($row['domain'] ?? DomainName::registrable((string) $row['website_domain'])),
            'checked'          => $checked,
            'stale'            => $stale,
            'checked_at'       => $row['checked_at'] ?? null,
            'registrar'        => $row['registrar'] ?? null,
            'registered_at'    => $row['registered_at'] ?? null,
            'registered_label' => format_date($row['registered_at'] ?? null),
            'age_days'         => $age['days'],
            'age_label'        => $age['label'],
            'expires_at'       => $row['expires_at'] ?? null,
            'expires_label'    => format_date($row['expires_at'] ?? null),
            'expires_days'     => $expiry['days'],
            'expiry_label'     => $expiry['label'],
            'expiry_tone'      => $expiry['tone'],
            'source'           => $row['whois_source'] ?? null,
            'whois_error'      => $row['whois_error'] ?? null,
            'hosting_error'    => $row['hosting_error'] ?? null,
            'provider'         => $row['hosting_provider'] ?? null,
            'cdn'              => $row['cdn'] ?? null,
            'country_code'     => $row['country_code'] ?? null,
            'country'          => self::countryName($row['country_code'] ?? null),
            'ip_address'       => $row['ip_address'] ?? null,
            'asn'              => isset($row['asn']) ? (int) $row['asn'] : null,
            'urls'             => ['details' => base_url('admin/website-details.php?id=' . (int) $row['website_id'])],
        ];
    }

    /**
     * @return array{days: ?int, label: string, tone: string}
     */
    public static function expiry(?string $expiresAt): array
    {
        $ts = $expiresAt ? strtotime($expiresAt . ' UTC') : false;
        if ($ts === false) {
            return ['days' => null, 'label' => 'Expiry unknown', 'tone' => 'neutral'];
        }
        $days = (int) floor(($ts - time()) / 86400);
        if ($days < 0) {
            return ['days' => $days, 'label' => 'Expired ' . self::span(abs($days)) . ' ago', 'tone' => 'danger'];
        }
        $tone = $days <= 7 ? 'danger' : ($days <= self::EXPIRY_WARNING_DAYS ? 'warning' : 'success');
        return ['days' => $days, 'label' => $days === 0 ? 'Expires today' : 'Expires in ' . self::span($days), 'tone' => $tone];
    }

    /**
     * @return array{days: ?int, label: string}
     */
    public static function age(?string $registeredAt): array
    {
        $ts = $registeredAt ? strtotime($registeredAt . ' UTC') : false;
        if ($ts === false) {
            return ['days' => null, 'label' => 'Unknown'];
        }
        $utc = new DateTimeZone('UTC');
        $diff = (new DateTimeImmutable('@' . $ts))->setTimezone($utc)->diff(new DateTimeImmutable('now', $utc));
        $parts = [];
        if ($diff->y > 0) {
            $parts[] = $diff->y . ($diff->y === 1 ? ' year' : ' years');
        }
        if ($diff->m > 0) {
            $parts[] = $diff->m . ($diff->m === 1 ? ' month' : ' months');
        }
        if ($parts === []) {
            $parts[] = $diff->d . ($diff->d === 1 ? ' day' : ' days');
        }
        return ['days' => max(0, intdiv(time() - $ts, 86400)), 'label' => implode(', ', $parts)];
    }

    public static function countryName(?string $code): ?string
    {
        if ($code === null || !preg_match('/^[A-Z]{2}$/', $code)) {
            return null;
        }
        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayRegion('-' . $code, 'en');
            if (is_string($name) && $name !== '' && $name !== $code) {
                return $name;
            }
        }
        return $code;
    }

    /** "12 days", "5 months", "2 years" */
    private static function span(int $days): string
    {
        if ($days < 60) {
            return $days . ($days === 1 ? ' day' : ' days');
        }
        if ($days < 730) {
            return (int) round($days / 30.44) . ' months';
        }
        return intdiv($days, 365) . ' years';
    }
}
