<?php

declare(strict_types=1);

namespace App\Monitoring;

/**
 * Turns a raw ProbeResult into a CheckResult with a health status, using the error detector,
 * HTTP status context, performance thresholds and the website's SSL state.
 *
 * Priority (most severe wins, but the most useful root cause is preferred):
 *   connection/DNS > timeout > SSL error > redirect error > WordPress critical / DB error (from body)
 *   > HTTP 5xx > HTTP 4xx > maintenance > critical performance > slow > SSL warning > online
 */
final class StatusClassifier
{
    /** @var array{moderate:int, slow:int, critical:int} */
    private array $thresholds;

    /**
     * @param array{moderate?:int, slow?:int, critical?:int} $thresholds milliseconds
     */
    public function __construct(private readonly ErrorDetector $detector, array $thresholds = [])
    {
        $this->thresholds = [
            'moderate' => max(100, (int) ($thresholds['moderate'] ?? 2000)),
            'slow'     => max(200, (int) ($thresholds['slow'] ?? 5000)),
            'critical' => max(500, (int) ($thresholds['critical'] ?? 10000)),
        ];
        if ($this->thresholds['critical'] <= $this->thresholds['slow']) {
            $this->thresholds['critical'] = $this->thresholds['slow'] * 2;
        }
    }

