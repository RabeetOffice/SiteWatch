<?php

declare(strict_types=1);

namespace App\Monitoring;

/**
 * Server-Side Request Forgery protection.
 *
 * Every monitoring target (and every redirect destination) is resolved to IP addresses first.
 * Targets resolving to loopback, private, link-local, CG-NAT, multicast, reserved or cloud
 * metadata addresses are rejected unless MONITOR_ALLOW_PRIVATE=true. The resolved addresses are
 * then pinned for the actual request (CURLOPT_RESOLVE), which defeats DNS rebinding.
 */
final class SsrfGuard
{
    /** Hostnames that are always blocked (cloud metadata services). */
    private const BLOCKED_HOSTS = [
        'localhost',
        'metadata.google.internal',
        'metadata',
        'instance-data',
        'instance-data.ec2.internal',
    ];

    /** Resolution cache lifetime (seconds) within one process: hops and SSL checks reuse lookups. */
    private const CACHE_TTL = 120;

    /** @var array<string, array{ips: array<int, string>, at: int}> */
    private static array $cache = [];

    public function __construct(private readonly bool $allowPrivate = false)
    {
    }

    public function allowsPrivate(): bool
    {
        return $this->allowPrivate;
    }

    /**
     * Resolve a URL's host and validate the addresses.
     *
     * @return array{ok: bool, host: string, port: int, ips: array<int, string>, error_kind?: string, error?: string}
     */
    public function resolve(string $url): array
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? 'http')) : 'http';
        $port = is_array($parts) && isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        if ($host === '' || !in_array($scheme, ['http', 'https'], true)) {
            return ['ok' => false, 'host' => $host, 'port' => $port, 'ips' => [], 'error_kind' => ProbeResult::ERROR_INVALID_URL, 'error' => 'Invalid URL.'];
        }

        $host = strtolower(trim($host, '[]'));

        if (!$this->allowPrivate && (in_array($host, self::BLOCKED_HOSTS, true) || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal') || str_ends_with($host, '.local'))) {
            return ['ok' => false, 'host' => $host, 'port' => $port, 'ips' => [], 'error_kind' => ProbeResult::ERROR_BLOCKED, 'error' => "Monitoring of internal host \"{$host}\" is not permitted."];
        }

        $ips = $this->lookup($host);
        if ($ips === []) {
            return ['ok' => false, 'host' => $host, 'port' => $port, 'ips' => [], 'error_kind' => ProbeResult::ERROR_DNS, 'error' => "DNS lookup failed: could not resolve \"{$host}\"."];
        }

        if (!$this->allowPrivate) {
            foreach ($ips as $ip) {
                if (!self::isPublicIp($ip)) {
                    return [
                        'ok'         => false,
                        'host'       => $host,
                        'port'       => $port,
                        'ips'        => $ips,
                        'error_kind' => ProbeResult::ERROR_BLOCKED,
                        'error'      => "\"{$host}\" resolves to a private or internal address ({$ip}); monitoring blocked for security.",
                    ];
                }
            }
        }

        return ['ok' => true, 'host' => $host, 'port' => $port, 'ips' => $ips];
    }

    /**
     * Resolve a hostname to IPv4 + IPv6 addresses (or return the literal IP).
     *
     * @return array<int, string>
     */
    public function lookup(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $cached = self::$cache[$host] ?? null;
        if ($cached !== null && $cached['at'] > time() - self::CACHE_TTL) {
            return $cached['ips'];
        }

        $ips = [];
        // The OS resolver (with its cache) is fast; only the addresses returned here are pinned for
        // the request, so validating exactly this set is sufficient.
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            foreach ($v4 as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $ips[] = $ip;
                }
            }
        }
        if ($ips === []) {
            // No A records (or resolver quirk): try AAAA, then a direct A query.
            $records = @dns_get_record($host, DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (!empty($record['ipv6']) && filter_var($record['ipv6'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }
        if ($ips === []) {
            $records = @dns_get_record($host, DNS_A);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (!empty($record['ip']) && filter_var($record['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $ips[] = $record['ip'];
                    }
                }
            }
        }
        $ips = array_values(array_unique($ips));
        if ($ips !== []) {
            self::$cache[$host] = ['ips' => $ips, 'at' => time()];
        }
        return $ips;
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * True when the address is a globally routable, non-reserved unicast address.
     */
    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return self::isPublicIpv4($ip);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return self::isPublicIpv6($ip);
        }
        return false;
    }

    private static function isPublicIpv4(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }
        $blocked = [
            ['0.0.0.0', 8],        // "this" network
            ['10.0.0.0', 8],       // private
            ['100.64.0.0', 10],    // carrier-grade NAT
            ['127.0.0.0', 8],      // loopback
            ['169.254.0.0', 16],   // link-local (includes cloud metadata 169.254.169.254)
            ['172.16.0.0', 12],    // private
            ['192.0.0.0', 24],     // IETF protocol assignments
            ['192.0.2.0', 24],     // TEST-NET-1
            ['192.88.99.0', 24],   // 6to4 relay (deprecated)
            ['192.168.0.0', 16],   // private
            ['198.18.0.0', 15],    // benchmarking
            ['198.51.100.0', 24],  // TEST-NET-2
            ['203.0.113.0', 24],   // TEST-NET-3
            ['224.0.0.0', 4],      // multicast
            ['240.0.0.0', 4],      // reserved + broadcast
        ];
        foreach ($blocked as [$network, $bits]) {
            if (self::inRangeV4($long, $network, $bits)) {
                return false;
            }
        }
        return true;
    }

    private static function inRangeV4(int $long, string $network, int $bits): bool
    {
        $net = ip2long($network);
        $mask = $bits === 0 ? 0 : (~((1 << (32 - $bits)) - 1)) & 0xFFFFFFFF;
        return ($long & $mask) === ($net & $mask);
    }

    private static function isPublicIpv6(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) {
            return false;
        }
        // IPv4-mapped (::ffff:a.b.c.d) and IPv4-compatible / NAT64 / 6to4 addresses: validate the embedded IPv4.
        if (substr($bin, 0, 10) === str_repeat("\0", 10) && substr($bin, 10, 2) === "\xff\xff") {
            return self::isPublicIpv4(inet_ntop(substr($bin, 12)) ?: '0.0.0.0');
        }
        if (substr($bin, 0, 12) === "\x00\x64\xff\x9b\x00\x00\x00\x00\x00\x00\x00\x00") { // 64:ff9b::/96 NAT64
            return self::isPublicIpv4(inet_ntop(substr($bin, 12)) ?: '0.0.0.0');
        }
        if (substr($bin, 0, 2) === "\x20\x02") { // 2002::/16 6to4
            return self::isPublicIpv4(inet_ntop(substr($bin, 2, 4)) ?: '0.0.0.0');
        }

        $blocked = [
            ['::', 128],          // unspecified
            ['::1', 128],         // loopback
            ['::', 96],           // IPv4-compatible (deprecated)
            ['fc00::', 7],        // unique local (includes AWS IMDS fd00:ec2::254)
            ['fe80::', 10],       // link-local
            ['fec0::', 10],       // site-local (deprecated)
            ['ff00::', 8],        // multicast
            ['2001:db8::', 32],   // documentation
            ['2001::', 32],       // Teredo
            ['100::', 64],        // discard-only
        ];
        foreach ($blocked as [$network, $bits]) {
            if (self::inRangeV6($bin, $network, $bits)) {
                return false;
            }
        }
        return true;
    }

    private static function inRangeV6(string $bin, string $network, int $bits): bool
    {
        $net = inet_pton($network);
        if ($net === false) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        if ($bytes > 0 && substr($bin, 0, $bytes) !== substr($net, 0, $bytes)) {
            return false;
        }
        if ($remainder === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainder)) & 0xFF;
        return (ord($bin[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }
}
