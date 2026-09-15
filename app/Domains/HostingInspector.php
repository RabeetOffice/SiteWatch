<?php

declare(strict_types=1);

namespace App\Domains;

use App\Monitoring\SsrfGuard;

/**
 * Works out where a website is hosted: its IP addresses and reverse DNS, the network (ASN) announcing the
 * address, the hosting company or CDN in front of it, the server location, and the domain's DNS and mail
 * providers.
 *
 * Sources: the server's DNS resolver, Team Cymru's IP-to-ASN DNS service (no key, no HTTP), ipinfo.io for
 * city-level location (optional) and a HEAD request to the website for platform/CDN headers. Each step is
 * best-effort: one failing never prevents the others.
 */
final class HostingInspector
{
    public function __construct(
        private readonly SsrfGuard $guard,
        private readonly HttpClient $http,
        private readonly bool $geoLookup = true,
        private readonly string $ipinfoToken = ''
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function inspect(string $host, string $domain): array
    {
        $result = [
            'ips' => [], 'ipv6' => [], 'primary_ip' => null, 'cname' => [], 'reverse_dns' => null,
            'asn' => null, 'as_name' => null, 'network' => null, 'organisation' => null,
            'provider' => null, 'cdn' => null, 'server' => null, 'powered_by' => null, 'http_status' => null,
            'country_code' => null, 'region' => null, 'city' => null, 'anycast' => false,
            'nameservers' => [], 'dns_provider' => null, 'mx' => [], 'email_provider' => null,
            'error' => null, 'notes' => [],
        ];

        [$result['ips'], $result['ipv6'], $result['cname']] = $this->resolve($host);
        [$result['nameservers'], $result['mx']] = $this->domainRecords($domain);
        $result['dns_provider'] = HostingDetector::dnsProvider($result['nameservers']);
        $result['email_provider'] = HostingDetector::emailProvider(array_column($result['mx'], 'host'));

        $primary = $result['ips'][0] ?? $result['ipv6'][0] ?? null;
        $result['primary_ip'] = $primary;
        if ($primary === null) {
            $result['error'] = "{$host} does not resolve to an IP address.";
            return $result;
        }
        if (!SsrfGuard::isPublicIp($primary)) {
            $result['error'] = "{$host} resolves to a private or internal address ({$primary}), so hosting details are not looked up.";
            return $result;
        }

        $result['reverse_dns'] = $this->reverseDns($primary);
        $this->network($primary, $result);
        if ($this->geoLookup) {
            $this->location($primary, $result);
        }

        $probe = $this->probe($host);
        $result['http_status'] = $probe['status'] > 0 ? $probe['status'] : null;
        $headers = HostingDetector::fromHeaders($probe['headers']);
        $result['server'] = $headers['server'];
        $result['powered_by'] = $headers['powered_by'];

        $networkCdn = HostingDetector::cdn((string) $result['as_name'], (string) $result['reverse_dns'], ...$result['cname']);
        $result['cdn'] = $headers['cdn'] ?? $networkCdn;

        // The platform headers and CNAME targets survive a CDN; the network owner only identifies the host
        // when no CDN sits in front of the website.
        $provider = $headers['platform'] ?? HostingDetector::provider(...$result['cname']);
        if ($provider === null && $networkCdn === null) {
            $provider = HostingDetector::provider((string) $result['reverse_dns'], (string) $result['as_name']) ?? $result['organisation'];
        }
        if ($provider === null && $result['cdn'] === 'Hostinger CDN') {
            $provider = 'Hostinger';
        }
        $result['provider'] = $provider !== null ? mb_substr($provider, 0, 120) : null;

        if ($networkCdn !== null) {
            $result['notes'][] = $provider === null
                ? "Traffic goes through {$networkCdn}, which hides the origin server, so the hosting company cannot be identified from outside."
                : "Traffic goes through {$networkCdn}; {$provider} was identified from the website's DNS or response headers.";
        }
        return $result;
    }

    /** @return array{0: array<int, string>, 1: array<int, string>, 2: array<int, string>} IPv4, IPv6, CNAME targets */
    private function resolve(string $host): array
    {
        $v4 = [];
        $v6 = [];
        $cname = [];
        foreach (@dns_get_record($host, DNS_CNAME) ?: [] as $record) {
            if (!empty($record['target'])) {
                $cname[] = strtolower(rtrim((string) $record['target'], '.'));
            }
        }
        foreach (@dns_get_record($host, DNS_A) ?: [] as $record) {
            if (!empty($record['ip']) && filter_var($record['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $v4[] = (string) $record['ip'];
            }
        }
        if ($v4 === []) {
            $fallback = @gethostbynamel($host);
            $v4 = is_array($fallback) ? $fallback : [];
        }
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (!empty($record['ipv6']) && filter_var($record['ipv6'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $v6[] = (string) $record['ipv6'];
            }
        }
        return [array_values(array_unique($v4)), array_values(array_unique($v6)), array_values(array_unique($cname))];
    }

    /** @return array{0: array<int, string>, 1: array<int, array{host: string, priority: int}>} */
    private function domainRecords(string $domain): array
    {
        $nameservers = [];
        foreach (@dns_get_record($domain, DNS_NS) ?: [] as $record) {
            if (!empty($record['target'])) {
                $nameservers[] = strtolower(rtrim((string) $record['target'], '.'));
            }
        }
        sort($nameservers);

        $mx = [];
        foreach (@dns_get_record($domain, DNS_MX) ?: [] as $record) {
            $target = strtolower(rtrim((string) ($record['target'] ?? ''), '.'));
            if ($target !== '') {
                $mx[] = ['host' => $target, 'priority' => (int) ($record['pri'] ?? 0)];
            }
        }
        usort($mx, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return [array_values(array_unique($nameservers)), array_slice($mx, 0, 10)];
    }

    private function reverseDns(string $ip): ?string
    {
        $record = @dns_get_record($this->reverseName($ip, '.in-addr.arpa', '.ip6.arpa'), DNS_PTR);
        $target = is_array($record) && isset($record[0]['target']) ? strtolower(rtrim((string) $record[0]['target'], '.')) : '';
        return $target !== '' ? mb_substr($target, 0, 253) : null;
    }

    /**
     * ASN, announced prefix, registry country and network name from Team Cymru's DNS service.
     *
     * @param array<string, mixed> $result
     */
    private function network(string $ip, array &$result): void
    {
        $origin = $this->txt($this->reverseName($ip, '.origin.asn.cymru.com', '.origin6.asn.cymru.com'));
        if ($origin === null) {
            return;
        }
        // "13335 | 104.16.0.0/13 | US | arin | 2014-03-28" (several ASNs may be listed first)
        $parts = array_map('trim', explode('|', $origin));
        $asn = (int) strtok($parts[0], ' ');
        if ($asn <= 0) {
            return;
        }
        $result['asn'] = $asn;
        $result['network'] = ($parts[1] ?? '') !== '' ? $parts[1] : null;
        $country = strtoupper($parts[2] ?? '');
        if (preg_match('/^[A-Z]{2}$/', $country)) {
            $result['country_code'] = $country;
        }

        // "13335 | US | arin | 2010-07-14 | CLOUDFLARENET, US"
        $info = $this->txt('AS' . $asn . '.asn.cymru.com');
        if ($info !== null) {
            $infoParts = array_map('trim', explode('|', $info));
            $name = (string) end($infoParts);
            if ($name !== '') {
                $result['as_name'] = mb_substr($name, 0, 190);
                $result['organisation'] = HostingDetector::organisation($name);
            }
        }
    }

    /**
     * City, region, country and anycast flag from ipinfo.io.
     *
     * @param array<string, mixed> $result
     */
    private function location(string $ip, array &$result): void
    {
        $headers = $this->ipinfoToken !== '' ? ['Authorization: Bearer ' . $this->ipinfoToken] : [];
        $response = $this->http->getJson('https://ipinfo.io/' . rawurlencode($ip) . '/json', $headers);
        $data = $response['data'];
        if ($response['status'] === 429) {
            $result['notes'][] = 'The ipinfo.io location limit was reached. Add an ipinfo.io token under Monitoring settings for higher limits.';
            return;
        }
        if (!is_array($data) || isset($data['error']) || !empty($data['bogon'])) {
            return;
        }
        $text = static fn (string $key): ?string => isset($data[$key]) && is_string($data[$key]) && trim($data[$key]) !== '' ? mb_substr(trim($data[$key]), 0, 100) : null;

        $result['city'] = $text('city');
        $result['region'] = $text('region');
        $country = strtoupper((string) $text('country'));
        if (preg_match('/^[A-Z]{2}$/', $country)) {
            $result['country_code'] = $country;
        }
        $result['anycast'] = !empty($data['anycast']);
        $result['reverse_dns'] ??= $text('hostname');
        $org = $text('org');
        if ($result['asn'] === null && $org !== null && preg_match('/^AS(\d+)\s+(.+)$/', $org, $m)) {
            $result['asn'] = (int) $m[1];
            $result['as_name'] = $m[2];
            $result['organisation'] = $m[2];
        }
    }

    /**
     * HEAD request to the website (HTTPS first) for platform and CDN headers, SSRF-validated.
     *
     * @return array{status: int, headers: array<string, string>, error: ?string}
     */
    private function probe(string $host): array
    {
        $last = ['status' => 0, 'headers' => [], 'error' => null];
        foreach (['https', 'http'] as $scheme) {
            $resolution = $this->guard->resolve($scheme . '://' . $host . '/');
            if (!$resolution['ok']) {
                return ['status' => 0, 'headers' => [], 'error' => $resolution['error'] ?? null];
            }
            $ip = $resolution['ips'][0];
            $last = $this->http->probeHeaders($scheme, $host, $ip);
            if ($last['status'] === 0 && $scheme === 'https' && preg_match('/ssl|certificate|tls/i', (string) $last['error'])) {
                // An invalid certificate still reveals the platform headers; nothing is sent but a HEAD request.
                $last = $this->http->probeHeaders($scheme, $host, $ip, false);
            }
            if ($last['status'] > 0) {
                return $last;
            }
        }
        return $last;
    }

    private function txt(string $name): ?string
    {
        $records = @dns_get_record($name, DNS_TXT);
        if (!is_array($records)) {
            return null;
        }
        foreach ($records as $record) {
            $txt = trim((string) ($record['txt'] ?? ''));
            if ($txt !== '') {
                return $txt;
            }
        }
        return null;
    }

    /**
     * Reversed address label: 4.3.2.1 + $v4Suffix, or the IPv6 nibbles + $v6Suffix.
     */
    private function reverseName(string $ip, string $v4Suffix, string $v6Suffix): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return implode('.', array_reverse(explode('.', $ip))) . $v4Suffix;
        }
        $hex = bin2hex((string) inet_pton($ip));
        return implode('.', array_reverse(str_split($hex))) . $v6Suffix;
    }
}
