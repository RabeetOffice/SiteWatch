<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\UrlNormalizer;
use App\Core\Validator;
use App\Monitoring\SsrfGuard;
use App\Monitoring\Status;
use App\Monitoring\StatusClassifier;
use App\Repositories\ActivityRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\WebsiteRepository;
use Throwable;

/**
 * Website CRUD, validation, pause/resume, bulk operations, import/export and API presentation.
 */
final class WebsiteService
{
    public const CHECK_KEYS = ['http', 'wp_errors', 'response_time', 'ssl', 'redirects', 'maintenance', 'database', 'fatal_errors'];
    public const ALERT_KEYS = ['down', 'critical', 'slow', 'ssl', 'recovery'];

    public const CHECK_LABELS = [
        'http'          => 'HTTP Status',
        'wp_errors'     => 'WordPress Errors',
        'response_time' => 'Response Time',
        'ssl'           => 'SSL Certificate',
        'redirects'     => 'Redirects',
        'maintenance'   => 'Maintenance Mode',
        'database'      => 'Database Errors',
        'fatal_errors'  => 'Exposed Fatal Errors',
    ];

    public const ALERT_LABELS = [
        'down'     => 'Website Down',
        'critical' => 'Critical Error',
        'slow'     => 'Slow Website',
        'ssl'      => 'SSL Expiry',
        'recovery' => 'Recovery',
    ];

    public const TYPE_LABELS = ['wordpress' => 'WordPress', 'woocommerce' => 'WooCommerce', 'other' => 'Other'];

