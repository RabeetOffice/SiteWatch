<?php

declare(strict_types=1);

namespace App\Countries;

/**
 * Minimal JSON-over-HTTPS client for the probe providers (fixed, trusted hosts only).
 */
class JsonHttp
{
    public function __construct(
        private readonly string $userAgent,
        private readonly ?string $caFile = null,
        private readonly int $timeout = 15
    ) {
    }

    /**
     * @param array<string, mixed>|null $body    JSON body (POST).
     * @param array<int, string>        $headers Extra request headers.
     * @return array{status: int, data: mixed, headers: array<string, string>, error: ?string}
     */
    public function request(string $method, string $url, ?array $body = null, array $headers = []): array
    {
        $responseHeaders = [];
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_CUSTOMREQUEST   => $method,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_CONNECTTIMEOUT  => 8,
            CURLOPT_TIMEOUT         => $this->timeout,
            CURLOPT_USERAGENT       => $this->userAgent,
            CURLOPT_ENCODING        => '',
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_HTTPHEADER      => array_merge(['Accept: application/json'], $body !== null ? ['Content-Type: application/json'] : [], $headers),
            CURLOPT_HEADERFUNCTION  => static function ($handle, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch, $options);
        if ($this->caFile !== null) {
            curl_setopt($ch, CURLOPT_CAINFO, $this->caFile);
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $raw === false ? (curl_error($ch) ?: 'Request failed.') : null;
        curl_close($ch);

        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        return ['status' => $status, 'data' => $data, 'headers' => $responseHeaders, 'error' => $error];
    }
}
