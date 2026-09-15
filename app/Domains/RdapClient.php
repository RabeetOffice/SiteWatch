<?php

declare(strict_types=1);

namespace App\Domains;

/**
 * Registration Data Access Protocol client. The registry server for each TLD comes from IANA's bootstrap
 * registry, cached in storage/cache for a week.
 */
final class RdapClient
{
    private const BOOTSTRAP_URL = 'https://data.iana.org/rdap/dns.json';
    private const BOOTSTRAP_TTL = 7 * 86400;

    /** @var array<string, string>|null TLD => RDAP base URL */
    private ?array $servers = null;

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $cacheDir
    ) {
    }

    /**
     * RDAP base URL for a TLD, or null when the registry offers no RDAP service.
     */
    public function serverFor(string $tld): ?string
    {
        return $this->servers()[strtolower($tld)] ?? null;
    }

    /**
     * @return array{status: string, server: ?string, data: ?array<string, mixed>, error: ?string}
     *         status: found | not_found | unsupported | error
     */
    public function lookup(string $domain): array
    {
        $base = $this->serverFor(DomainName::tld($domain));
        if ($base === null) {
            return ['status' => 'unsupported', 'server' => null, 'data' => null, 'error' => null];
        }

        $response = $this->http->getJson(rtrim($base, '/') . '/domain/' . rawurlencode($domain));
        if ($response['status'] === 404) {
            return ['status' => 'not_found', 'server' => $base, 'data' => null, 'error' => null];
        }
        if ($response['data'] === null || $response['status'] >= 400) {
            $error = match (true) {
                $response['status'] === 429 => 'The registry is limiting lookups right now. Try again later.',
                $response['error'] !== null => 'The registry could not be reached: ' . $response['error'],
                default                     => 'The registry returned HTTP ' . $response['status'] . '.',
            };
            return ['status' => 'error', 'server' => $base, 'data' => null, 'error' => $error];
        }
        return ['status' => 'found', 'server' => $base, 'data' => $response['data'], 'error' => null];
    }

    /** @return array<string, string> */
    private function servers(): array
    {
        if ($this->servers !== null) {
            return $this->servers;
        }

        $file = $this->cacheDir . DIRECTORY_SEPARATOR . 'rdap-dns.json';
        $bootstrap = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($bootstrap) || (int) filemtime($file) < time() - self::BOOTSTRAP_TTL) {
            $response = $this->http->getJson(self::BOOTSTRAP_URL);
            if (is_array($response['data']) && isset($response['data']['services'])) {
                $bootstrap = $response['data'];
                @file_put_contents($file, json_encode($bootstrap), LOCK_EX);
            }
            // On failure a stale cached copy (if any) is still better than nothing.
        }

        $map = [];
        foreach ((array) ($bootstrap['services'] ?? []) as $service) {
            if (!is_array($service) || !isset($service[0], $service[1]) || !is_array($service[0]) || !is_array($service[1])) {
                continue;
            }
            $url = null;
            foreach ($service[1] as $candidate) {
                if (is_string($candidate) && str_starts_with($candidate, 'https://')) {
                    $url = $candidate;
                    break;
                }
            }
            if ($url === null) {
                continue;
            }
            foreach ($service[0] as $tld) {
                $map[strtolower((string) $tld)] = $url;
            }
        }
        return $this->servers = $map;
    }
}
