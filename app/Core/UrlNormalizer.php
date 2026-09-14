<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Validates and normalises administrator-supplied website URLs.
 *
 *   example.com            -> https://example.com/
 *   HTTP://Example.com/x/  -> http://example.com/x/
 *   https://example.com:443 -> https://example.com/
 */
final class UrlNormalizer
{
    /**
     * @return string|null Normalised absolute URL, or null when the input is not a valid public http(s) URL.
     */
    public static function normalize(?string $input): ?string
    {
        $input = trim((string) $input);
        if ($input === '' || strlen($input) > 2048) {
            return null;
        }
        // Reject control characters / whitespace inside the URL
        if (preg_match('/[\s\x00-\x1F\x7F]/', $input)) {
            return null;
        }
        if (!preg_match('#^[a-z][a-z0-9+\-.]*://#i', $input)) {
            $input = 'https://' . ltrim($input, '/');
        }

        $parts = parse_url($input);
        if ($parts === false || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null; // credentials in URLs are not supported
        }

        $host = self::normalizeHost($parts['host']);
        if ($host === null) {
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            return null;
        }
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        $path = $parts['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }
        // Encode any stray characters while preserving existing percent-encoding and slashes.
        $path = implode('/', array_map(
            static fn (string $segment): string => rawurlencode(rawurldecode($segment)),
            explode('/', $path)
        ));

        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

        return $scheme . '://' . $host . ($port !== null ? ':' . $port : '') . $path . $query;
    }

    /**
     * Lower-cases, strips trailing dots and converts IDN hosts to punycode. Returns null when invalid.
     */
    public static function normalizeHost(string $host): ?string
    {
        $host = strtolower(rtrim(trim($host), '.'));
        if ($host === '' || strlen($host) > 253) {
            return null;
        }

        // IPv6 literal
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $ip = substr($host, 1, -1);
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[' . $ip . ']' : null;
        }
        // IPv4 literal
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $host;
        }

        // Internationalised domain names -> ASCII
        if (preg_match('/[^\x00-\x7F]/', $host)) {
            if (!function_exists('idn_to_ascii')) {
                return null;
            }
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                return null;
            }
            $host = strtolower($ascii);
        }

        if (!preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/', $host)) {
            return null;
        }
        // Require at least one dot (a public website), except for explicit localhost which the SSRF guard handles.
        if (!str_contains($host, '.') && $host !== 'localhost') {
            return null;
        }
        return $host;
    }

    /**
     * Display domain for a normalised URL (host, without the scheme).
     */
    public static function domain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) ? $host : $url;
    }

    public static function isHttps(string $url): bool
    {
        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }
}
