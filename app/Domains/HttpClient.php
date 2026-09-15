<?php

declare(strict_types=1);

namespace App\Domains;

/**
 * Small cURL client for the registry (RDAP) and IP-location APIs, plus a header-only probe of a website.
 *
 * API requests only go to fixed, trusted services (IANA's RDAP bootstrap servers, ipinfo.io), never to
 * administrator-supplied URLs. The website probe is the exception: HostingInspector validates the address with
 * SsrfGuard first and this client pins the connection to that address (CURLOPT_RESOLVE).
 */
final class HttpClient
{
    public function __construct(
        private readonly string $userAgent,
        private readonly ?string $caFile = null,
        private readonly int $timeout = 12
    ) {
    }

    /**
     * @param array<int, string> $headers
     * @return array{status: int, body: string, error: ?string}
     */
    public function get(string $url, array $headers = [], int $maxBytes = 2000000): array
    {
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 3,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT  => min(8, $this->timeout),
            CURLOPT_TIMEOUT         => $this->timeout,
            CURLOPT_USERAGENT       => $this->userAgent,
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_ENCODING        => '',
            CURLOPT_WRITEFUNCTION   => static function ($handle, string $chunk) use (&$body, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    return 0; // abort oversized responses
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($this->caFile !== null) {
            curl_setopt($ch, CURLOPT_CAINFO, $this->caFile);
        }
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $ok === false ? (curl_error($ch) ?: 'Request failed.') : null;
        curl_close($ch);
        return ['status' => $status, 'body' => $body, 'error' => $error];
    }

    /**
     * @param array<int, string> $headers
     * @return array{status: int, data: ?array<string, mixed>, error: ?string}
     */
    public function getJson(string $url, array $headers = []): array
    {
        $response = $this->get($url, array_merge(['Accept: application/rdap+json, application/json'], $headers));
        if ($response['error'] !== null) {
            return ['status' => $response['status'], 'data' => null, 'error' => $response['error']];
        }
        $data = json_decode($response['body'], true);
        return [
            'status' => $response['status'],
            'data'   => is_array($data) ? $data : null,
            'error'  => is_array($data) ? null : 'The service returned an invalid response (HTTP ' . $response['status'] . ').',
        ];
    }

    /**
     * HEAD request to a website, connecting to a pre-validated IP address. Redirects are not followed.
     *
     * @return array{status: int, headers: array<string, string>, error: ?string}
     */
    public function probeHeaders(string $scheme, string $host, string $ip, bool $verify = true): array
    {
        $port = $scheme === 'https' ? 443 : 80;
        $headers = [];
        $ch = curl_init($scheme . '://' . $host . '/');
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE        => [$host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2 && count($headers) < 100) {
                    $headers[strtolower(trim($parts[0]))] = mb_substr(trim($parts[1]), 0, 300);
                }
                return strlen($line);
            },
        ]);
        if ($verify && $this->caFile !== null) {
            curl_setopt($ch, CURLOPT_CAINFO, $this->caFile);
        }
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $ok === false ? (curl_error($ch) ?: 'Request failed.') : null;
        curl_close($ch);
        return ['status' => $status, 'headers' => $headers, 'error' => $error];
    }
}
