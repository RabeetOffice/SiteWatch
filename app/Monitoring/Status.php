<?php

declare(strict_types=1);

namespace App\Monitoring;

/**
 * The status vocabulary shared by the classifier, incident manager, API and UI.
 *
 * Severity levels:
 *   down    - confirmed failure; counts towards the failure threshold and creates incidents
 *   warning - degraded but reachable; shown as a warning, never creates a downtime incident
 *   ok      - healthy
 *   neutral - not monitored / no data yet
 */
final class Status
{
    public const ONLINE = 'ONLINE';
    public const DOWN = 'DOWN';
    public const SUSPECTED_DOWN = 'SUSPECTED_DOWN';
    public const CRITICAL_ERROR = 'CRITICAL_ERROR';
    public const DATABASE_ERROR = 'DATABASE_ERROR';
    public const HTTP_500 = 'HTTP_500';
    public const HTTP_502 = 'HTTP_502';
    public const HTTP_503 = 'HTTP_503';
    public const HTTP_504 = 'HTTP_504';
    public const HTTP_ERROR = 'HTTP_ERROR';
    public const TIMEOUT = 'TIMEOUT';
    public const DNS_ERROR = 'DNS_ERROR';
    public const SSL_ERROR = 'SSL_ERROR';
    public const SSL_WARNING = 'SSL_WARNING';
    public const REDIRECT_ERROR = 'REDIRECT_ERROR';
    public const MAINTENANCE = 'MAINTENANCE';
    public const CRITICAL_PERFORMANCE = 'CRITICAL_PERFORMANCE';
    public const SLOW = 'SLOW';
    public const WARNING = 'WARNING';
    public const PAUSED = 'PAUSED';
    public const PENDING = 'PENDING';

    public const SEVERITY_DOWN = 'down';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_OK = 'ok';
    public const SEVERITY_NEUTRAL = 'neutral';

    /**
     * Display priority (lower = more severe). Used for sorting and for choosing which of several
     * simultaneous problems to display.
     */
    public const PRIORITY = [
        self::DNS_ERROR            => 1,
        self::DOWN                 => 2,
        self::TIMEOUT              => 3,
        self::HTTP_500             => 4,
        self::HTTP_502             => 4,
        self::HTTP_503             => 4,
        self::HTTP_504             => 4,
        self::CRITICAL_ERROR       => 5,
        self::DATABASE_ERROR       => 6,
        self::SSL_ERROR            => 7,
        self::REDIRECT_ERROR       => 8,
        self::HTTP_ERROR           => 9,
        self::MAINTENANCE          => 10,
        self::CRITICAL_PERFORMANCE => 11,
        self::SUSPECTED_DOWN       => 12,
        self::SLOW                 => 13,
        self::SSL_WARNING          => 14,
        self::WARNING              => 15,
        self::ONLINE               => 16,
        self::PENDING              => 17,
        self::PAUSED               => 18,
    ];

    public const LABELS = [
        self::ONLINE               => 'Online',
        self::DOWN                 => 'Down',
        self::SUSPECTED_DOWN       => 'Suspected Down',
        self::CRITICAL_ERROR       => 'Critical Error',
        self::DATABASE_ERROR       => 'Database Error',
        self::HTTP_500             => 'HTTP 500',
        self::HTTP_502             => 'HTTP 502',
        self::HTTP_503             => 'HTTP 503',
        self::HTTP_504             => 'HTTP 504',
        self::HTTP_ERROR           => 'HTTP Error',
        self::TIMEOUT              => 'Timeout',
        self::DNS_ERROR            => 'DNS Error',
        self::SSL_ERROR            => 'SSL Error',
        self::SSL_WARNING          => 'SSL Warning',
        self::REDIRECT_ERROR       => 'Redirect Error',
        self::MAINTENANCE          => 'Maintenance',
        self::CRITICAL_PERFORMANCE => 'Critical Performance',
        self::SLOW                 => 'Slow',
        self::WARNING              => 'Warning',
        self::PAUSED               => 'Paused',
        self::PENDING              => 'Pending',
    ];

    public const SEVERITIES = [
        self::ONLINE               => self::SEVERITY_OK,
        self::DOWN                 => self::SEVERITY_DOWN,
        self::SUSPECTED_DOWN       => self::SEVERITY_WARNING,
        self::CRITICAL_ERROR       => self::SEVERITY_DOWN,
        self::DATABASE_ERROR       => self::SEVERITY_DOWN,
        self::HTTP_500             => self::SEVERITY_DOWN,
        self::HTTP_502             => self::SEVERITY_DOWN,
        self::HTTP_503             => self::SEVERITY_DOWN,
        self::HTTP_504             => self::SEVERITY_DOWN,
        self::HTTP_ERROR           => self::SEVERITY_DOWN,
        self::TIMEOUT              => self::SEVERITY_DOWN,
        self::DNS_ERROR            => self::SEVERITY_DOWN,
        self::SSL_ERROR            => self::SEVERITY_DOWN,
        self::SSL_WARNING          => self::SEVERITY_WARNING,
        self::REDIRECT_ERROR       => self::SEVERITY_DOWN,
        self::MAINTENANCE          => self::SEVERITY_DOWN,
        self::CRITICAL_PERFORMANCE => self::SEVERITY_DOWN,
        self::SLOW                 => self::SEVERITY_WARNING,
        self::WARNING              => self::SEVERITY_WARNING,
        self::PAUSED               => self::SEVERITY_NEUTRAL,
        self::PENDING              => self::SEVERITY_NEUTRAL,
    ];

