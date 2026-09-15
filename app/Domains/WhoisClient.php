<?php

declare(strict_types=1);

namespace App\Domains;

/**
 * Port-43 WHOIS client, used for registries without RDAP (.ie, .io, .de, .eu, ...). The WHOIS server for each
 * TLD is discovered through whois.iana.org and cached in storage/cache for 30 days.
 */
final class WhoisClient
{
    private const IANA = 'whois.iana.org';
    private const SERVER_TTL = 30 * 86400;
    private const MAX_BYTES = 200000;

    /** Registries that need a specific query syntax to return a single, complete record. */
    private const QUERY_FORMATS = [
        'whois.verisign-grs.com' => 'domain %s',
        'whois.denic.de'         => '-T dn,ace %s',
        'whois.jprs.jp'          => '%s/e',
    ];

    /** @var array<string, array{server: string, at: int}>|null */
    private ?array $servers = null;

    public function __construct(
        private readonly string $cacheDir,
        private readonly int $timeout = 10
    ) {
    }

    /**
     * @return array{status: string, server: ?string, raw: ?string, parsed: ?array<string, mixed>, error: ?string}
     *         status: found | not_found | error
     */
    public function lookup(string $domain): array
    {
        $tld = DomainName::tld($domain);
        $error = null;
        $server = $this->serverFor($tld, $error);
        if ($server === null) {
            return ['status' => 'error', 'server' => null, 'raw' => null, 'parsed' => null, 'error' => $error ?? "No WHOIS server is published for .{$tld} domains."];
        }

        $raw = $this->query($server, sprintf(self::QUERY_FORMATS[$server] ?? '%s', $domain), $error);
        if ($raw === null) {
            return [
                'status' => 'error', 'server' => $server, 'raw' => null, 'parsed' => null,
                'error'  => "The WHOIS server {$server} could not be reached ({$error}). Some hosting providers block outbound port 43.",
            ];
        }
        $parsed = WhoisParser::parse($raw);
        return ['status' => $parsed['found'] ? 'found' : 'not_found', 'server' => $server, 'raw' => $raw, 'parsed' => $parsed, 'error' => null];
    }

    public function serverFor(string $tld, ?string &$error = null): ?string
    {
        $tld = strtolower($tld);
        $file = $this->cacheDir . DIRECTORY_SEPARATOR . 'whois-servers.json';
        if ($this->servers === null) {
            $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            $this->servers = is_array($decoded) ? $decoded : [];
        }

        $cached = $this->servers[$tld] ?? null;
        if (is_array($cached) && (int) ($cached['at'] ?? 0) > time() - self::SERVER_TTL) {
            return ($cached['server'] ?? '') !== '' ? (string) $cached['server'] : null;
        }

        $raw = $this->query(self::IANA, $tld, $error);
        if ($raw === null) {
            $error = 'The IANA WHOIS service could not be reached (' . $error . '). Some hosting providers block outbound port 43.';
            return null;
        }
        $server = preg_match('/^\s*(?:whois|refer):\s*(\S+)/mi', $raw, $m) ? strtolower($m[1]) : '';
        if (!preg_match('/^[a-z0-9.-]+$/', $server)) {
            $server = '';
        }
        $this->servers[$tld] = ['server' => $server, 'at' => time()];
        @file_put_contents($file, json_encode($this->servers), LOCK_EX);
        return $server !== '' ? $server : null;
    }

    private function query(string $server, string $query, ?string &$error): ?string
    {
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client('tcp://' . $server . ':43', $errno, $errstr, $this->timeout);
        if ($stream === false) {
            $error = $errstr !== '' ? $errstr : 'connection failed';
            return null;
        }
        stream_set_timeout($stream, $this->timeout);
        fwrite($stream, $query . "\r\n");

        $raw = '';
        $deadline = microtime(true) + $this->timeout;
        while (!feof($stream) && strlen($raw) < self::MAX_BYTES && microtime(true) < $deadline) {
            $chunk = fread($stream, 8192);
            if ($chunk === false || ($chunk === '' && stream_get_meta_data($stream)['timed_out'])) {
                break;
            }
            $raw .= $chunk;
        }
        fclose($stream);

        if (trim($raw) === '') {
            $error = 'empty response';
            return null;
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
        }
        return $raw;
    }
}
