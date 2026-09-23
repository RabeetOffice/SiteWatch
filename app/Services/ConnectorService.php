<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Crypto;
use App\Monitoring\Status;
use App\Notifications\NotificationManager;
use App\Repositories\ConnectorRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\WebsiteRepository;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * SiteWatch Connector: the SiteWatch side of the WordPress plugin in wordpress-plugin/sitewatch-connector.
 *
 * Protocol (plugin → SiteWatch only; SiteWatch never calls the WordPress site):
 *   POST api/connector/ingest.php with a JSON body and the headers
 *     X-SiteWatch-Site       website ID
 *     X-SiteWatch-Timestamp  Unix time, accepted within MAX_SKEW seconds
 *     X-SiteWatch-Signature  hex HMAC-SHA256("<timestamp>.<body>", secret)
 *   Body: {v, reason, site_url, sent_at, status: {...}, snapshot?: {...}, events?: [{uid, type, severity, title, data, at}]}
 *   Reply: {success, data: {website, want_snapshot, received, interval}}
 *
 * The connection key the user pastes into WordPress is "swc1_" + base64url({"u": SiteWatch URL, "i": website ID,
 * "s": secret}). The secret is stored encrypted with APP_KEY.
 */
final class ConnectorService
{
    public const KEY_PREFIX = 'swc1_';
    public const MAX_SKEW = 300;
    public const MAX_BODY = 2_000_000;
    /** A connected site that has not reported for this long is shown as "not reporting". */
    public const STALE_AFTER = 1800;
    public const RETENTION_DAYS = 90;
    public const REASONS = ['hello', 'heartbeat', 'fatal', 'event', 'deactivated', 'disconnect'];
    public const SEVERITIES = ['info', 'warning', 'critical'];
    /** Most requests per website per minute before SiteWatch answers 429. */
    private const RATE_LIMIT = 30;
    /** Two fatal error alerts for the same website are at least this far apart. */
    private const ERROR_ALERT_GAP = 600;
    /** First plugin version that can update itself. */
    public const SELF_UPDATE_SINCE = '1.1.0';
    /** Signed plugin download links are valid this long. */
    private const PACKAGE_TTL = 86400;
    /** Inside evidence must be this recent to hold back an alert. */
    public const EVIDENCE_FRESH = 900;
    /** Longest an alert is held back while WordPress reports that it is serving pages. */
    public const HOLD_MAX = 1800;

    public const EVENT_LABELS = [
        'fatal_error'        => 'Fatal error',
        'plugin_activated'   => 'Plugin activated',
        'plugin_deactivated' => 'Plugin deactivated',
        'plugin_installed'   => 'Plugin installed',
        'plugin_updated'     => 'Plugin updated',
        'plugin_deleted'     => 'Plugin deleted',
        'theme_installed'    => 'Theme installed',
        'theme_updated'      => 'Theme updated',
        'theme_switched'     => 'Theme switched',
        'core_updated'       => 'WordPress updated',
        'admin_login'        => 'Administrator sign-in',
        'login_failures'     => 'Failed sign-ins',
        'admin_created'      => 'New administrator',
        'admin_granted'      => 'Administrator role granted',
        'admin_deleted'      => 'Administrator deleted',
        'setting_changed'    => 'Setting changed',
        'connector_updated'  => 'Plugin self-update',
        'connector_update_failed' => 'Plugin self-update failed',
        'vulnerability'      => 'Known vulnerability',
        'file_changed'       => 'Protected file changed',
    ];

    public function __construct(
        private readonly ConnectorRepository $repo,
        private readonly WebsiteRepository $websites,
        private readonly Crypto $crypto,
        private readonly LoggerInterface $log,
        private readonly string $pluginDir,
        private ?NotificationManager $notifications = null
    ) {
    }

    public static function create(): self
    {
        $db = App::db();
        return new self(
            new ConnectorRepository($db),
            new WebsiteRepository($db),
            Crypto::fromConfig(),
            App::logger('connector'),
            (string) App::config()->get('app.paths.root') . DIRECTORY_SEPARATOR . 'wordpress-plugin' . DIRECTORY_SEPARATOR . 'sitewatch-connector'
        );
    }

    public function repository(): ConnectorRepository
    {
        return $this->repo;
    }

    // ------------------------------------------------------------------
    // Keys and signatures
    // ------------------------------------------------------------------

