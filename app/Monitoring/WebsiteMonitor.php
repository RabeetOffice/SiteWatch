<?php

declare(strict_types=1);

namespace App\Monitoring;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Performs the HTTP probes. Websites are checked concurrently in batches using Guzzle promises.
 *
 * Redirects are followed manually (allow_redirects = false) so that every hop is validated by the
 * SSRF guard and pinned to its resolved IP addresses via CURLOPT_RESOLVE. One failing website can
 * never abort the batch: every promise resolves to a ProbeResult, errors included.
 */
final class WebsiteMonitor
{
    private const REDIRECT_CODES = [301, 302, 303, 307, 308];

    private Client $client;
    /** @var array{connect_timeout: int, timeout: int, max_redirects: int, user_agent: string, verify: bool|string, body_limit: int} */
    private array $options;

    /**
     * @param array{connect_timeout?: int, timeout?: int, max_redirects?: int, user_agent?: string, verify?: bool|string, body_limit?: int} $options
     */
    public function __construct(private readonly SsrfGuard $guard, array $options = [], ?Client $client = null)
    {
        $this->options = [
            'connect_timeout' => max(2, (int) ($options['connect_timeout'] ?? 10)),
            'timeout'         => max(5, (int) ($options['timeout'] ?? 30)),
            'max_redirects'   => max(0, (int) ($options['max_redirects'] ?? 10)),
            'user_agent'      => (string) ($options['user_agent'] ?? 'Mozilla/5.0 (compatible; SiteWatch/1.0)'),
            'verify'          => $options['verify'] ?? true,
            'body_limit'      => max(16384, (int) ($options['body_limit'] ?? 512 * 1024)),
        ];
        // A short select timeout matters: redirect hops are queued from inside promise callbacks, and
        // Guzzle's default 1 s curl_multi_select() wait would add a full second to every hop.
        $handler = HandlerStack::create(new CurlMultiHandler(['select_timeout' => 0.05]));
        $this->client = $client ?? new Client([
            'handler'         => $handler,
            'http_errors'     => false,
            'allow_redirects' => false,
            'cookies'         => false,
            'decode_content'  => true,
            'version'         => 1.1,
        ]);
    }

    /**
     * Probe many websites concurrently, in batches of $concurrency.
     *
     * @param array<int, array<string, mixed>> $websites Rows with at least id + url
     * @return array<int, ProbeResult> keyed by website id
     */
    public function probeMany(array $websites, int $concurrency = 15): array
    {
        $concurrency = max(1, min(50, $concurrency));
        $results = [];

        foreach (array_chunk($websites, $concurrency) as $batch) {
            $promises = [];
            foreach ($batch as $website) {
                $id = (int) $website['id'];
                $promises[$id] = $this->probeAsync((string) $website['url']);
            }
            $settled = Utils::settle($promises)->wait();
            foreach ($settled as $id => $outcome) {
                if (($outcome['state'] ?? '') === 'fulfilled' && $outcome['value'] instanceof ProbeResult) {
                    $results[(int) $id] = $outcome['value'];
                    continue;
                }
                $reason = $outcome['reason'] ?? null;
                $message = $reason instanceof Throwable ? $reason->getMessage() : 'Unknown monitoring failure';
                $url = '';
                foreach ($batch as $website) {
                    if ((int) $website['id'] === (int) $id) {
                        $url = (string) $website['url'];
                    }
                }
                $results[(int) $id] = ProbeResult::error($url, ProbeResult::ERROR_UNKNOWN, $message, 0);
            }
        }
        return $results;
    }

    /**
     * Probe a single website synchronously.
     */
    public function probe(string $url): ProbeResult
    {
        $result = $this->probeAsync($url)->wait();
        return $result instanceof ProbeResult ? $result : ProbeResult::error($url, ProbeResult::ERROR_UNKNOWN, 'Unknown monitoring failure', 0);
    }

    /**
     * @return PromiseInterface<ProbeResult>
     */
    public function probeAsync(string $url): PromiseInterface
    {
        $state = [
            'origin'   => $url,
            'started'  => gmdate('Y-m-d H:i:s'),
            'chain'    => [],
            'visited'  => [],
            'ip'       => null,
            'transfer' => 0.0, // seconds actually spent transferring (sum of curl total_time per hop)
        ];
        return $this->hop($url, 0, $state);
    }