    /**
     * @param array<string, mixed> $website The website row (checks_json, url, ssl_* columns)
     */
    public function classify(ProbeResult $probe, array $website, string $checkedAtUtc = ''): CheckResult
    {
        $checks = self::enabledChecks($website);
        $checkedAt = $checkedAtUtc !== '' ? $checkedAtUtc : gmdate('Y-m-d H:i:s');
        $sslDays = self::sslDaysRemaining($website);

        $diagnostics = [
            'final_url'      => $probe->finalUrl,
            'redirect_chain' => $probe->redirectChain,
            'remote_ip'      => $probe->remoteIp,
        ];
        if ($probe->curlErrno !== null) {
            $diagnostics['curl_errno'] = $probe->curlErrno;
        }

        $base = [
            'httpStatus'       => $probe->httpStatus,
            'responseTime'     => $probe->responseTimeMs,
            'redirectCount'    => $probe->redirectCount,
            'finalUrl'         => $probe->finalUrl,
            'sslDaysRemaining' => $sslDays,
            'checkedAt'        => $checkedAt,
        ];

        // 1. Transport level failures ------------------------------------------------
        if ($probe->isError()) {
            return $this->fromTransportError($probe, $base, $diagnostics);
        }

        $http = (int) $probe->httpStatus;
        $body = $probe->body;

        // 2. Recognised failure pages in the body (even when HTTP 200) --------------
        $categories = [];
        if ($checks['wp_errors']) {
            $categories[] = ErrorDetector::CATEGORY_WP_CRITICAL;
        }
        if ($checks['database']) {
            $categories[] = ErrorDetector::CATEGORY_DATABASE;
        }
        if ($checks['maintenance']) {
            $categories[] = ErrorDetector::CATEGORY_MAINTENANCE;
        }
        if ($checks['fatal_errors']) {
            $categories[] = ErrorDetector::CATEGORY_FATAL;
        }
        if ($checks['http']) {
            $categories[] = ErrorDetector::CATEGORY_SERVER;
        }
        $detection = $categories !== [] ? $this->detector->detect($body, $http, $categories) : null;
        if ($detection !== null) {
            $diagnostics['detected'] = [
                'type'     => $detection['type'],
                'category' => $detection['category'],
                'excerpt'  => $detection['excerpt'],
            ];
            $diagnostics['http_status'] = $http;
            $message = $detection['message'] . ' (HTTP ' . $http . ')';
            return new CheckResult(
                ...$base,
                status: $detection['status'],
                isFailure: true,
                errorType: $detection['type'],
                errorMessage: $message,
                diagnostics: $diagnostics
            );
        }

        // 3. HTTP status ----------------------------------------------------------------
        if ($checks['http']) {
            if ($http >= 500) {
                $status = match ($http) {
                    500 => Status::HTTP_500,
                    502 => Status::HTTP_502,
                    503 => Status::HTTP_503,
                    504 => Status::HTTP_504,
                    default => Status::HTTP_ERROR,
                };
                return new CheckResult(
                    ...$base,
                    status: $status,
                    isFailure: true,
                    errorType: 'http_' . $http,
                    errorMessage: 'Server returned HTTP ' . $http . ' ' . self::reason($http),
                    diagnostics: $diagnostics
                );
            }
            if ($http === 401 || $http === 403 || $http === 429) {
                return new CheckResult(
                    ...$base,
                    status: Status::WARNING,
                    isFailure: false,
                    errorType: 'access_blocked',
                    errorMessage: 'HTTP ' . $http . ' ' . self::reason($http) . ' — the site responded but blocked the monitor (firewall, bot protection or authentication).',
                    diagnostics: $diagnostics
                );
            }
            if ($http >= 400) {
                return new CheckResult(
                    ...$base,
                    status: Status::HTTP_ERROR,
                    isFailure: true,
                    errorType: 'http_' . $http,
                    errorMessage: 'Homepage returned HTTP ' . $http . ' ' . self::reason($http),
                    diagnostics: $diagnostics
                );
            }
            if ($http >= 300) {
                return new CheckResult(
                    ...$base,
                    status: Status::REDIRECT_ERROR,
                    isFailure: true,
                    errorType: 'redirect_without_location',
                    errorMessage: 'Server returned HTTP ' . $http . ' without a usable Location header.',
                    diagnostics: $diagnostics
                );
            }
        }

        // 4. Redirect sanity (https -> http downgrade) ----------------------------------
        if ($checks['redirects'] && $probe->finalUrl !== null
            && str_starts_with(strtolower($probe->url), 'https://') && str_starts_with(strtolower($probe->finalUrl), 'http://')) {
            return new CheckResult(
                ...$base,
                status: Status::WARNING,
                isFailure: false,
                errorType: 'insecure_redirect',
                errorMessage: 'The HTTPS URL redirects to an insecure HTTP address: ' . $probe->finalUrl,
                diagnostics: $diagnostics
            );
        }

        // 5. Performance ----------------------------------------------------------------
        if ($checks['response_time']) {
            $ms = $probe->responseTimeMs;
            if ($ms >= $this->thresholds['critical']) {
                return new CheckResult(
                    ...$base,
                    status: Status::CRITICAL_PERFORMANCE,
                    isFailure: true,
                    errorType: 'critical_performance',
                    errorMessage: 'Critically slow response: ' . format_ms($ms) . ' (threshold ' . format_ms($this->thresholds['critical']) . ')',
                    diagnostics: $diagnostics
                );
            }
            if ($ms >= $this->thresholds['slow']) {
                return new CheckResult(
                    ...$base,
                    status: Status::SLOW,
                    isFailure: false,
                    errorType: 'slow_response',
                    errorMessage: 'Slow response: ' . format_ms($ms) . ' (threshold ' . format_ms($this->thresholds['slow']) . ')',
                    diagnostics: $diagnostics
                );
            }
        }

        // 6. SSL certificate expiring soon (from the last certificate inspection) --------
        if ($checks['ssl'] && $sslDays !== null && $sslDays <= 30 && str_starts_with(strtolower($website['url'] ?? ''), 'https://')) {
            $label = $sslDays < 0 ? 'expired' : ($sslDays === 0 ? 'expires today' : "expires in {$sslDays} day" . ($sslDays === 1 ? '' : 's'));
            return new CheckResult(
                ...$base,
                status: $sslDays < 0 ? Status::SSL_ERROR : Status::SSL_WARNING,
                isFailure: $sslDays < 0,
                errorType: $sslDays < 0 ? 'ssl_expired' : 'ssl_expiring',
                errorMessage: 'SSL certificate ' . $label . '.',
                diagnostics: $diagnostics
            );
        }
        if ($checks['ssl'] && isset($website['ssl_valid']) && (int) $website['ssl_valid'] === 0 && !empty($website['ssl_error'])
            && str_starts_with(strtolower($website['url'] ?? ''), 'https://')) {
            return new CheckResult(
                ...$base,
                status: Status::SSL_WARNING,
                isFailure: false,
                errorType: 'ssl_invalid',
                errorMessage: 'SSL certificate problem: ' . $website['ssl_error'],
                diagnostics: $diagnostics
            );
        }

        return new CheckResult(...$base, status: Status::ONLINE, isFailure: false, diagnostics: $diagnostics);
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $diagnostics
     */
    private function fromTransportError(ProbeResult $probe, array $base, array $diagnostics): CheckResult
    {
        $message = (string) $probe->errorMessage;
        [$status, $type, $failure] = match ($probe->errorKind) {
            ProbeResult::ERROR_DNS                => [Status::DNS_ERROR, 'dns_failure', true],
            ProbeResult::ERROR_TIMEOUT            => [Status::TIMEOUT, 'timeout', true],
            ProbeResult::ERROR_SSL                => [Status::SSL_ERROR, 'ssl_error', true],
            ProbeResult::ERROR_CONNECT            => [Status::DOWN, 'connection_failed', true],
            ProbeResult::ERROR_REDIRECT_LOOP      => [Status::REDIRECT_ERROR, 'redirect_loop', true],
            ProbeResult::ERROR_TOO_MANY_REDIRECTS => [Status::REDIRECT_ERROR, 'too_many_redirects', true],
            ProbeResult::ERROR_REDIRECT_BLOCKED   => [Status::REDIRECT_ERROR, 'redirect_blocked', true],
            ProbeResult::ERROR_BLOCKED            => [Status::WARNING, 'blocked_target', false],
            ProbeResult::ERROR_INVALID_URL        => [Status::WARNING, 'invalid_url', false],
            default                               => [Status::DOWN, 'request_failed', true],
        };
        // A timed-out request must never be reported as merely "slow".
        $base['responseTime'] = $probe->errorKind === ProbeResult::ERROR_TIMEOUT ? $probe->responseTimeMs : $base['responseTime'];

        return new CheckResult(
            ...$base,
            status: $status,
            isFailure: $failure,
            errorType: $type,
            errorMessage: $message,
            diagnostics: $diagnostics
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Enabled check flags with defaults.
     *
     * @param array<string, mixed> $website
     * @return array{http:bool, wp_errors:bool, response_time:bool, ssl:bool, redirects:bool, maintenance:bool, database:bool, fatal_errors:bool}
     */
    public static function enabledChecks(array $website): array
    {
        $defaults = ['http' => true, 'wp_errors' => true, 'response_time' => true, 'ssl' => true, 'redirects' => true, 'maintenance' => true, 'database' => true, 'fatal_errors' => true];
        $raw = $website['checks_json'] ?? null;
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
        if (!is_array($decoded)) {
            return $defaults;
        }
        foreach ($defaults as $key => $default) {
            if (array_key_exists($key, $decoded)) {
                $defaults[$key] = (bool) $decoded[$key];
            }
        }
        return $defaults;
    }

    /** @param array<string, mixed> $website */
    public static function sslDaysRemaining(array $website): ?int
    {
        if (empty($website['ssl_expires_at'])) {
            return null;
        }
        $expires = strtotime($website['ssl_expires_at'] . ' UTC');
        if ($expires === false) {
            return null;
        }
        return (int) floor(($expires - time()) / 86400);
    }

    public static function reason(int $code): string
    {
        return match ($code) {
            400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed',
            408 => 'Request Timeout', 410 => 'Gone', 429 => 'Too Many Requests', 500 => 'Internal Server Error',
            501 => 'Not Implemented', 502 => 'Bad Gateway', 503 => 'Service Unavailable', 504 => 'Gateway Timeout',
            505 => 'HTTP Version Not Supported', 508 => 'Loop Detected', 520 => 'Web Server Returned an Unknown Error',
            521 => 'Web Server Is Down', 522 => 'Connection Timed Out', 523 => 'Origin Is Unreachable', 524 => 'A Timeout Occurred',
            525 => 'SSL Handshake Failed', 526 => 'Invalid SSL Certificate', default => '',
        };
    }

    /** @return array{moderate:int, slow:int, critical:int} */
    public function thresholds(): array
    {
        return $this->thresholds;
    }
}