    public function __construct(
        private readonly WebsiteRepository $websites,
        private readonly ActivityRepository $activity,
        private readonly SettingsRepository $settings,
        private readonly SsrfGuard $guard
    ) {
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $input
     * @return array{data: array<string, mixed>, errors: array<string, string>, warnings: array<int, string>}
     */
    public function validate(array $input, ?int $ignoreId = null, bool $checkDns = true): array
    {
        $v = new Validator($input);
        $v->required('name', 'Website name')->max('name', 150, 'Website name');
        $v->max('client_name', 150, 'Client name');
        $v->required('url', 'Website URL')->url('url', 'Website URL');
        $v->in('type', WebsiteRepository::TYPES, 'Website type');
        $v->in('check_interval', WebsiteRepository::INTERVALS, 'Monitoring interval');
        $v->integer('failure_threshold', 1, 10, 'Failure threshold');
        $v->integer('recovery_threshold', 1, 10, 'Recovery threshold');
        $v->max('notes', 2000, 'Notes');

        $warnings = [];
        $url = UrlNormalizer::normalize((string) ($input['url'] ?? ''));
        if ($url !== null && !$v->fails()) {
            $existing = $this->websites->findByUrl($url);
            if ($existing !== null && (int) $existing['id'] !== $ignoreId) {
                $v->addError('url', 'This website is already being monitored (' . $existing['name'] . ').');
            } elseif ($checkDns) {
                try {
                    $resolution = $this->guard->resolve($url);
                    if (!$resolution['ok']) {
                        if (($resolution['error_kind'] ?? '') === 'blocked_target') {
                            $v->addError('url', (string) $resolution['error']);
                        } else {
                            $warnings[] = 'The hostname could not be resolved right now; monitoring will report a DNS error until it resolves.';
                        }
                    }
                } catch (Throwable) {
                    // DNS problems are reported by the monitor itself.
                }
            }
        }

        $checks = [];
        foreach (self::CHECK_KEYS as $key) {
            $checks[$key] = $this->flag($input, 'checks', $key, true);
        }
        $alerts = [];
        foreach (self::ALERT_KEYS as $key) {
            $alerts[$key] = $this->flag($input, 'alerts', $key, true);
        }

        $data = [
            'name'               => trim((string) ($input['name'] ?? '')),
            'client_name'        => trim((string) ($input['client_name'] ?? '')),
            'url'                => (string) $url,
            'domain'             => $url !== null ? UrlNormalizer::domain($url) : '',
            'type'               => in_array($input['type'] ?? '', WebsiteRepository::TYPES, true) ? (string) $input['type'] : 'wordpress',
            'check_interval'     => in_array((int) ($input['check_interval'] ?? 0), WebsiteRepository::INTERVALS, true) ? (int) $input['check_interval'] : $this->settings->getInt('default_check_interval', 5),
            'monitoring_enabled' => array_key_exists('monitoring_enabled', $input) ? (int) $this->toBool($input['monitoring_enabled']) : 1,
            'failure_threshold'  => ($input['failure_threshold'] ?? '') === '' ? null : (int) $input['failure_threshold'],
            'recovery_threshold' => ($input['recovery_threshold'] ?? '') === '' ? null : (int) $input['recovery_threshold'],
            'checks_json'        => json_encode($checks),
            'alerts_json'        => json_encode($alerts),
            'notes'              => trim((string) ($input['notes'] ?? '')) ?: null,
        ];

        return ['data' => $data, 'errors' => $v->errors(), 'warnings' => $warnings];
    }

    /** @param array<string, mixed> $input */
    private function flag(array $input, string $group, string $key, bool $default): bool
    {
        if (isset($input[$group]) && is_array($input[$group])) {
            if (array_is_list($input[$group])) {
                return in_array($key, $input[$group], true);
            }
            return array_key_exists($key, $input[$group]) ? $this->toBool($input[$group][$key]) : false;
        }
        if (array_key_exists($group . '_' . $key, $input)) {
            return $this->toBool($input[$group . '_' . $key]);
        }
        return $default;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }

    // ------------------------------------------------------------------
    // CRUD
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data Validated data
     * @return array<string, mixed>
     */
    public function create(array $data, ?int $userId = null, ?string $ip = null): array
    {
        $data['status'] = (int) $data['monitoring_enabled'] === 1 ? Status::PENDING : Status::PAUSED;
        $data['next_check_at'] = utc_now()->format('Y-m-d H:i:s');
        $data['favicon_url'] = $this->faviconUrl((string) $data['domain']);
        $id = $this->websites->create($data);
        $this->activity->log('website.added', sprintf('Website added: %s (%s)', $data['name'], $data['domain']), $id, $userId, ['url' => $data['url']], $ip);
        return $this->websites->find($id) ?? ['id' => $id] + $data;
    }

    /**
     * @param array<string, mixed> $website Existing row
     * @param array<string, mixed> $data Validated data
     * @return array<string, mixed>
     */
    public function update(array $website, array $data, ?int $userId = null, ?string $ip = null): array
    {
        $id = (int) $website['id'];
        $urlChanged = $data['url'] !== $website['url'];
        if ($urlChanged) {
            $data['favicon_url'] = $this->faviconUrl((string) $data['domain']);
            $data['ssl_valid'] = null;
            $data['ssl_expires_at'] = null;
            $data['ssl_issuer'] = null;
            $data['ssl_error'] = null;
            $data['ssl_checked_at'] = null;
            $data['ssl_alert_level'] = null;
            $data['failure_count'] = 0;
            $data['success_count'] = 0;
            $data['next_check_at'] = utc_now()->format('Y-m-d H:i:s');
        }
        $wasEnabled = (int) $website['monitoring_enabled'] === 1;
        $nowEnabled = (int) $data['monitoring_enabled'] === 1;
        if ($wasEnabled && !$nowEnabled) {
            $data['previous_status'] = $website['status'];
            $data['status'] = Status::PAUSED;
        } elseif (!$wasEnabled && $nowEnabled) {
            $data['status'] = Status::PENDING;
            $data['failure_count'] = 0;
            $data['success_count'] = 0;
            $data['next_check_at'] = utc_now()->format('Y-m-d H:i:s');
        }
        $this->websites->update($id, $data);

        $changes = [];
        foreach (['name', 'client_name', 'url', 'type', 'check_interval', 'monitoring_enabled'] as $field) {
            if ((string) ($website[$field] ?? '') !== (string) ($data[$field] ?? '')) {
                $changes[$field] = ['from' => $website[$field] ?? null, 'to' => $data[$field] ?? null];
            }
        }
        $this->activity->log('website.edited', sprintf('Website edited: %s', $data['name']), $id, $userId, $changes ?: null, $ip);
        if ($wasEnabled && !$nowEnabled) {
            $this->activity->log('monitoring.paused', sprintf('Monitoring paused: %s', $data['name']), $id, $userId, null, $ip);
        } elseif (!$wasEnabled && $nowEnabled) {
            $this->activity->log('monitoring.resumed', sprintf('Monitoring resumed: %s', $data['name']), $id, $userId, null, $ip);
        }
        return $this->websites->find($id) ?? array_merge($website, $data);
    }

    /** @param array<string, mixed> $website */
    public function delete(array $website, ?int $userId = null, ?string $ip = null): void
    {
        $this->websites->delete((int) $website['id']);
        $this->activity->log('website.deleted', sprintf('Website deleted: %s (%s)', $website['name'], $website['domain']), null, $userId, ['url' => $website['url']], $ip);
    }

    /**
     * @param array<string, mixed> $website
     * @return array<string, mixed>
     */
    public function pause(array $website, ?int $userId = null, ?string $ip = null): array
    {
        $id = (int) $website['id'];
        if ((int) $website['monitoring_enabled'] === 1) {
            $this->websites->update($id, [
                'monitoring_enabled' => 0,
                'previous_status'    => $website['status'],
                'status'             => Status::PAUSED,
                'last_status_change_at' => utc_now()->format('Y-m-d H:i:s'),
            ]);
            $this->activity->log('monitoring.paused', sprintf('Monitoring paused: %s', $website['name']), $id, $userId, null, $ip);
        }
        return $this->websites->find($id) ?? $website;
    }

    /**
     * @param array<string, mixed> $website
     * @return array<string, mixed>
     */
    public function resume(array $website, ?int $userId = null, ?string $ip = null): array
    {
        $id = (int) $website['id'];
        if ((int) $website['monitoring_enabled'] !== 1) {
            $this->websites->update($id, [
                'monitoring_enabled' => 1,
                'previous_status'    => $website['status'],
                'status'             => Status::PENDING,
                'failure_count'      => 0,
                'success_count'      => 0,
                'next_check_at'      => utc_now()->format('Y-m-d H:i:s'),
                'last_status_change_at' => utc_now()->format('Y-m-d H:i:s'),
            ]);
            $this->activity->log('monitoring.resumed', sprintf('Monitoring resumed: %s', $website['name']), $id, $userId, null, $ip);
        }
        return $this->websites->find($id) ?? $website;
    }

    /** @param array<string, mixed> $website */
    public function setInterval(array $website, int $interval, ?int $userId = null, ?string $ip = null): void
    {
        if (!in_array($interval, WebsiteRepository::INTERVALS, true)) {
            return;
        }
        $this->websites->update((int) $website['id'], ['check_interval' => $interval]);
        $this->activity->log('website.edited', sprintf('Monitoring interval for %s changed to %d minute(s)', $website['name'], $interval), (int) $website['id'], $userId, ['check_interval' => $interval], $ip);
    }

    public function faviconUrl(string $domain): ?string
    {
        $provider = $this->settings->getString('favicon_provider', 'google');
        $domain = strtolower(trim($domain, '[]'));
        if ($domain === '' || $provider === 'none' || filter_var($domain, FILTER_VALIDATE_IP)) {
            return null;
        }
        return match ($provider) {
            'duckduckgo' => 'https://icons.duckduckgo.com/ip3/' . rawurlencode($domain) . '.ico',
            default      => 'https://www.google.com/s2/favicons?domain=' . rawurlencode($domain) . '&sz=64',
        };
    }

    // ------------------------------------------------------------------
    // Import
    // ------------------------------------------------------------------

    /**
     * Parse "one URL per line" text. Optional " | name | client" suffixes are accepted.
     *
     * @return array<int, array<string, mixed>>
     */
    public function parseUrlList(string $text): array
    {
        $entries = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $entries[] = [
                'url'         => $parts[0],
                'name'        => $parts[1] ?? '',
                'client_name' => $parts[2] ?? '',
                'raw'         => $line,
            ];
        }
        return $entries;
    }

