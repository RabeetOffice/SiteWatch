<?php

declare(strict_types=1);

namespace App\Countries;

/**
 * check-host.net: free HTTP checks from about 50 servers. Used only for countries where Globalping had no probe,
 * so the free service is asked sparingly.
 */
class CheckHostClient
{
    private const BASE = 'https://check-host.net';

    /** @var array<string, list<array{node: string, city: string, asn: string}>>|null Country => nodes. */
    private ?array $nodes = null;

    public function __construct(private readonly JsonHttp $http, private readonly ?string $cacheFile = null)
    {
    }

    /**
     * Countries with at least one check-host.net server.
     *
     * @return array<string, list<array{node: string, city: string, asn: string}>>
     */
    public function nodes(): array
    {
        if ($this->nodes !== null) {
            return $this->nodes;
        }
        if ($this->cacheFile !== null && is_file($this->cacheFile) && filemtime($this->cacheFile) > time() - 86400) {
            $cached = json_decode((string) file_get_contents($this->cacheFile), true);
            if (is_array($cached)) {
                return $this->nodes = $cached;
            }
        }
        $res = $this->http->request('GET', self::BASE . '/nodes/hosts');
        $map = [];
        foreach ((array) ($res['data']['nodes'] ?? []) as $node => $info) {
            $loc = (array) ($info['location'] ?? []);
            $cc = strtoupper((string) ($loc[0] ?? ''));
            if (preg_match('/^[A-Z]{2}$/', $cc)) {
                $map[$cc][] = ['node' => (string) $node, 'city' => (string) ($loc[2] ?? ''), 'asn' => (string) ($info['asn'] ?? '')];
            }
        }
        if ($map !== [] && $this->cacheFile !== null) {
            @file_put_contents($this->cacheFile, (string) json_encode($map));
        }
        return $this->nodes = $map;
    }

    /**
     * Check $url from one server in each country (skipping countries without one; $offset picks another server
     * for a second opinion).
     *
     * @param list<string> $countries
     * @return array<string, Probe>
     */
    public function check(string $url, array $countries, int $offset = 0, int $maxWaitSeconds = 20): array
    {
        $nodes = $this->nodes();
        $picked = [];
        foreach ($countries as $country) {
            $list = $nodes[$country] ?? [];
            if ($list !== []) {
                $picked[$country] = $list[$offset % count($list)];
            }
        }
        if ($picked === []) {
            return [];
        }
        $query = 'host=' . rawurlencode($url);
        foreach ($picked as $info) {
            $query .= '&node=' . rawurlencode($info['node']);
        }
        $start = $this->http->request('GET', self::BASE . '/check-http?' . $query);
        $id = is_array($start['data']) ? (string) ($start['data']['request_id'] ?? '') : '';
        if ($id === '') {
            return [];
        }

        $deadline = microtime(true) + $maxWaitSeconds;
        $results = [];
        do {
            usleep(2_000_000);
            $res = $this->http->request('GET', self::BASE . '/check-result/' . rawurlencode($id));
            $results = is_array($res['data']) ? $res['data'] : [];
            $pending = array_filter($picked, static fn (array $info): bool => !isset($results[$info['node']]) || $results[$info['node']] === null);
        } while ($pending !== [] && microtime(true) < $deadline);

        $out = [];
        foreach ($picked as $country => $info) {
            $row = $results[$info['node']][0] ?? null;
            if (!is_array($row)) {
                continue;
            }
            $out[$country] = $this->parse($country, $info, $row);
        }
        return $out;
    }

    /**
     * One check-host.net result row: [success(0|1), seconds, message, http code, ip].
     *
     * @param array{node: string, city: string, asn: string} $info
     * @param array<int, mixed> $row
     */
    public function parse(string $country, array $info, array $row): Probe
    {
        $code = isset($row[3]) && is_numeric($row[3]) ? (int) $row[3] : null;
        $message = isset($row[2]) ? (string) $row[2] : '';
        return new Probe(
            country: $country,
            provider: 'checkhost',
            answered: $code !== null,
            statusCode: $code,
            responseMs: isset($row[1]) && is_numeric($row[1]) ? (int) round((float) $row[1] * 1000) : null,
            resolvedIp: isset($row[4]) && is_string($row[4]) && $row[4] !== '' ? $row[4] : null,
            error: $code === null ? mb_substr($message !== '' ? $message : 'No answer.', 0, 250) : null,
            network: $info['asn'] !== '' ? $info['asn'] : null,
            asn: $info['asn'] !== '' ? (int) preg_replace('/\D/', '', $info['asn']) : null,
            city: $info['city'] !== '' ? $info['city'] : null,
            type: 'datacenter'
        );
    }
}
