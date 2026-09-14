<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Monitoring\ErrorDetector;
use App\Monitoring\ProbeResult;
use App\Monitoring\Status;
use App\Monitoring\StatusClassifier;
use PHPUnit\Framework\TestCase;

final class StatusClassifierTest extends TestCase
{
    private StatusClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new StatusClassifier(new ErrorDetector(), ['moderate' => 2000, 'slow' => 5000, 'critical' => 10000]);
    }

    /** @return array<string, mixed> */
    private function website(array $overrides = []): array
    {
        return array_merge(['id' => 1, 'url' => 'https://example.com/', 'checks_json' => null, 'ssl_valid' => 1, 'ssl_expires_at' => gmdate('Y-m-d H:i:s', time() + 90 * 86400)], $overrides);
    }

    private function response(int $http, string $body = '<html><body>ok</body></html>', int $ms = 300, string $finalUrl = 'https://example.com/'): ProbeResult
    {
        return new ProbeResult(url: 'https://example.com/', httpStatus: $http, responseTimeMs: $ms, body: $body, finalUrl: $finalUrl, redirectChain: [['url' => 'https://example.com/', 'status' => $http]]);
    }

    public function testHealthyResponseIsOnline(): void
    {
        $result = $this->classifier->classify($this->response(200), $this->website());
        self::assertSame(Status::ONLINE, $result->status);
        self::assertFalse($result->isFailure);
        self::assertSame(200, $result->httpStatus);
    }

    public function testCriticalErrorBeatsHttp500(): void
    {
        $body = '<body id="error-page"><p>There has been a critical error on this website.</p></body>';
        $result = $this->classifier->classify($this->response(500, $body), $this->website());
        self::assertSame(Status::CRITICAL_ERROR, $result->status);
        self::assertTrue($result->isFailure);
        self::assertSame(500, $result->httpStatus, 'HTTP 500 is kept as supporting diagnostics');
        self::assertStringContainsString('HTTP 500', (string) $result->errorMessage);
    }

    public function testCriticalErrorWithHttp200(): void
    {
        $body = '<body id="error-page"><p>There has been a critical error on this website.</p></body>';
        $result = $this->classifier->classify($this->response(200, $body), $this->website());
        self::assertSame(Status::CRITICAL_ERROR, $result->status);
        self::assertSame(200, $result->httpStatus);
    }

    public function testHttpStatusMapping(): void
    {
        self::assertSame(Status::HTTP_500, $this->classifier->classify($this->response(500), $this->website())->status);
        self::assertSame(Status::HTTP_502, $this->classifier->classify($this->response(502), $this->website())->status);
        self::assertSame(Status::HTTP_503, $this->classifier->classify($this->response(503), $this->website())->status);
        self::assertSame(Status::HTTP_504, $this->classifier->classify($this->response(504), $this->website())->status);
        self::assertSame(Status::HTTP_ERROR, $this->classifier->classify($this->response(520), $this->website())->status);
        self::assertSame(Status::HTTP_ERROR, $this->classifier->classify($this->response(404), $this->website())->status);
    }

    public function testForbiddenIsWarningNotFailure(): void
    {
        $result = $this->classifier->classify($this->response(403), $this->website());
        self::assertSame(Status::WARNING, $result->status);
        self::assertFalse($result->isFailure);
        self::assertSame('access_blocked', $result->errorType);
    }

    public function testPerformanceThresholds(): void
    {
        self::assertSame(Status::ONLINE, $this->classifier->classify($this->response(200, ms: 4999), $this->website())->status);
        $slow = $this->classifier->classify($this->response(200, ms: 5000), $this->website());
        self::assertSame(Status::SLOW, $slow->status);
        self::assertFalse($slow->isFailure);
        $critical = $this->classifier->classify($this->response(200, ms: 10001), $this->website());
        self::assertSame(Status::CRITICAL_PERFORMANCE, $critical->status);
        self::assertTrue($critical->isFailure);
    }

    public function testTimeoutTakesPriorityOverSlow(): void
    {
        $probe = ProbeResult::error('https://example.com/', ProbeResult::ERROR_TIMEOUT, 'Request timed out', 30000);
        $result = $this->classifier->classify($probe, $this->website());
        self::assertSame(Status::TIMEOUT, $result->status);
        self::assertTrue($result->isFailure);
        self::assertNull($result->httpStatus);
    }

    public function testTransportErrors(): void
    {
        $map = [
            ProbeResult::ERROR_DNS                => Status::DNS_ERROR,
            ProbeResult::ERROR_CONNECT            => Status::DOWN,
            ProbeResult::ERROR_SSL                => Status::SSL_ERROR,
            ProbeResult::ERROR_REDIRECT_LOOP      => Status::REDIRECT_ERROR,
            ProbeResult::ERROR_TOO_MANY_REDIRECTS => Status::REDIRECT_ERROR,
            ProbeResult::ERROR_REDIRECT_BLOCKED   => Status::REDIRECT_ERROR,
            ProbeResult::ERROR_UNKNOWN            => Status::DOWN,
        ];
        foreach ($map as $kind => $expected) {
            $result = $this->classifier->classify(ProbeResult::error('https://example.com/', $kind, 'x', 10), $this->website());
            self::assertSame($expected, $result->status, $kind);
            self::assertTrue($result->isFailure, $kind);
        }
        $blocked = $this->classifier->classify(ProbeResult::error('https://example.com/', ProbeResult::ERROR_BLOCKED, 'blocked', 0), $this->website());
        self::assertSame(Status::WARNING, $blocked->status);
        self::assertFalse($blocked->isFailure);
    }

    public function testSslWarningOverlayWhenCertificateExpiresSoon(): void
    {
        $website = $this->website(['ssl_expires_at' => gmdate('Y-m-d H:i:s', time() + 5 * 86400)]);
        $result = $this->classifier->classify($this->response(200), $website);
        self::assertSame(Status::SSL_WARNING, $result->status);
        self::assertFalse($result->isFailure);
        self::assertSame(5, $result->sslDaysRemaining);
    }

    public function testExpiredCertificateFromRecordIsFailure(): void
    {
        $website = $this->website(['ssl_expires_at' => gmdate('Y-m-d H:i:s', time() - 2 * 86400), 'ssl_valid' => 0]);
        $result = $this->classifier->classify($this->response(200), $website);
        self::assertSame(Status::SSL_ERROR, $result->status);
        self::assertTrue($result->isFailure);
    }

    public function testSslCheckCanBeDisabledPerWebsite(): void
    {
        $website = $this->website(['ssl_expires_at' => gmdate('Y-m-d H:i:s', time() + 5 * 86400), 'checks_json' => json_encode(['ssl' => false])]);
        $result = $this->classifier->classify($this->response(200), $website);
        self::assertSame(Status::ONLINE, $result->status);
    }

    public function testInsecureRedirectDowngradeIsWarning(): void
    {
        $result = $this->classifier->classify($this->response(200, finalUrl: 'http://example.com/'), $this->website());
        self::assertSame(Status::WARNING, $result->status);
        self::assertSame('insecure_redirect', $result->errorType);
    }

    public function testDisabledHttpCheckIgnoresStatusCodes(): void
    {
        $website = $this->website(['checks_json' => json_encode(['http' => false])]);
        $result = $this->classifier->classify($this->response(500), $website);
        self::assertSame(Status::ONLINE, $result->status);
    }

    public function testDisabledWordPressDetectionStillReportsHttp500(): void
    {
        $body = '<p>There has been a critical error on this website.</p>';
        $website = $this->website(['checks_json' => json_encode(['wp_errors' => false])]);
        $result = $this->classifier->classify($this->response(500, $body), $website);
        self::assertSame(Status::HTTP_500, $result->status);
    }

    public function testDiagnosticsIncludeRedirectChain(): void
    {
        $result = $this->classifier->classify($this->response(200), $this->website());
        self::assertArrayHasKey('redirect_chain', $result->diagnostics);
        self::assertSame('https://example.com/', $result->finalUrl);
    }
}