    /**
     * Parse CSV content with optional header row.
     *
     * @return array{entries: array<int, array<string, mixed>>, errors: array<int, string>}
     */
    public function parseCsv(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $rows = [];
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return ['entries' => [], 'errors' => ['Unable to read CSV data.']];
        }
        fwrite($handle, $content);
        rewind($handle);
        $delimiter = ',';
        $firstLine = (string) fgets($handle);
        if (substr_count($firstLine, ';') > substr_count($firstLine, ',')) {
            $delimiter = ';';
        } elseif (substr_count($firstLine, "\t") > substr_count($firstLine, ',')) {
            $delimiter = "\t";
        }
        rewind($handle);
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            if ($row === [null] || $row === []) {
                continue;
            }
            $rows[] = array_map(static fn ($c) => trim((string) $c), $row);
        }
        fclose($handle);
        if ($rows === []) {
            return ['entries' => [], 'errors' => ['The CSV file is empty.']];
        }

        // Header detection
        $map = ['name' => null, 'client_name' => null, 'url' => null, 'type' => null, 'check_interval' => null];
        $header = $rows[0];
        $hasHeader = false;
        foreach ($header as $index => $cell) {
            $key = strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $cell) ?? '');
            $key = trim($key);
            if (in_array($key, ['website name', 'name', 'site', 'site name', 'website'], true) && $map['name'] === null) {
                $map['name'] = $index;
                $hasHeader = true;
            } elseif (in_array($key, ['client name', 'client', 'customer', 'company'], true)) {
                $map['client_name'] = $index;
                $hasHeader = true;
            } elseif (in_array($key, ['url', 'website url', 'address', 'domain', 'link', 'site url'], true)) {
                $map['url'] = $index;
                $hasHeader = true;
            } elseif (in_array($key, ['type', 'website type', 'platform'], true)) {
                $map['type'] = $index;
                $hasHeader = true;
            } elseif (in_array($key, ['check interval', 'interval', 'monitoring interval', 'interval minutes', 'frequency'], true)) {
                $map['check_interval'] = $index;
                $hasHeader = true;
            }
        }
        if ($hasHeader) {
            array_shift($rows);
            if ($map['url'] === null) {
                return ['entries' => [], 'errors' => ['The CSV header must include a "URL" column.']];
            }
        } else {
            // Positional: Website Name, Client Name, URL, Type, Check Interval  — or a single URL column.
            $map = count($header) >= 3
                ? ['name' => 0, 'client_name' => 1, 'url' => 2, 'type' => 3, 'check_interval' => 4]
                : ['name' => 1, 'client_name' => null, 'url' => 0, 'type' => null, 'check_interval' => null];
        }

        $entries = [];
        foreach ($rows as $i => $row) {
            $get = static fn (?int $idx): string => $idx !== null && isset($row[$idx]) ? trim((string) $row[$idx]) : '';
            $url = $get($map['url']);
            if ($url === '') {
                continue;
            }
            $entries[] = [
                'url'            => $url,
                'name'           => $get($map['name']),
                'client_name'    => $get($map['client_name']),
                'type'           => strtolower($get($map['type'])),
                'check_interval' => $get($map['check_interval']),
                'raw'            => 'row ' . ($i + ($hasHeader ? 2 : 1)),
            ];
        }
        return ['entries' => $entries, 'errors' => []];
    }

    /**
     * Import entries, returning a full report. Nothing is silently discarded.
     *
     * @param array<int, array<string, mixed>> $entries
     * @return array{imported: array<int, array<string, mixed>>, duplicates: array<int, array<string, mixed>>, invalid: array<int, array<string, mixed>>, failed: array<int, array<string, mixed>>}
     */
    public function import(array $entries, ?int $userId = null, ?string $ip = null): array
    {
        $report = ['imported' => [], 'duplicates' => [], 'invalid' => [], 'failed' => []];
        $seen = [];
        foreach ($entries as $entry) {
            $raw = (string) ($entry['raw'] ?? ($entry['url'] ?? ''));
            $url = UrlNormalizer::normalize((string) ($entry['url'] ?? ''));
            if ($url === null) {
                $report['invalid'][] = ['input' => $raw, 'reason' => 'Invalid URL'];
                continue;
            }
            if (isset($seen[$url])) {
                $report['duplicates'][] = ['input' => $raw, 'reason' => 'Duplicate inside the import'];
                continue;
            }
            $seen[$url] = true;

            $domain = UrlNormalizer::domain($url);
            $type = (string) ($entry['type'] ?? '');
            $type = match (true) {
                str_contains($type, 'woo')                       => 'woocommerce',
                str_contains($type, 'wordpress') || $type === 'wp' => 'wordpress',
                $type === '' || $type === 'other'                 => $type === '' ? 'wordpress' : 'other',
                default                                           => 'other',
            };
            $interval = (int) ($entry['check_interval'] ?? 0);
            $input = [
                'name'           => ($entry['name'] ?? '') !== '' ? (string) $entry['name'] : self::nameFromDomain($domain),
                'client_name'    => (string) ($entry['client_name'] ?? ''),
                'url'            => $url,
                'type'           => $type,
                'check_interval' => in_array($interval, WebsiteRepository::INTERVALS, true) ? $interval : $this->settings->getInt('default_check_interval', 5),
            ];

            $validated = $this->validate($input, null, false);
            if ($validated['errors'] !== []) {
                $reason = (string) reset($validated['errors']);
                if (str_contains($reason, 'already being monitored')) {
                    $report['duplicates'][] = ['input' => $raw, 'reason' => 'Already monitored'];
                } else {
                    $report['invalid'][] = ['input' => $raw, 'reason' => $reason];
                }
                continue;
            }
            try {
                $website = $this->create($validated['data'], $userId, $ip);
                $report['imported'][] = ['input' => $raw, 'id' => (int) $website['id'], 'name' => $website['name'], 'url' => $website['url']];
            } catch (Throwable $e) {
                $report['failed'][] = ['input' => $raw, 'reason' => 'Database error: ' . str_limit($e->getMessage(), 120)];
            }
        }
        if ($report['imported'] !== []) {
            $this->activity->log('website.imported', sprintf('%d website(s) imported', count($report['imported'])), null, $userId, ['count' => count($report['imported'])], $ip);
        }
        return $report;
    }

    public static function nameFromDomain(string $domain): string
    {
        $domain = preg_replace('/^www\./i', '', $domain) ?? $domain;
        $label = explode('.', $domain)[0] ?? $domain;
        $label = str_replace(['-', '_'], ' ', $label);
        return ucwords($label) ?: $domain;
    }

    // ------------------------------------------------------------------
    // Presentation
    // ------------------------------------------------------------------

    /**
     * API/JSON representation of a website row.
     *
     * @param array<string, mixed> $w
     * @return array<string, mixed>
     */
    public function present(array $w): array
    {
        $status = (string) ($w['status'] ?? Status::PENDING);
        if ((int) ($w['monitoring_enabled'] ?? 1) !== 1) {
            $status = Status::PAUSED;
        }
        $sslDays = StatusClassifier::sslDaysRemaining($w);
        $isHttps = str_starts_with(strtolower((string) ($w['url'] ?? '')), 'https://');
        $checks = StatusClassifier::enabledChecks($w);
        $failureThreshold = (int) ($w['failure_threshold'] ?: $this->settings->getInt('failure_threshold', 3));
        $recoveryThreshold = (int) ($w['recovery_threshold'] ?: $this->settings->getInt('recovery_threshold', 2));

        return [
            'id'                 => (int) $w['id'],
            'name'               => (string) $w['name'],
            'client_name'        => (string) ($w['client_name'] ?? ''),
            'url'                => (string) $w['url'],
            'domain'             => (string) $w['domain'],
            'type'               => (string) ($w['type'] ?? 'wordpress'),
            'type_label'         => self::TYPE_LABELS[$w['type'] ?? 'wordpress'] ?? 'Other',
            'status'             => $status,
            'status_label'       => Status::label($status),
            'severity'           => Status::severity($status),
            'monitoring_enabled' => (int) ($w['monitoring_enabled'] ?? 1) === 1,
            'check_interval'     => (int) ($w['check_interval'] ?? 5),
            'failure_count'      => (int) ($w['failure_count'] ?? 0),
            'success_count'      => (int) ($w['success_count'] ?? 0),
            'failure_threshold'  => $failureThreshold,
            'recovery_threshold' => $recoveryThreshold,
            'recovering'         => Status::isFailure($status) && (int) ($w['success_count'] ?? 0) > 0,
            'last_http_status'   => isset($w['last_http_status']) ? (int) $w['last_http_status'] : null,
            'last_response_time' => isset($w['last_response_time']) ? (int) $w['last_response_time'] : null,
            'last_response_label' => format_ms($w['last_response_time'] ?? null),
            'last_error_type'    => $w['last_error_type'] ?? null,
            'last_error_message' => $w['last_error_message'] ?? null,
            'last_final_url'     => $w['last_final_url'] ?? null,
            'last_checked_at'    => $w['last_checked_at'] ?? null,
            'last_checked_ago'   => time_ago($w['last_checked_at'] ?? null),
            'last_checked_label' => format_datetime($w['last_checked_at'] ?? null),
            'last_online_at'     => $w['last_online_at'] ?? null,
            'last_down_at'       => $w['last_down_at'] ?? null,
            'next_check_at'      => $w['next_check_at'] ?? null,
            'ssl'                => self::presentSsl($w, $sslDays, $isHttps, $checks['ssl']),
            'checks'             => $checks,
            'uptime_30d'         => isset($w['uptime_30d']) ? (float) $w['uptime_30d'] : null,
            'uptime_30d_label'   => format_uptime($w['uptime_30d'] ?? null),
            'open_incidents'     => (int) ($w['open_incidents'] ?? 0),
            'favicon_url'        => $w['favicon_url'] ?? null,
            // Only list queries join the connector columns; other callers leave it out rather than report "none".
            'connector'          => array_key_exists('connector_key_created_at', $w) ? ConnectorService::presentBadge($w) : null,
            'notes'              => $w['notes'] ?? null,
            'created_at'         => $w['created_at'] ?? null,
            'created_label'      => format_datetime($w['created_at'] ?? null),
            'urls'               => [
                'details' => base_url('admin/website-details.php?id=' . (int) $w['id']),
                'edit'    => base_url('admin/website-edit.php?id=' . (int) $w['id']),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $w
     * @return array{applicable: bool, valid: ?bool, expires_at: ?string, expires_label: string, days_remaining: ?int, issuer: ?string, error: ?string, label: string, tone: string, checked_at: ?string}
     */
    public static function presentSsl(array $w, ?int $days, bool $isHttps, bool $enabled = true): array
    {
        if (!$isHttps) {
            return ['applicable' => false, 'valid' => null, 'expires_at' => null, 'expires_label' => '—', 'days_remaining' => null, 'issuer' => null, 'error' => null, 'label' => 'No HTTPS', 'tone' => 'neutral', 'checked_at' => null];
        }
        if (!$enabled) {
            return ['applicable' => false, 'valid' => null, 'expires_at' => null, 'expires_label' => '—', 'days_remaining' => null, 'issuer' => null, 'error' => null, 'label' => 'Not monitored', 'tone' => 'neutral', 'checked_at' => null];
        }
        $valid = isset($w['ssl_valid']) ? (int) $w['ssl_valid'] === 1 : null;
        if ($valid === null) {
            $label = 'Pending';
            $tone = 'neutral';
        } elseif (!$valid) {
            $label = $days !== null && $days < 0 ? 'Expired' : 'Invalid';
            $tone = 'danger';
        } elseif ($days !== null && $days <= 7) {
            $label = 'Expiring · ' . $days . 'd';
            $tone = 'danger';
        } elseif ($days !== null && $days <= 30) {
            $label = 'Expiring · ' . $days . 'd';
            $tone = 'warning';
        } else {
            $label = $days !== null ? 'Valid · ' . $days . 'd' : 'Valid';
            $tone = 'success';
        }
        return [
            'applicable'     => true,
            'valid'          => $valid,
            'expires_at'     => $w['ssl_expires_at'] ?? null,
            'expires_label'  => format_date($w['ssl_expires_at'] ?? null),
            'days_remaining' => $days,
            'issuer'         => $w['ssl_issuer'] ?? null,
            'error'          => $w['ssl_error'] ?? null,
            'label'          => $label,
            'tone'           => $tone,
            'checked_at'     => $w['ssl_checked_at'] ?? null,
        ];
    }

    /**
     * CSV rows for export.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{headers: array<int, string>, rows: array<int, array<int, mixed>>}
     */
    public function exportRows(array $rows): array
    {
        $headers = ['ID', 'Website Name', 'Client Name', 'URL', 'Domain', 'Type', 'Status', 'Monitoring', 'Check Interval (min)', 'Last HTTP Status', 'Last Response (ms)', 'Last Error', 'Last Checked (UTC)', 'SSL Valid', 'SSL Expires (UTC)', 'Uptime 30d %', 'Created (UTC)'];
        $out = [];
        foreach ($rows as $w) {
            $out[] = [
                $w['id'], $w['name'], $w['client_name'], $w['url'], $w['domain'], self::TYPE_LABELS[$w['type']] ?? $w['type'],
                Status::label((int) $w['monitoring_enabled'] === 1 ? $w['status'] : Status::PAUSED),
                (int) $w['monitoring_enabled'] === 1 ? 'Enabled' : 'Paused',
                $w['check_interval'], $w['last_http_status'], $w['last_response_time'], $w['last_error_message'], $w['last_checked_at'],
                isset($w['ssl_valid']) ? ((int) $w['ssl_valid'] === 1 ? 'Yes' : 'No') : '',
                $w['ssl_expires_at'], isset($w['uptime_30d']) ? number_format((float) $w['uptime_30d'], 3) : '', $w['created_at'],
            ];
        }
        return ['headers' => $headers, 'rows' => $out];
    }
}