    /**
     * Create (or replace) the secret for a website and return the key to paste into WordPress.
     * Replacing the key disconnects a site still using the old one.
     *
     * @param array<string, mixed> $website
     */
    public function createKey(array $website): string
    {
        if (!$this->crypto->isConfigured()) {
            throw new RuntimeException('APP_KEY is not set in .env, so connection secrets cannot be stored safely.');
        }
        $secret = bin2hex(random_bytes(32));
        $this->repo->saveSecret((int) $website['id'], $this->crypto->encrypt($secret));
        return self::encodeKey(rtrim(base_url(), '/'), (int) $website['id'], $secret);
    }

    /** The current key again (for "Show key"), or null when none was created. */
    public function currentKey(int $websiteId): ?string
    {
        $row = $this->repo->find($websiteId);
        if ($row === null) {
            return null;
        }
        $secret = $this->crypto->decrypt((string) $row['secret']);
        return $secret !== '' ? self::encodeKey(rtrim(base_url(), '/'), $websiteId, $secret) : null;
    }

    public static function encodeKey(string $sitewatchUrl, int $websiteId, string $secret): string
    {
        $json = (string) json_encode(['u' => $sitewatchUrl, 'i' => $websiteId, 's' => $secret], JSON_UNESCAPED_SLASHES);
        return self::KEY_PREFIX . rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /** @return array{u: string, i: int, s: string}|null */
    public static function decodeKey(string $key): ?array
    {
        $key = (string) preg_replace('/\s+/', '', $key);
        if (!str_starts_with($key, self::KEY_PREFIX)) {
            return null;
        }
        $json = base64_decode(strtr(substr($key, strlen(self::KEY_PREFIX)), '-_', '+/'), true);
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data) || !isset($data['u'], $data['i'], $data['s'])) {
            return null;
        }
        return ['u' => (string) $data['u'], 'i' => (int) $data['i'], 's' => (string) $data['s']];
    }

    public static function sign(string $secret, string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /**
     * Check a signed request and return the website and connector rows it belongs to.
     *
     * @return array{website: array<string, mixed>, connector: array<string, mixed>}
     * @throws ConnectorException
     */
    public function verify(int $websiteId, string $timestamp, string $signature, string $body, ?int $now = null): array
    {
        $now ??= time();
        if ($websiteId <= 0 || !ctype_digit($timestamp) || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
            throw new ConnectorException('Missing or malformed signature headers.', 400);
        }
        if (abs($now - (int) $timestamp) > self::MAX_SKEW) {
            throw new ConnectorException('The request timestamp is too far from SiteWatch\'s clock. Check the WordPress server time.', 401);
        }
        $connector = $this->repo->find($websiteId);
        $secret = $connector !== null ? $this->crypto->decrypt((string) $connector['secret']) : '';
        // Compare even when the site is unknown, so timing does not reveal which IDs exist.
        $expected = self::sign($secret !== '' ? $secret : str_repeat('0', 64), $timestamp, $body);
        if (!hash_equals($expected, $signature) || $connector === null || $secret === '') {
            throw new ConnectorException('Invalid signature. Create a new connection key in SiteWatch and connect again.', 401);
        }
        $website = $this->websites->find($websiteId);
        if ($website === null) {
            throw new ConnectorException('This website no longer exists in SiteWatch.', 404);
        }
        return ['website' => $website, 'connector' => $connector];
    }

    /**
     * Per-website request budget, counted in a small file per website and minute.
     *
     * @throws ConnectorException
     */
    public function throttle(int $websiteId): void
    {
        $dir = (string) App::config()->get('app.paths.cache');
        $file = $dir . DIRECTORY_SEPARATOR . 'connector-rate-' . $websiteId . '.json';
        $minute = (int) floor(time() / 60);
        $state = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        $count = is_array($state) && ($state['m'] ?? 0) === $minute ? (int) $state['c'] : 0;
        if ($count >= self::RATE_LIMIT) {
            throw new ConnectorException('Too many reports from this site. Try again in a minute.', 429);
        }
        @file_put_contents($file, json_encode(['m' => $minute, 'c' => $count + 1]), LOCK_EX);
    }

    // ------------------------------------------------------------------
    // Ingest
    // ------------------------------------------------------------------

    /**
     * Store a verified report.
     *
     * @param array<string, mixed> $website
     * @param array<string, mixed> $connector
     * @param array<string, mixed> $payload
     * @return array<string, mixed> Reply data for the plugin.
     */
    public function ingest(array $website, array $connector, array $payload, string $ip): array
    {
        $websiteId = (int) $website['id'];
        $reason = in_array($payload['reason'] ?? '', self::REASONS, true) ? (string) $payload['reason'] : 'heartbeat';
        $status = is_array($payload['status'] ?? null) ? $payload['status'] : [];
        $now = utc_now()->format('Y-m-d H:i:s');

        $update = [
            'last_seen_at'   => $now,
            'last_reason'    => $reason,
            'last_ip'        => mb_substr($ip, 0, 45),
            'site_url'       => mb_substr((string) ($payload['site_url'] ?? ''), 0, 255) ?: null,
            'plugin_version' => self::version($status['plugin_version'] ?? null),
            'wp_version'     => self::version($status['wp_version'] ?? null),
            'php_version'    => self::version($status['php_version'] ?? null),
        ];
        $pulse = is_array($status['pulse'] ?? null) ? $status['pulse'] : [];
        foreach (['ok_at' => 'pulse_ok_at', 'error_at' => 'pulse_error_at', 'probe_at' => 'probe_seen_at'] as $key => $column) {
            $at = self::unixToDb($pulse[$key] ?? null);
            if ($at !== null) {
                $update[$column] = $at;
            }
        }
        if (isset($update['probe_seen_at'])) {
            $update['probe_status'] = max(0, min(999, (int) ($pulse['probe_status'] ?? 0)));
        }
        $lastUpdate = is_array($status['last_update'] ?? null) ? $status['last_update'] : null;
        if ($lastUpdate !== null && self::unixToDb($lastUpdate['at'] ?? null) !== null) {
            $update['update_result'] = mb_substr(ucfirst((string) ($lastUpdate['result'] ?? '')) . ' (' . (string) ($lastUpdate['version'] ?? '?') . ')'
                . (!empty($lastUpdate['message']) ? ': ' . (string) $lastUpdate['message'] : ''), 0, 255);
            $update['update_at'] = self::unixToDb($lastUpdate['at']);
        }
        $bundled = $this->bundledPluginVersion();
        $outdated = $update['plugin_version'] !== null && $bundled !== null && version_compare($update['plugin_version'], $bundled, '<');
        if (!$outdated) {
            $update['want_update'] = 0;
        }

        $firstContact = empty($connector['connected_at']);
        if ($firstContact || $reason === 'hello') {
            $update['connected_at'] = $now;
        }

        $snapshotStored = false;
        if (is_array($payload['snapshot'] ?? null)) {
            $json = json_encode($payload['snapshot'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if (is_string($json) && strlen($json) <= 1_500_000) {
                $summary = self::summarise($payload['snapshot']);
                $update += [
                    'snapshot'        => $json,
                    'snapshot_at'     => $now,
                    'want_snapshot'   => 0,
                    'updates_pending' => $summary['updates'],
                    'security_issues' => $summary['issues'],
                ];
                $snapshotStored = true;
            }
        }
        $this->repo->update($websiteId, $update);

        if ($firstContact || $reason === 'hello') {
            ActivityService::log('connector.connected', sprintf('SiteWatch Connector connected on %s (WordPress %s)', $website['name'], $update['wp_version'] ?? '?'), $websiteId);
        } elseif ($reason === 'deactivated' || $reason === 'disconnect') {
            ActivityService::log('connector.disconnected', sprintf('SiteWatch Connector %s on %s', $reason === 'deactivated' ? 'deactivated' : 'disconnected', $website['name']), $websiteId);
        }

        $received = 0;
        $events = is_array($payload['events'] ?? null) ? array_slice(array_values($payload['events']), 0, 200) : [];
        foreach ($events as $raw) {
            $event = is_array($raw) ? self::normaliseEvent($raw) : null;
            if ($event === null) {
                continue;
            }
            $stored = $this->repo->storeEvent($websiteId, $event);
            $received++;
            if ($stored['is_new']) {
                $this->alertFor($website, $event, $stored['id']);
            }
        }

        $wantSnapshot = !$snapshotStored && ((int) ($connector['want_snapshot'] ?? 0) === 1 || empty($connector['snapshot_at']));
        $reply = [
            'website'       => (string) $website['name'],
            'want_snapshot' => $wantSnapshot,
            'received'      => $received,
            'interval'      => 300,
            'server_time'   => time(),
            'auto_update'   => App::settings()->getBool('connector_auto_update', true),
            'plugin_update' => null,
            'update_now'    => false,
        ];
        if ($outdated) {
            $secret = $this->crypto->decrypt((string) $connector['secret']);
            $reply['plugin_update'] = [
                'version'      => $bundled,
                'package'      => $this->packageUrl($websiteId, $secret, (string) $bundled),
                'requires'     => '5.2',
                'requires_php' => '7.2',
                'tested'       => '',
                'url'          => rtrim(base_url(), '/'),
                'notes'        => 'SiteWatch Connector ' . $bundled . ', supplied by your SiteWatch server.',
            ];
            $reply['update_now'] = (int) ($connector['want_update'] ?? 0) === 1;
        }
        return $reply;
    }

    private static function unixToDb(mixed $value): ?string
    {
        if (!is_numeric($value)) {
            return null;
        }
        $ts = (int) $value;
        $now = time();
        return $ts > $now - 30 * 86400 && $ts <= $now + 300 ? gmdate('Y-m-d H:i:s', min($ts, $now)) : null;
    }

    // ------------------------------------------------------------------
    // Plugin self-update
    // ------------------------------------------------------------------

    /**
     * Short-lived download link for the plugin zip, signed with the site's secret. WordPress downloads packages with
     * a plain GET and no custom headers, so the proof travels in the query string.
     */
    public function packageUrl(int $websiteId, string $secret, string $version, ?int $expires = null): string
    {
        $expires ??= time() + self::PACKAGE_TTL;
        return base_url('api/connector/package.php') . '?' . http_build_query([
            'site'    => $websiteId,
            'v'       => $version,
            'expires' => $expires,
            'sig'     => self::packageSignature($secret, $websiteId, $version, $expires),
        ]);
    }

    public static function packageSignature(string $secret, int $websiteId, string $version, int $expires): string
    {
        return hash_hmac('sha256', 'package|' . $websiteId . '|' . $version . '|' . $expires, $secret);
    }

    /**
     * @throws ConnectorException
     */
    public function verifyPackage(int $websiteId, string $version, int $expires, string $signature, ?int $now = null): void
    {
        $now ??= time();
        if ($websiteId <= 0 || !preg_match('/^[a-f0-9]{64}$/', $signature) || $expires < $now || $expires > $now + 2 * self::PACKAGE_TTL) {
            throw new ConnectorException('This download link is invalid or has expired.', 403);
        }
        $row = $this->repo->find($websiteId);
        $secret = $row !== null ? $this->crypto->decrypt((string) $row['secret']) : '';
        if ($secret === '' || !hash_equals(self::packageSignature($secret, $websiteId, $version, $expires), $signature)) {
            throw new ConnectorException('This download link is invalid or has expired.', 403);
        }
    }

    public function requestUpdate(int $websiteId): void
    {
        $this->repo->update($websiteId, ['want_update' => 1]);
    }

    // ------------------------------------------------------------------
    // Inside evidence (false alert protection)
    // ------------------------------------------------------------------

    /**
     * Does WordPress itself say the site is serving pages? Used before an outage is confirmed: if SiteWatch's checks
     * fail but real visitors keep getting pages, the checks are most likely being blocked.
     *
     * @return array{healthy: bool, reason: string, ok_ago: ?int, probe_seen_ago: ?int, probe_status: ?int}|null Null when the plugin is not reporting.
     */
    public function insideEvidence(int $websiteId): ?array
    {
        $row = $this->repo->find($websiteId);
        if ($row === null || empty($row['connected_at'])) {
            return null;
        }
        $recentFatal = $this->repo->latestFatal($websiteId, utc_now()->modify('-' . self::EVIDENCE_FRESH . ' seconds')->format('Y-m-d H:i:s')) !== null;
        return self::judgeEvidence($row, time(), $recentFatal);
    }

    /**
     * The decision behind insideEvidence(), kept pure for testing.
     *
     * @param array<string, mixed> $row connector_sites row
     * @return array{healthy: bool, reason: string, ok_ago: ?int, probe_seen_ago: ?int, probe_status: ?int}|null
     */
    public static function judgeEvidence(array $row, int $now, bool $recentFatal): ?array
    {
        $ts = static fn (?string $v): ?int => $v !== null && $v !== '' ? (int) strtotime($v . ' UTC') : null;
        $seen = $ts($row['last_seen_at'] ?? null);
        if ($seen === null || $now - $seen > self::EVIDENCE_FRESH || in_array($row['last_reason'] ?? '', ['deactivated', 'disconnect'], true)) {
            return null;
        }
        $ok = $ts($row['pulse_ok_at'] ?? null);
        $error = $ts($row['pulse_error_at'] ?? null);
        $probe = $ts($row['probe_seen_at'] ?? null);
        $result = [
            'healthy'        => false,
            'reason'         => '',
            'ok_ago'         => $ok !== null ? $now - $ok : null,
            'probe_seen_ago' => $probe !== null ? $now - $probe : null,
            'probe_status'   => isset($row['probe_status']) ? (int) $row['probe_status'] : null,
        ];
        if ($ok === null || $now - $ok > self::EVIDENCE_FRESH) {
            $result['reason'] = 'WordPress has not served a page recently.';
        } elseif ($recentFatal || ($error !== null && $now - $error <= self::EVIDENCE_FRESH)) {
            $result['reason'] = 'WordPress also reported server errors.';
        } else {
            $result['healthy'] = true;
            $result['reason'] = 'WordPress served pages normally ' . ($now - $ok < 60 ? 'within the last minute' : intdiv($now - $ok, 60) . ' min ago') . '.';
        }
        return $result;
    }

    /**
     * Validate one event from the plugin into repository fields.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>|null
     */
    public static function normaliseEvent(array $raw): ?array
    {
        $uid = (string) ($raw['uid'] ?? '');
        $type = (string) ($raw['type'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $uid) || !preg_match('/^[a-z_]{2,40}$/', $type)) {
            return null;
        }
        $severity = in_array($raw['severity'] ?? '', self::SEVERITIES, true) ? (string) $raw['severity'] : 'info';
        $data = is_array($raw['data'] ?? null) ? $raw['data'] : [];
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json) || strlen($json) > 60_000) {
            $json = '{}';
        }
        $now = time();
        $at = (int) ($raw['at'] ?? $now);
        $at = $at > $now + 300 || $at < $now - 30 * 86400 ? $now : $at;
        $first = isset($data['first_at']) ? (int) $data['first_at'] : $at;
        $first = $first > $at || $first < $now - 30 * 86400 ? $at : $first;

        $fingerprint = null;
        if ($type === 'fatal_error') {
            $fp = (string) ($data['fingerprint'] ?? '');
            $fingerprint = preg_match('/^[a-f0-9]{40}$/', $fp) ? $fp : sha1($json);
        }
        $title = trim((string) ($raw['title'] ?? ''));
        return [
            'event_uid'        => $uid,
            'type'             => $type,
            'severity'         => $severity,
            'title'            => mb_substr($title !== '' ? $title : (self::EVENT_LABELS[$type] ?? $type), 0, 255),
            'data'             => $json,
            'fingerprint'      => $fingerprint,
            'new_count'        => max(1, (int) ($data['new_count'] ?? 1)),
            'total_count'      => isset($data['total_count']) ? max(1, (int) $data['total_count']) : null,
            'occurred_at'      => gmdate('Y-m-d H:i:s', $first),
            'last_occurred_at' => gmdate('Y-m-d H:i:s', $at),
        ];
    }

    /**
     * Counts for the list view: pending updates and security checks needing attention.
     *
     * @param array<string, mixed> $snapshot
     * @return array{updates: int, issues: int}
     */
    public static function summarise(array $snapshot): array
    {
        $updates = $snapshot['updates'] ?? [];
        $count = (!empty($updates['core']['latest']) ? 1 : 0)
            + (is_array($updates['plugins'] ?? null) ? count($updates['plugins']) : 0)
            + (is_array($updates['themes'] ?? null) ? count($updates['themes']) : 0);
        $issues = 0;
        foreach ((array) ($snapshot['security'] ?? []) as $check) {
            if (is_array($check) && in_array($check['status'] ?? '', ['warning', 'critical'], true)) {
                $issues++;
            }
        }
        return ['updates' => $count, 'issues' => $issues];
    }

    private static function version(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        return $value !== '' && preg_match('/^[0-9A-Za-z.\-+]{1,20}$/', $value) ? $value : null;
    }

    // ------------------------------------------------------------------
    // Alerts
    // ------------------------------------------------------------------

    private function notifications(): NotificationManager
    {
        return $this->notifications ??= new NotificationManager(App::settings(), new NotificationRepository(App::db()), App::logger('notifications'));
    }

    /**
     * Alert on the first report of a fatal error and on critical security events.
     *
     * @param array<string, mixed> $website
     * @param array<string, mixed> $event Normalised event.
     */
    private function alertFor(array $website, array $event, int $eventId): void
    {
        try {
            if ($event['type'] === 'fatal_error') {
                $recent = $this->repo->events((int) $website['id'], ['fatal_error'], 5);
                foreach ($recent as $row) {
                    if ((int) $row['id'] !== $eventId && !empty($row['notified_at'])
                        && time() - strtotime($row['notified_at'] . ' UTC') < self::ERROR_ALERT_GAP) {
                        return; // One error alert per site at a time; the details page lists the rest.
                    }
                }
                if ($this->notifications()->wordpressError($website, $event)) {
                    $this->repo->markNotified($eventId);
                }
            } elseif ($event['severity'] === 'critical') {
                if ($this->notifications()->wordpressSecurity($website, $event)) {
                    $this->repo->markNotified($eventId);
                }
            }
        } catch (Throwable $e) {
            $this->log->error('Connector alert failed', ['website_id' => $website['id'], 'error' => $e->getMessage()]);
        }
    }

    /**
     * One-line cause for a down alert: the latest fatal error reported shortly before, e.g.
     * "Elementor Pro 3.21: Fatal error: Call to undefined function foo() (wp-content/plugins/elementor-pro/x.php:142)".
     */
    public function causeFor(int $websiteId, int $withinSeconds = 1800): ?string
    {
        $row = $this->repo->latestFatal($websiteId, utc_now()->modify("-{$withinSeconds} seconds")->format('Y-m-d H:i:s'));
        return $row !== null ? self::describeError($row) : null;
    }

    /** @param array<string, mixed> $row connector_events row or normalised event */
    public static function describeError(array $row): string
    {
        $data = json_decode((string) ($row['data'] ?? ''), true);
        if (!is_array($data)) {
            return (string) ($row['title'] ?? 'Fatal error');
        }
        $component = is_array($data['component'] ?? null) ? $data['component'] : [];
        $source = trim((string) ($component['name'] ?? '') . ' ' . (string) ($component['version'] ?? ''));
        $message = (string) ($data['message'] ?? '');
        $file = (string) ($data['file'] ?? '');
        // PHP's "Uncaught …" messages already end with "in <file>:<line>".
        $where = $file !== '' && !str_contains($message, $file) ? ' (' . $file . ':' . (int) ($data['line'] ?? 0) . ')' : '';
        return ($source !== '' ? $source . ': ' : '') . ($data['error_type'] ?? 'Fatal error') . ': '
            . str_limit($message, 300) . $where;
    }

    // ------------------------------------------------------------------
    // Management
    // ------------------------------------------------------------------

    public function requestSnapshot(int $websiteId): void
    {
        $this->repo->update($websiteId, ['want_snapshot' => 1]);
    }

    public function revoke(int $websiteId): void
    {
        $this->repo->delete($websiteId);
    }

    /**
     * Connection state for a connector_sites row (or the columns joined into a website row).
     *
     * @return string none | pending | connected | stale | deactivated | disconnected
     */
    public static function state(?array $row): string
    {
        if ($row === null || empty($row['key_created_at'] ?? $row['connector_key_created_at'] ?? null)) {
            return 'none';
        }
        $connected = $row['connected_at'] ?? $row['connector_connected_at'] ?? null;
        $seen = $row['last_seen_at'] ?? $row['connector_seen_at'] ?? null;
        $reason = $row['last_reason'] ?? $row['connector_reason'] ?? null;
        if (empty($connected)) {
            return 'pending';
        }
        if ($reason === 'deactivated') {
            return 'deactivated';
        }
        if ($reason === 'disconnect') {
            return 'disconnected';
        }
        return $seen !== null && time() - (int) strtotime($seen . ' UTC') <= self::STALE_AFTER ? 'connected' : 'stale';
    }

    public const STATE_LABELS = [
        'none'         => 'Not installed',
        'pending'      => 'Waiting for the plugin',
        'connected'    => 'Connected',
        'stale'        => 'Not reporting',
        'deactivated'  => 'Plugin deactivated',
        'disconnected' => 'Disconnected in WordPress',
    ];

    /**
     * Compact status for website rows (list, dashboard).
     *
     * @param array<string, mixed> $w Website row with the connector_* columns from WebsiteRepository.
     * @return array{state: string, label: string, last_seen_at: ?string, last_seen_ago: string, plugin_version: ?string, wp_version: ?string, updates: ?int, issues: ?int}
     */
    public static function presentBadge(array $w): array
    {
        $state = self::state($w);
        return [
            'state'          => $state,
            'label'          => self::STATE_LABELS[$state],
            'last_seen_at'   => $w['connector_seen_at'] ?? null,
            'last_seen_ago'  => time_ago($w['connector_seen_at'] ?? null),
            'plugin_version' => $w['connector_plugin_version'] ?? null,
            'wp_version'     => $w['connector_wp_version'] ?? null,
            'updates'        => isset($w['connector_updates']) ? (int) $w['connector_updates'] : null,
            'issues'         => isset($w['connector_issues']) ? (int) $w['connector_issues'] : null,
        ];
    }

    /**
     * Everything the website details page shows.
     *
     * @return array<string, mixed>
     */
    public function details(int $websiteId): array
    {
        $row = $this->repo->find($websiteId);
        $state = self::state($row);
        $snapshot = $row !== null && !empty($row['snapshot']) ? json_decode((string) $row['snapshot'], true) : null;
        $bundled = $this->bundledPluginVersion();

        $errors = $row !== null ? array_map([self::class, 'presentEvent'], $this->repo->events($websiteId, ['fatal_error'], 25)) : [];
        $activity = $row !== null ? array_map([self::class, 'presentEvent'], $this->repo->events($websiteId, null, 50, ['fatal_error'])) : [];

        return [
            'state'           => $state,
            'state_label'     => self::STATE_LABELS[$state],
            'key_created_at'  => $row['key_created_at'] ?? null,
            'connected_at'    => $row['connected_at'] ?? null,
            'last_seen_at'    => $row['last_seen_at'] ?? null,
            'last_seen_label' => $row !== null && $row['last_seen_at'] ? format_datetime($row['last_seen_at']) . ' (' . time_ago($row['last_seen_at']) . ')' : 'Never',
            'last_reason'     => $row['last_reason'] ?? null,
            'site_url'        => $row['site_url'] ?? null,
            'plugin_version'  => $row['plugin_version'] ?? null,
            'bundled_version' => $bundled,
            'plugin_outdated' => $row !== null && !empty($row['plugin_version']) && $bundled !== null && version_compare((string) $row['plugin_version'], $bundled, '<'),
            'wp_version'      => $row['wp_version'] ?? null,
            'php_version'     => $row['php_version'] ?? null,
            'updates_pending' => isset($row['updates_pending']) ? (int) $row['updates_pending'] : null,
            'security_issues' => isset($row['security_issues']) ? (int) $row['security_issues'] : null,
            'snapshot_at'     => $row['snapshot_at'] ?? null,
            'snapshot_label'  => $row !== null && $row['snapshot_at'] ? time_ago($row['snapshot_at']) : null,
            'want_snapshot'   => $row !== null && (int) $row['want_snapshot'] === 1,
            'want_update'     => $row !== null && (int) ($row['want_update'] ?? 0) === 1,
            // Self-update arrived in plugin 1.1.0; older copies must be replaced by hand once.
            'can_self_update' => $row !== null && !empty($row['plugin_version']) && version_compare((string) $row['plugin_version'], self::SELF_UPDATE_SINCE, '>='),
            'auto_update'     => App::settings()->getBool('connector_auto_update', true),
            'update_result'   => $row['update_result'] ?? null,
            'update_label'    => $row !== null && !empty($row['update_at']) ? time_ago($row['update_at']) : null,
            'pulse'           => $row === null ? null : [
                'ok_at'        => $row['pulse_ok_at'] ?? null,
                'ok_ago'       => time_ago($row['pulse_ok_at'] ?? null, 'Not recorded yet'),
                'error_at'     => $row['pulse_error_at'] ?? null,
                'error_ago'    => time_ago($row['pulse_error_at'] ?? null, 'None recorded'),
                'probe_at'     => $row['probe_seen_at'] ?? null,
                'probe_ago'    => time_ago($row['probe_seen_at'] ?? null, 'Not seen yet'),
                'probe_status' => isset($row['probe_status']) ? (int) $row['probe_status'] : null,
            ],
            'snapshot'        => is_array($snapshot) ? $snapshot : null,
            'vulnerabilities' => self::presentVulnerabilities($row),
            'errors'          => $errors,
            'activity'        => $activity,
        ];
    }

    /**
     * The latest vulnerability report for the website page, or null before the first check.
     *
     * @param array<string, mixed>|null $row connector_sites row
     * @return array<string, mixed>|null
     */
    public static function presentVulnerabilities(?array $row): ?array
    {
        $report = $row !== null && !empty($row['vuln_report']) ? json_decode((string) $row['vuln_report'], true) : null;
        if (!is_array($report)) {
            return null;
        }
        return [
            'count'         => count($report['items'] ?? []),
            'items'         => array_values((array) ($report['items'] ?? [])),
            'closed'        => array_values((array) ($report['closed'] ?? [])),
            'components'    => (int) ($report['components'] ?? 0),
            'unavailable'   => (int) ($report['unavailable'] ?? 0),
            'source'        => (string) ($report['source'] ?? VulnerabilityFeed::SOURCE_NAME),
            'source_url'    => (string) ($report['source_url'] ?? VulnerabilityFeed::SOURCE_URL),
            'checked_at'    => $row['vuln_checked_at'] ?? null,
            'checked_label' => time_ago($row['vuln_checked_at'] ?? null),
            'stale'         => !empty($row['snapshot_at']) && !empty($row['vuln_checked_at']) && $row['vuln_checked_at'] < $row['snapshot_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row connector_events row
     * @return array<string, mixed>
     */
    public static function presentEvent(array $row): array
    {
        $data = json_decode((string) ($row['data'] ?? ''), true);
        return [
            'id'               => (int) $row['id'],
            'type'             => (string) $row['type'],
            'type_label'       => self::EVENT_LABELS[$row['type']] ?? ucwords(str_replace('_', ' ', (string) $row['type'])),
            'severity'         => (string) $row['severity'],
            'title'            => (string) $row['title'],
            'data'             => is_array($data) ? $data : [],
            'occurrences'      => (int) $row['occurrences'],
            'occurred_at'      => $row['occurred_at'],
            'last_occurred_at' => $row['last_occurred_at'],
            'last_label'       => format_datetime($row['last_occurred_at']),
            'last_ago'         => time_ago($row['last_occurred_at']),
            'first_label'      => format_datetime($row['occurred_at']),
            'notified'         => !empty($row['notified_at']),
        ];
    }

    /**
     * Fleet overview for the dashboard.
     *
     * @return array{total_websites: int, installed: int, connected: int, attention: int, sites: array<int, array<string, mixed>>, recent: array<int, array<string, mixed>>}
     */
    public function fleet(): array
    {
        $sites = [];
        $connected = 0;
        $attention = 0;
        foreach ($this->repo->allWithWebsites() as $row) {
            $state = self::state($row + ['key_created_at' => 'x']);
            if ($state === 'connected') {
                $connected++;
            }
            if ($state !== 'connected' && $state !== 'pending') {
                $attention++;
            }
            $sites[] = [
                'website_id'     => (int) $row['website_id'],
                'name'           => (string) $row['name'],
                'client_name'    => (string) ($row['client_name'] ?? ''),
                'state'          => $state,
                'state_label'    => self::STATE_LABELS[$state],
                'last_seen_ago'  => time_ago($row['last_seen_at']),
                'wp_version'     => $row['wp_version'],
                'plugin_version' => $row['plugin_version'],
                'updates'        => $row['updates_pending'] !== null ? (int) $row['updates_pending'] : null,
                'issues'         => $row['security_issues'] !== null ? (int) $row['security_issues'] : null,
                'vulnerabilities' => isset($row['vuln_count']) ? (int) $row['vuln_count'] : null,
                'url'            => base_url('admin/website-details.php?id=' . (int) $row['website_id']) . '#wordpressSection',
            ];
        }
        $recent = array_map(static function (array $row): array {
            return self::presentEvent($row) + [
                'website_id'   => (int) $row['website_id'],
                'website_name' => (string) $row['website_name'],
                'url'          => base_url('admin/website-details.php?id=' . (int) $row['website_id']) . '#wordpressSection',
            ];
        }, $this->repo->recentImportant(6));

        return [
            'total_websites' => $this->websites->count(),
            'installed'      => count(array_filter($sites, static fn (array $s): bool => $s['state'] !== 'pending')),
            'connected'      => $connected,
            'attention'      => $attention,
            'sites'          => $sites,
            'recent'         => $recent,
        ];
    }

    public function purge(): int
    {
        $this->repo->purgeFeedOlderThan(utc_now()->modify('-' . VulnerabilityScanner::FEED_RETENTION_DAYS . ' days')->format('Y-m-d H:i:s'));
        return $this->repo->purgeOlderThan(utc_now()->modify('-' . self::RETENTION_DAYS . ' days')->format('Y-m-d H:i:s'));
    }

    // ------------------------------------------------------------------
    // Plugin download
    // ------------------------------------------------------------------

    public function bundledPluginVersion(): ?string
    {
        $main = $this->pluginDir . DIRECTORY_SEPARATOR . 'sitewatch-connector.php';
        $head = is_readable($main) ? (string) file_get_contents($main, false, null, 0, 2048) : '';
        return preg_match('/^\s*\*\s*Version:\s*([0-9][0-9A-Za-z.\-]*)/m', $head, $m) ? $m[1] : null;
    }

    /**
     * Zip the bundled plugin into a temporary file with a top-level "sitewatch-connector/" folder, as WordPress expects.
     */
    public function buildZip(): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is not available on this server.');
        }
        if (!is_dir($this->pluginDir)) {
            throw new RuntimeException('The plugin files are missing from wordpress-plugin/sitewatch-connector.');
        }
        $path = tempnam(sys_get_temp_dir(), 'swc');
        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the zip file.');
        }
        $base = realpath($this->pluginDir);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator((string) $base, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen((string) $base) + 1));
            $zip->addFile($file->getPathname(), 'sitewatch-connector/' . $relative);
        }
        $zip->close();
        return $path;
    }
}
