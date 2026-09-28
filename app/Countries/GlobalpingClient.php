<?php

declare(strict_types=1);

namespace App\Countries;

use RuntimeException;

/**
 * Globalping (https://globalping.io, run by jsDelivr): free HTTP tests from community probes in many countries,
 * a good share of them on home and office connections. 250 tests an hour without an account, 500 with a free
 * token; one test = one probe.
 */
class GlobalpingClient
{
    private const API = 'https://api.globalping.io/v1/measurements';

    /** Tests left this hour, from the last response (null: unknown). */
    public ?int $remaining = null;
    /** Seconds until the hourly allowance resets, from the last response. */
    public ?int $resetIn = null;

    public function __construct(private readonly JsonHttp $http, private readonly string $token = '')
    {
    }

    /**
     * Start an HTTP test of $url from one probe in each country.
     *
     * @param list<string> $countries
     * @return string Measurement id.
     * @throws RateLimitException when the hourly allowance is used up.
     */
    public function start(string $url, array $countries): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            throw new RuntimeException('The website address is not valid.');
        }
        $https = strtolower((string) ($parts['scheme'] ?? 'https')) === 'https';
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $body = [
            'type'      => 'http',
            'target'    => $parts['host'],
            'locations' => array_map(static fn (string $c): array => ['country' => $c, 'limit' => 1], array_values($countries)),
            'measurementOptions' => [
                'protocol' => $https ? 'HTTPS' : 'HTTP',
                'request'  => ['method' => 'GET', 'path' => $path === '' ? '/' : $path],
            ] + (isset($parts['port']) ? ['port' => (int) $parts['port']] : []),
        ];
        $res = $this->http->request('POST', self::API, $body, $this->token !== '' ? ['Authorization: Bearer ' . $this->token] : []);
        $this->readLimits($res['headers']);
        if ($res['status'] === 429) {
            throw new RateLimitException('The free Globalping allowance for this hour is used up.', $this->resetIn);
        }
        if ($res['error'] !== null) {
            throw new RuntimeException('Globalping could not be reached: ' . $res['error']);
        }
        if ($res['status'] === 422 || !is_array($res['data']) || empty($res['data']['id'])) {
            $message = is_array($res['data']) ? (string) ($res['data']['error']['message'] ?? '') : '';
            throw new RuntimeException('Globalping did not accept the test' . ($message !== '' ? ': ' . $message : ' (HTTP ' . $res['status'] . ').'));
        }
        return (string) $res['data']['id'];
    }

    /**
     * Wait for a measurement and return one probe per country.
     *
     * @return array<string, Probe>
     */
    public function results(string $id, int $maxWaitSeconds = 25): array
    {
        $deadline = microtime(true) + $maxWaitSeconds;
        $data = null;
        do {
            usleep(1_500_000);
            $res = $this->http->request('GET', self::API . '/' . rawurlencode($id));
            if (is_array($res['data'])) {
                $data = $res['data'];
                if (($data['status'] ?? '') !== 'in-progress') {
                    break;
                }
            }
        } while (microtime(true) < $deadline);

        $out = [];
        foreach ((array) ($data['results'] ?? []) as $entry) {
            $probe = $this->parse($entry);
            if ($probe !== null && !isset($out[$probe->country])) {
                $out[$probe->country] = $probe;
            }
        }
        return $out;
    }

    /**
     * @param mixed $entry One element of the "results" list.
     */
    public function parse(mixed $entry): ?Probe
    {
        if (!is_array($entry) || !is_array($entry['probe'] ?? null) || !is_array($entry['result'] ?? null)) {
            return null;
        }
        $p = $entry['probe'];
        $r = $entry['result'];
        $country = strtoupper((string) ($p['country'] ?? ''));
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            return null;
        }
        $tags = array_map('strval', (array) ($p['tags'] ?? []));
        $type = in_array('eyeball-network', $tags, true) ? 'home' : (in_array('datacenter-network', $tags, true) ? 'datacenter' : null);
        $status = ($r['status'] ?? '') === 'finished' && isset($r['statusCode']) ? (int) $r['statusCode'] : null;
        $headers = [];
        foreach ((array) ($r['headers'] ?? []) as $name => $value) {
            $headers[strtolower((string) $name)] = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
        }
        $error = null;
        if ($status === null) {
            $error = trim((string) ($r['rawOutput'] ?? '')) ?: 'The test did not finish.';
            $error = mb_substr(preg_replace('/\s+/', ' ', $error) ?? $error, 0, 250);
        }
        return new Probe(
            country: $country,
            provider: 'globalping',
            answered: $status !== null,
            statusCode: $status,
            responseMs: isset($r['timings']['total']) && is_numeric($r['timings']['total']) ? (int) $r['timings']['total'] : null,
            resolvedIp: isset($r['resolvedAddress']) && is_string($r['resolvedAddress']) ? $r['resolvedAddress'] : null,
            error: $error,
            network: isset($p['network']) ? mb_substr((string) $p['network'], 0, 120) : null,
            asn: isset($p['asn']) ? (int) $p['asn'] : null,
            city: isset($p['city']) ? mb_substr((string) $p['city'], 0, 80) : null,
            type: $type,
            headers: $headers,
            body: is_string($r['rawBody'] ?? null) ? mb_substr($r['rawBody'], 0, 20000) : ''
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function readLimits(array $headers): void
    {
        if (isset($headers['x-ratelimit-remaining']) && is_numeric($headers['x-ratelimit-remaining'])) {
            $this->remaining = (int) $headers['x-ratelimit-remaining'];
        }
        if (isset($headers['x-ratelimit-reset']) && is_numeric($headers['x-ratelimit-reset'])) {
            $this->resetIn = (int) $headers['x-ratelimit-reset'];
        }
    }
}
