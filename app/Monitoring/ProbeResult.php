<?php

declare(strict_types=1);

namespace App\Monitoring;

/**
 * Raw outcome of an HTTP probe, before any health classification.
 */
final class ProbeResult
{
    public const ERROR_DNS = 'dns';
    public const ERROR_CONNECT = 'connect';
    public const ERROR_TIMEOUT = 'timeout';
    public const ERROR_SSL = 'ssl';
    public const ERROR_REDIRECT_LOOP = 'redirect_loop';
    public const ERROR_TOO_MANY_REDIRECTS = 'too_many_redirects';
    public const ERROR_REDIRECT_BLOCKED = 'redirect_blocked';
    public const ERROR_BLOCKED = 'blocked_target';
    public const ERROR_INVALID_URL = 'invalid_url';
    public const ERROR_UNKNOWN = 'unknown';

    /**
     * @param array<string, string> $headers         Lower-cased header name => value (final response)
     * @param array<int, array{url: string, status: int}> $redirectChain
     */
    public function __construct(
        public readonly string $url,
        public readonly ?int $httpStatus,
        public readonly int $responseTimeMs,
        public readonly string $body = '',
        public readonly array $headers = [],
        public readonly ?string $finalUrl = null,
        public readonly int $redirectCount = 0,
        public readonly array $redirectChain = [],
        public readonly ?string $errorKind = null,
        public readonly ?string $errorMessage = null,
        public readonly ?int $curlErrno = null,
        public readonly ?string $remoteIp = null,
        public readonly string $startedAt = ''
    ) {
    }

    public static function error(string $url, string $kind, string $message, int $responseTimeMs, array $chain = [], ?int $curlErrno = null, string $startedAt = ''): self
    {
        return new self(
            url: $url,
            httpStatus: null,
            responseTimeMs: $responseTimeMs,
            finalUrl: $chain !== [] ? (string) end($chain)['url'] : $url,
            redirectCount: max(0, count($chain) - 1),
            redirectChain: $chain,
            errorKind: $kind,
            errorMessage: $message,
            curlErrno: $curlErrno,
            startedAt: $startedAt ?: gmdate('Y-m-d H:i:s')
        );
    }

    public function isError(): bool
    {
        return $this->errorKind !== null;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
