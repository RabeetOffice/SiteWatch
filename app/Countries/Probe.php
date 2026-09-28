<?php

declare(strict_types=1);

namespace App\Countries;

/**
 * What one probe in one country saw when it opened a website.
 */
final class Probe
{
    /**
     * @param array<string, string> $headers Response headers, lower-case names.
     */
    public function __construct(
        public readonly string $country,
        public readonly string $provider,
        public readonly bool $answered,
        public readonly ?int $statusCode = null,
        public readonly ?int $responseMs = null,
        public readonly ?string $resolvedIp = null,
        public readonly ?string $error = null,
        public readonly ?string $network = null,
        public readonly ?int $asn = null,
        public readonly ?string $city = null,
        public readonly ?string $type = null,
        public readonly array $headers = [],
        public readonly string $body = ''
    ) {
    }

    /** The site answered with a page (2xx or 3xx). */
    public function isOk(): bool
    {
        return $this->answered && $this->statusCode !== null && $this->statusCode >= 200 && $this->statusCode < 400;
    }

    /**
     * Same probe without the body, which is only needed while classifying.
     */
    public function withoutBody(): self
    {
        return new self($this->country, $this->provider, $this->answered, $this->statusCode, $this->responseMs, $this->resolvedIp,
            $this->error, $this->network, $this->asn, $this->city, $this->type, $this->headers, '');
    }
}