    /**
     * Response time is the sum of cURL's per-hop transfer time, never wall-clock time: promises for a
     * whole batch are created before any transfer starts, so wall-clock would include the time spent
     * resolving/queueing the other websites in the batch.
     *
     * @param array{origin: string, started: string, chain: array<int, array{url: string, status: int}>, visited: array<int, string>, ip: ?string, transfer: float} $state
     * @return PromiseInterface<ProbeResult>
     */
    private function hop(string $url, int $hopIndex, array $state): PromiseInterface
    {
        $elapsedMs = fn (): int => (int) round($state['transfer'] * 1000);

        $resolution = $this->guard->resolve($url);
        if (!$resolution['ok']) {
            $kind = $resolution['error_kind'] ?? ProbeResult::ERROR_DNS;
            if ($hopIndex > 0 && $kind === ProbeResult::ERROR_BLOCKED) {
                $kind = ProbeResult::ERROR_REDIRECT_BLOCKED;
                $message = 'Redirect to a private/internal address was blocked: ' . $url;
            } else {
                $message = (string) ($resolution['error'] ?? 'Could not resolve host.');
            }
            return Create::promiseFor(ProbeResult::error($state['origin'], $kind, $message, $elapsedMs(), $state['chain'], null, $state['started']));
        }

        $state['visited'][] = $url;
        $host = $resolution['host'];
        $port = $resolution['port'];
        $resolveEntry = $host . ':' . $port . ':' . implode(',', $resolution['ips']);

        $remaining = max(1, $this->options['timeout'] - (int) floor($state['transfer']));
        $remoteIp = null;
        $hopTime = null;
        $hopStart = microtime(true);
        $requestOptions = [
            'connect_timeout' => min($this->options['connect_timeout'], $remaining),
            'timeout'         => $remaining,
            'verify'          => $this->options['verify'],
            'headers'         => [
                'User-Agent'      => $this->options['user_agent'],
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Cache-Control'   => 'no-cache',
                'Pragma'          => 'no-cache',
            ],
            'curl' => [
                CURLOPT_RESOLVE     => [$resolveEntry],
                CURLOPT_MAXFILESIZE => 8 * 1024 * 1024,
            ],
            'on_stats' => function (TransferStats $stats) use (&$remoteIp, &$hopTime): void {
                $handler = $stats->getHandlerStats();
                if (!empty($handler['primary_ip'])) {
                    $remoteIp = (string) $handler['primary_ip'];
                }
                $hopTime = $stats->getTransferTime();
            },
        ];

        return $this->client->getAsync($url, $requestOptions)->then(
            function (ResponseInterface $response) use ($url, $hopIndex, $state, &$remoteIp, &$hopTime, $hopStart): PromiseInterface|ProbeResult {
                $state['transfer'] += $hopTime ?? (microtime(true) - $hopStart);
                $elapsedMs = fn (): int => (int) round($state['transfer'] * 1000);
                $status = $response->getStatusCode();
                $state['chain'][] = ['url' => $url, 'status' => $status];
                $state['ip'] = $remoteIp ?? $state['ip'];

                if (in_array($status, self::REDIRECT_CODES, true) && $response->hasHeader('Location')) {
                    $location = trim($response->getHeaderLine('Location'));
                    $next = $this->resolveLocation($url, $location);
                    if ($next === null) {
                        return ProbeResult::error($state['origin'], ProbeResult::ERROR_TOO_MANY_REDIRECTS, 'Invalid redirect Location header: ' . $location, $elapsedMs(), $state['chain'], null, $state['started']);
                    }
                    if ($hopIndex + 1 > $this->options['max_redirects']) {
                        return ProbeResult::error($state['origin'], ProbeResult::ERROR_TOO_MANY_REDIRECTS, 'Too many redirects (more than ' . $this->options['max_redirects'] . ').', $elapsedMs(), $state['chain'], null, $state['started']);
                    }
                    if (in_array($next, $state['visited'], true)) {
                        return ProbeResult::error($state['origin'], ProbeResult::ERROR_REDIRECT_LOOP, 'Redirect loop detected: ' . $next . ' redirects back to itself.', $elapsedMs(), $state['chain'], null, $state['started']);
                    }
                    return $this->hop($next, $hopIndex + 1, $state);
                }

                $body = '';
                try {
                    $stream = $response->getBody();
                    $body = $stream->isReadable() ? (string) $stream->read($this->options['body_limit']) : '';
                } catch (Throwable) {
                    $body = '';
                }
                $headers = [];
                foreach ($response->getHeaders() as $name => $values) {
                    $headers[strtolower((string) $name)] = implode(', ', $values);
                }

                return new ProbeResult(
                    url: $state['origin'],
                    httpStatus: $status,
                    responseTimeMs: $elapsedMs(),
                    body: $body,
                    headers: $headers,
                    finalUrl: $url,
                    redirectCount: max(0, count($state['chain']) - 1),
                    redirectChain: $state['chain'],
                    remoteIp: $state['ip'],
                    startedAt: $state['started']
                );
            },
            function (Throwable $e) use ($state, &$hopTime, $hopStart): ProbeResult {
                $state['transfer'] += $hopTime ?? (microtime(true) - $hopStart);
                [$kind, $message, $errno] = $this->classifyException($e);
                return ProbeResult::error($state['origin'], $kind, $message, (int) round($state['transfer'] * 1000), $state['chain'], $errno, $state['started']);
            }
        );
    }

