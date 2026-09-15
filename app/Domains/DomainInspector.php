<?php

declare(strict_types=1);

namespace App\Domains;

use App\Core\App;
use App\Monitoring\MonitorManager;
use App\Monitoring\SsrfGuard;

/**
 * Looks up a host's domain registration (RDAP, falling back to WHOIS) and hosting details.
 */
final class DomainInspector
{
    private const RAW_LIMIT = 64000;

    public function __construct(
        private readonly RdapClient $rdap,
        private readonly WhoisClient $whois,
        private readonly HostingInspector $hosting
    ) {
    }

    public static function create(): self
    {
        $config = App::config();
        $settings = App::settings();
        $cache = (string) $config->get('app.paths.cache');
        $http = new HttpClient((string) $config->get('app.monitor.user_agent', 'SiteWatch'), MonitorManager::caBundlePath());

        return new self(
            new RdapClient($http, $cache),
            new WhoisClient($cache),
            new HostingInspector(
                new SsrfGuard((bool) $config->get('app.monitor.allow_private_targets', false)),
                $http,
                $settings->getBool('domain_geo_lookup', true),
                $settings->getString('ipinfo_token')
            )
        );
    }

    /**
     * @return array{host: string, domain: string, registration: array<string, mixed>, hosting: array<string, mixed>, checked_at: string}
     */
    public function inspect(string $host): array
    {
        $host = strtolower(rtrim(trim($host), '.'));
        $registration = $this->registration($host);
        return [
            'host'         => $host,
            'domain'       => (string) $registration['domain'],
            'registration' => $registration,
            'hosting'      => $this->hosting->inspect($host, (string) $registration['domain']),
            'checked_at'   => gmdate('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function registration(string $host): array
    {
        $result = [
            'found' => false, 'domain' => DomainName::registrable($host), 'source' => null, 'server' => null, 'error' => null, 'raw' => null,
            'registrar' => null, 'registrar_url' => null, 'registrar_iana_id' => null, 'abuse_email' => null,
            'registered_at' => null, 'expires_at' => null, 'updated_at' => null,
            'nameservers' => [], 'statuses' => [], 'registrant' => null, 'registrant_country' => null, 'dnssec' => null,
        ];
        $notFound = false;
        $lastError = null;

        foreach (DomainName::candidates($host) as $candidate) {
            $rdap = $this->rdap->lookup($candidate);
            if ($rdap['status'] === 'found' && $rdap['data'] !== null) {
                $raw = json_encode($rdap['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: null;
                return array_merge($result, RdapParser::parse($rdap['data']), [
                    'found' => true, 'domain' => $candidate, 'source' => 'rdap', 'server' => $rdap['server'], 'raw' => self::limit($raw),
                ]);
            }
            if ($rdap['status'] === 'not_found') {
                $notFound = true;
                continue;
            }

            // No RDAP service for this TLD, or the RDAP server failed: try classic WHOIS.
            $whois = $this->whois->lookup($candidate);
            if ($whois['status'] === 'found' && $whois['parsed'] !== null) {
                $parsed = $whois['parsed'];
                unset($parsed['registrar_whois']);
                return array_merge($result, $parsed, [
                    'found' => true, 'domain' => $candidate, 'source' => 'whois', 'server' => $whois['server'], 'raw' => self::limit($whois['raw']),
                ]);
            }
            if ($whois['status'] === 'not_found') {
                $notFound = true;
                $result['raw'] = self::limit($whois['raw']);
                continue;
            }
            $lastError = $rdap['error'] ?? $whois['error'];
        }

        $result['error'] = $lastError
            ?? ($notFound ? 'The registry has no record of this domain. It may be unregistered or recently expired.' : 'Registration details are unavailable.');
        return $result;
    }

    private static function limit(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        return strlen($raw) > self::RAW_LIMIT ? mb_strcut($raw, 0, self::RAW_LIMIT) . "\n…(truncated)" : $raw;
    }
}
