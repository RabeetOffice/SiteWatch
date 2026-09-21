<?php

declare(strict_types=1);

namespace App\Monitoring;

/**
 * A classified health check result for one website. This is what gets persisted and displayed.
 */
final class CheckResult
{
    /**
     * @param array<string, mixed> $diagnostics Supporting information (redirect chain, matched signature, curl errno, ...)
     */
    public function __construct(
        public readonly string $status,
        public readonly bool $isFailure,
        public readonly ?int $httpStatus = null,
        public readonly ?int $responseTime = null,
        public readonly ?int $ttfb = null,
        public readonly ?string $errorType = null,
        public readonly ?string $errorMessage = null,
        public readonly int $redirectCount = 0,
        public readonly ?string $finalUrl = null,
        public readonly ?int $sslDaysRemaining = null,
        public readonly array $diagnostics = [],
        public readonly string $checkedAt = '',
        public readonly string $source = 'cron'
    ) {
    }

    public function withSource(string $source): self
    {
        return new self(
            $this->status,
            $this->isFailure,
            $this->httpStatus,
            $this->responseTime,
            $this->ttfb,
            $this->errorType,
            $this->errorMessage,
            $this->redirectCount,
            $this->finalUrl,
            $this->sslDaysRemaining,
            $this->diagnostics,
            $this->checkedAt,
            $source
        );
    }

    public function label(): string
    {
        return Status::label($this->status);
    }

    public function severity(): string
    {
        return Status::severity($this->status);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status'             => $this->status,
            'status_label'       => $this->label(),
            'severity'           => $this->severity(),
            'is_failure'         => $this->isFailure,
            'http_status'        => $this->httpStatus,
            'response_time'      => $this->responseTime,
            'ttfb'               => $this->ttfb,
            'error_type'         => $this->errorType,
            'error_message'      => $this->errorMessage,
            'redirect_count'     => $this->redirectCount,
            'final_url'          => $this->finalUrl,
            'ssl_days_remaining' => $this->sslDaysRemaining,
            'diagnostics'        => $this->diagnostics,
            'checked_at'         => $this->checkedAt,
            'source'             => $this->source,
        ];
    }
}