    private function resolveLocation(string $current, string $location): ?string
    {
        if ($location === '') {
            return null;
        }
        try {
            $resolved = (string) UriResolver::resolve(new Uri($current), new Uri($location));
        } catch (Throwable) {
            return null;
        }
        $scheme = strtolower((string) parse_url($resolved, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || parse_url($resolved, PHP_URL_HOST) === null) {
            return null;
        }
        return $resolved;
    }

    /**
     * Map Guzzle/cURL exceptions to an error kind and a friendly message.
     *
     * @return array{0: string, 1: string, 2: ?int}
     */
    private function classifyException(Throwable $e): array
    {
        $errno = null;
        $curlError = '';
        if ($e instanceof ConnectException || $e instanceof RequestException) {
            $context = $e->getHandlerContext();
            $errno = isset($context['errno']) ? (int) $context['errno'] : null;
            $curlError = (string) ($context['error'] ?? '');
        }
        $raw = $curlError !== '' ? $curlError : $e->getMessage();
        $raw = preg_replace('/\s+\(see https?:\/\/\S+\)/', '', $raw) ?? $raw;
        $raw = preg_replace('/ for "[^"]*"$/', '', $raw) ?? $raw;
        $lower = strtolower($raw);

        // cURL error numbers: https://curl.se/libcurl/c/libcurl-errors.html
        $sslErrnos = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91, 98];
        if ($errno === 28 || str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
            return [ProbeResult::ERROR_TIMEOUT, 'Request timed out after ' . $this->options['timeout'] . ' seconds (' . trim($raw) . ').', $errno];
        }
        if ($errno === 6 || str_contains($lower, 'could not resolve') || str_contains($lower, 'name or service not known') || str_contains($lower, 'getaddrinfo')) {
            return [ProbeResult::ERROR_DNS, 'DNS resolution failed: ' . trim($raw), $errno];
        }
        if (($errno !== null && in_array($errno, $sslErrnos, true)) || str_contains($lower, 'ssl') || str_contains($lower, 'certificate') || str_contains($lower, 'tls')) {
            return [ProbeResult::ERROR_SSL, 'SSL/TLS error: ' . $this->friendlySslMessage($raw), $errno];
        }
        if ($errno === 7 || str_contains($lower, 'connection refused') || str_contains($lower, 'failed to connect') || str_contains($lower, 'unreachable') || str_contains($lower, 'no route to host')) {
            return [ProbeResult::ERROR_CONNECT, 'Connection failed: ' . trim($raw), $errno];
        }
        if ($errno === 52 || str_contains($lower, 'empty reply')) {
            return [ProbeResult::ERROR_CONNECT, 'Empty reply from server (the web server accepted the connection but sent no response).', $errno];
        }
        if ($errno === 55 || $errno === 56 || str_contains($lower, 'connection reset') || str_contains($lower, 'recv failure') || str_contains($lower, 'send failure')) {
            return [ProbeResult::ERROR_CONNECT, 'Connection interrupted: ' . trim($raw), $errno];
        }
        if ($errno === 47) {
            return [ProbeResult::ERROR_TOO_MANY_REDIRECTS, 'Too many redirects.', $errno];
        }
        return [ProbeResult::ERROR_UNKNOWN, 'Request failed: ' . trim($raw), $errno];
    }

    private function friendlySslMessage(string $raw): string
    {
        $lower = strtolower($raw);
        if (str_contains($lower, 'expired')) {
            return 'the certificate has expired.';
        }
        if (str_contains($lower, 'self signed') || str_contains($lower, 'self-signed')) {
            return 'the certificate is self-signed and not trusted.';
        }
        if (str_contains($lower, 'unable to get local issuer') || str_contains($lower, 'verify failed')) {
            return 'certificate verification failed (untrusted issuer or incomplete chain).';
        }
        if (str_contains($lower, 'does not match') || str_contains($lower, 'no alternative certificate subject name')) {
            return 'the certificate does not match the hostname.';
        }
        if (str_contains($lower, 'handshake')) {
            return 'TLS handshake failed.';
        }
        return trim($raw);
    }

    /** @return array{connect_timeout: int, timeout: int, max_redirects: int, user_agent: string, verify: bool|string, body_limit: int} */
    public function options(): array
    {
        return $this->options;
    }
}