    /** Incident type created for each failure status. */
    public const INCIDENT_TYPES = [
        self::DOWN                 => 'DOWNTIME',
        self::DNS_ERROR            => 'DNS_ERROR',
        self::TIMEOUT              => 'TIMEOUT',
        self::HTTP_500             => 'HTTP_ERROR',
        self::HTTP_502             => 'HTTP_ERROR',
        self::HTTP_503             => 'HTTP_ERROR',
        self::HTTP_504             => 'HTTP_ERROR',
        self::HTTP_ERROR           => 'HTTP_ERROR',
        self::CRITICAL_ERROR       => 'WORDPRESS_CRITICAL',
        self::DATABASE_ERROR       => 'DATABASE_ERROR',
        self::SSL_ERROR            => 'SSL_ERROR',
        self::REDIRECT_ERROR       => 'REDIRECT_ERROR',
        self::MAINTENANCE          => 'MAINTENANCE',
        self::CRITICAL_PERFORMANCE => 'PERFORMANCE',
    ];

    public const INCIDENT_TYPE_LABELS = [
        'DOWNTIME'           => 'Downtime',
        'WORDPRESS_CRITICAL' => 'WordPress Critical Error',
        'DATABASE_ERROR'     => 'Database Error',
        'HTTP_ERROR'         => 'HTTP Error',
        'TIMEOUT'            => 'Timeout',
        'DNS_ERROR'          => 'DNS Error',
        'SSL_ERROR'          => 'SSL Error',
        'REDIRECT_ERROR'     => 'Redirect Error',
        'MAINTENANCE'        => 'Maintenance Mode',
        'PERFORMANCE'        => 'Performance',
    ];

    /** Alert rule key (settings / per-site) that governs notifications for a failure status. */
    public const ALERT_KEYS = [
        self::DOWN                 => 'down',
        self::DNS_ERROR            => 'down',
        self::TIMEOUT              => 'timeout',
        self::HTTP_500             => 'http',
        self::HTTP_502             => 'http',
        self::HTTP_503             => 'http',
        self::HTTP_504             => 'http',
        self::HTTP_ERROR           => 'http',
        self::CRITICAL_ERROR       => 'critical',
        self::DATABASE_ERROR       => 'database',
        self::SSL_ERROR            => 'ssl',
        self::REDIRECT_ERROR       => 'down',
        self::MAINTENANCE          => 'down',
        self::CRITICAL_PERFORMANCE => 'slow',
    ];

    public static function label(?string $status): string
    {
        return self::LABELS[$status ?? ''] ?? ucwords(strtolower(str_replace('_', ' ', (string) $status)));
    }

    public static function severity(?string $status): string
    {
        return self::SEVERITIES[$status ?? ''] ?? self::SEVERITY_NEUTRAL;
    }

    public static function isFailure(?string $status): bool
    {
        return self::severity($status) === self::SEVERITY_DOWN;
    }

    public static function isWarning(?string $status): bool
    {
        return self::severity($status) === self::SEVERITY_WARNING;
    }

    public static function priority(?string $status): int
    {
        return self::PRIORITY[$status ?? ''] ?? 99;
    }

    public static function incidentType(string $status): string
    {
        return self::INCIDENT_TYPES[$status] ?? 'DOWNTIME';
    }

    public static function incidentTypeLabel(?string $type): string
    {
        return self::INCIDENT_TYPE_LABELS[$type ?? ''] ?? ucwords(strtolower(str_replace('_', ' ', (string) $type)));
    }

    public static function alertKey(string $status): string
    {
        return self::ALERT_KEYS[$status] ?? 'down';
    }

    /** @return array<int, string> */
    public static function all(): array
    {
        return array_keys(self::LABELS);
    }

    /** @return array<int, string> */
    public static function withSeverity(string $severity): array
    {
        return array_keys(array_filter(self::SEVERITIES, static fn (string $s): bool => $s === $severity));
    }

    /**
     * SQL expression ordering statuses by severity priority (most severe first).
     */
    public static function sqlPriorityExpression(string $column = 'status'): string
    {
        $cases = [];
        foreach (self::PRIORITY as $status => $priority) {
            $cases[] = "WHEN '" . $status . "' THEN " . $priority;
        }
        return 'CASE ' . $column . ' ' . implode(' ', $cases) . ' ELSE 99 END';
    }
}
