<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Repositories\ConnectorRepository;
use RuntimeException;

/**
 * Remote actions for WordPress sites running SiteWatch Connector 1.4.0+ (design and threat model:
 * docs/sitewatch-connector.md, section 7).
 *
 * A request becomes a connector_commands row. The next heartbeat reply carries every open command, signed with the
 * site's secret: HMAC-SHA256("command|<site>|<id>|<action>|<args_json>|<expires>"). The plugin checks it, runs it if
 * the WordPress administrator allowed that action, and answers with a "command_result" event. Commands without an
 * answer after TTL are shown as "No answer".
 */
final class RemoteActionService
{
    public const TTL = 3600;
    /** Planned maintenance keeps holding alerts this long after its end, while the site comes back. */
    public const MAINTENANCE_GRACE = 300;

    public const ACTIONS = [
        'clear_cache'       => 'Clear caches',
        'deactivate_plugin' => 'Deactivate plugin',
        'activate_plugin'   => 'Activate plugin',
        'update_plugins'    => 'Update plugins',
        'maintenance'       => 'Maintenance page',
        'rollback_plugin'   => 'Roll back plugin',
        'update_themes'     => 'Update themes',
        'update_core'       => 'Update WordPress',
        'backup'            => 'Backup',
    ];

    public const STATUS_LABELS = [
        'pending' => 'Waiting for the site',
        'sent'    => 'Sent to the site',
        'done'    => 'Done',
        'failed'  => 'Failed',
        'expired' => 'No answer',
    ];

    public function __construct(private readonly ConnectorRepository $repo)
    {
    }

    public static function create(): self
    {
        return new self(new ConnectorRepository(App::db()));
    }

    public static function sign(string $secret, int $websiteId, int $id, string $action, string $argsJson, int $expires): string
    {
        return hash_hmac('sha256', 'command|' . $websiteId . '|' . $id . '|' . $action . '|' . $argsJson . '|' . $expires, $secret);
    }

    /**
     * Actions this site allows, as reported by the plugin: null when the plugin cannot do remote actions (before
     * 1.4.0), an empty list when the WordPress administrator has not allowed any.
     *
     * @param array<string, mixed>|null $row connector_sites row
     * @return array<int, string>|null
     */
    public static function allowed(?array $row): ?array
    {
        $list = $row !== null && isset($row['remote_actions']) ? json_decode((string) $row['remote_actions'], true) : null;
        return is_array($list) ? array_values(array_intersect(array_keys(self::ACTIONS), $list)) : null;
    }

    /**
     * Validate a request against what the site allows and what its last snapshot says, and queue it.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed> The command row.
     * @throws RuntimeException With a message for the user.
     */
    public function request(int $websiteId, string $action, array $args, ?int $userId): array
    {
        $row = $this->repo->find($websiteId);
        if ($row === null || empty($row['connected_at']) || ConnectorService::state($row) === 'none') {
            throw new RuntimeException('This website is not connected with the SiteWatch Connector plugin.');
        }
        if (!isset(self::ACTIONS[$action])) {
            throw new RuntimeException('Unknown remote action.');
        }
        $allowed = self::allowed($row);
        if ($allowed === null) {
            throw new RuntimeException('Remote actions need SiteWatch Connector 1.4.0 or later on this site.');
        }
        if (!in_array($action, $allowed, true)) {
            throw new RuntimeException('This site does not allow "' . self::ACTIONS[$action] . '". A WordPress administrator can allow it under SiteWatch → Remote actions in the WordPress admin menu.');
        }
        $snapshot = !empty($row['snapshot']) ? json_decode((string) $row['snapshot'], true) : null;
        $clean = self::validateArgs($action, $args, is_array($snapshot) ? $snapshot : []);

        $now = time();
        $id = $this->repo->createCommand([
            'website_id'   => $websiteId,
            'action'       => $action,
            'args'         => (string) json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'status'       => 'pending',
            'requested_by' => $userId,
            'created_at'   => gmdate('Y-m-d H:i:s', $now),
            'expires_at'   => gmdate('Y-m-d H:i:s', $now + self::TTL),
        ]);
        if ($action === 'maintenance' && $clean['mode'] === 'on') {
            // Hold maintenance alerts from now: delivery can take a few minutes. The plugin's report then confirms it.
            $this->repo->update($websiteId, ['maintenance_until' => gmdate('Y-m-d H:i:s', $now + self::TTL + $clean['minutes'] * 60)]);
        }
        return (array) $this->repo->command($id);
    }

    /** Actions that can be sent to many sites at once (the others name one site's plugin). */
    public const BULK_ACTIONS = ['clear_cache', 'update_plugins', 'update_themes', 'maintenance', 'backup'];

    /**
     * Queue one action on many websites. Each site goes through request(), so a site that is not connected, runs an
     * older plugin or does not allow the action is skipped with the reason. For "update_plugins" every site gets its
     * own list: all plugin updates in its last health report (at most 20).
     *
     * @param array<int, array<string, mixed>> $websites websites rows
     * @param array<string, mixed> $args
     * @return array{queued: array<int, array{website_id: int, name: string, command_id: int}>, skipped: array<int, array{website_id: int, name: string, reason: string}>}
     */
    public function bulk(array $websites, string $action, array $args, ?int $userId): array
    {
        if (!in_array($action, self::BULK_ACTIONS, true)) {
            throw new RuntimeException('This action cannot be sent to many websites at once.');
        }
        $result = ['queued' => [], 'skipped' => []];
        foreach ($websites as $website) {
            $id = (int) $website['id'];
            $name = (string) $website['name'];
            try {
                $siteArgs = $args;
                if ($action === 'update_plugins' || $action === 'update_themes') {
                    $row = $this->repo->find($id);
                    $snapshot = $row !== null && !empty($row['snapshot']) ? json_decode((string) $row['snapshot'], true) : null;
                    $snapshot = is_array($snapshot) ? $snapshot : [];
                    $siteArgs = $action === 'update_plugins' ? ['plugins' => self::pendingUpdates($snapshot)] : ['themes' => self::pendingThemeUpdates($snapshot)];
                    if (reset($siteArgs) === [] && $row !== null && !empty($row['connected_at'])) {
                        $result['skipped'][] = ['website_id' => $id, 'name' => $name, 'reason' => 'No ' . ($action === 'update_plugins' ? 'plugin' : 'theme') . ' updates in the last health report.'];
                        continue;
                    }
                }
                $command = $this->request($id, $action, $siteArgs, $userId);
                $result['queued'][] = ['website_id' => $id, 'name' => $name, 'command_id' => (int) $command['id']];
            } catch (RuntimeException $e) {
                $result['skipped'][] = ['website_id' => $id, 'name' => $name, 'reason' => $e->getMessage()];
            }
        }
        return $result;
    }

    /**
     * Plugin files with an update in a health snapshot, except SiteWatch Connector (it updates itself), at most 20.
     *
     * @param array<string, mixed> $snapshot
     * @return array<int, string>
     */
    public static function pendingUpdates(array $snapshot): array
    {
        $files = [];
        foreach ((array) ($snapshot['plugins'] ?? []) as $p) {
            if (is_array($p) && !empty($p['update']) && isset($p['file']) && !str_starts_with((string) $p['file'], 'sitewatch-connector/')) {
                $files[] = (string) $p['file'];
            }
        }
        return array_slice($files, 0, 20);
    }

    /**
     * Backup plugins in a health snapshot: a list from plugin 1.8.0, a single UpdraftPlus entry from 1.7.0.
     *
     * @param array<string, mixed> $snapshot
     * @return array<int, array<string, mixed>>
     */
    public static function backupPlugins(array $snapshot): array
    {
        $backups = $snapshot['backups'] ?? null;
        if (!is_array($backups)) {
            return [];
        }
        $list = isset($backups['plugin']) ? [$backups] : array_values(array_filter($backups, 'is_array'));
        return array_map(static fn (array $b): array => $b + ['key' => strtolower((string) ($b['plugin'] ?? ''))], $list);
    }

    /**
     * Theme folders with an update in a health snapshot, at most 20.
     *
     * @param array<string, mixed> $snapshot
     * @return array<int, string>
     */
    public static function pendingThemeUpdates(array $snapshot): array
    {
        $slugs = [];
        foreach ((array) ($snapshot['themes'] ?? []) as $t) {
            if (is_array($t) && !empty($t['update']) && isset($t['slug'])) {
                $slugs[] = (string) $t['slug'];
            }
        }
        return array_slice($slugs, 0, 20);
    }

    /**
     * Arguments for a command, checked against the site's last health snapshot.
     *
     * @param array<string, mixed> $args
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     * @throws RuntimeException
     */
    public static function validateArgs(string $action, array $args, array $snapshot): array
    {
        $plugins = [];
        foreach ((array) ($snapshot['plugins'] ?? []) as $p) {
            if (is_array($p) && isset($p['file'])) {
                $plugins[(string) $p['file']] = $p;
            }
        }
        $pluginArg = static function (mixed $file) use ($plugins): string {
            $file = is_string($file) ? $file : '';
            if (!isset($plugins[$file])) {
                throw new RuntimeException('That plugin is not in the site\'s last health report. Refresh the health report and try again.');
            }
            if (str_starts_with($file, 'sitewatch-connector/')) {
                throw new RuntimeException('SiteWatch Connector cannot change itself through remote actions.');
            }
            return $file;
        };

        switch ($action) {
            case 'clear_cache':
                return [];
            case 'deactivate_plugin':
            case 'activate_plugin':
                $file = $pluginArg($args['plugin'] ?? null);
                $active = !empty($plugins[$file]['active']);
                if ($action === 'deactivate_plugin' && !$active) {
                    throw new RuntimeException('That plugin is already inactive.');
                }
                if ($action === 'activate_plugin' && $active) {
                    throw new RuntimeException('That plugin is already active.');
                }
                return ['plugin' => $file];
            case 'update_plugins':
                $requested = is_array($args['plugins'] ?? null) ? array_values(array_unique(array_map('strval', $args['plugins']))) : [];
                $files = [];
                foreach ($requested as $file) {
                    $file = $pluginArg($file);
                    if (empty($plugins[$file]['update'])) {
                        throw new RuntimeException(($plugins[$file]['name'] ?? $file) . ' has no update in the last health report.');
                    }
                    $files[] = $file;
                }
                if ($files === [] || count($files) > 20) {
                    throw new RuntimeException('Choose between 1 and 20 plugins to update.');
                }
                return ['plugins' => $files];
            case 'rollback_plugin':
                $file = $pluginArg($args['plugin'] ?? null);
                $previous = (string) ($plugins[$file]['previous_version'] ?? '');
                if ($previous === '') {
                    throw new RuntimeException('No earlier version of this plugin is recorded. Versions are recorded at each update from plugin 1.5.0 on.');
                }
                if (($args['version'] ?? null) !== $previous) {
                    throw new RuntimeException('This plugin can only be rolled back to ' . $previous . ', the version before its last update.');
                }
                return ['plugin' => $file, 'version' => $previous];
            case 'update_themes':
                $themes = [];
                foreach ((array) ($snapshot['themes'] ?? []) as $t) {
                    if (is_array($t) && isset($t['slug'])) {
                        $themes[(string) $t['slug']] = $t;
                    }
                }
                $requested = is_array($args['themes'] ?? null) ? array_values(array_unique(array_map('strval', $args['themes']))) : [];
                foreach ($requested as $slug) {
                    if (!isset($themes[$slug])) {
                        throw new RuntimeException('That theme is not in the site\'s last health report. Refresh the health report and try again.');
                    }
                    if (empty($themes[$slug]['update'])) {
                        throw new RuntimeException(($themes[$slug]['name'] ?? $slug) . ' has no update in the last health report.');
                    }
                }
                if ($requested === [] || count($requested) > 20) {
                    throw new RuntimeException('Choose between 1 and 20 themes to update.');
                }
                return ['themes' => $requested];
            case 'update_core':
                $latest = (string) ($snapshot['updates']['core']['latest'] ?? '');
                if ($latest === '') {
                    throw new RuntimeException('The last health report shows no WordPress update for this site.');
                }
                if (($args['version'] ?? null) !== $latest) {
                    throw new RuntimeException('WordPress can only be updated to ' . $latest . ', the version in the last health report.');
                }
                return ['version' => $latest];
            case 'backup':
                $plugins = self::backupPlugins($snapshot);
                if ($plugins === []) {
                    throw new RuntimeException('The last health report shows no supported backup plugin (UpdraftPlus or BackWPup) on this site.');
                }
                $want = (string) ($args['plugin'] ?? '');
                if ($want === '') {
                    return []; // the site uses the plugin it has (UpdraftPlus first)
                }
                if (!in_array($want, array_column($plugins, 'key'), true)) {
                    throw new RuntimeException('That backup plugin is not active on this site.');
                }
                return ['plugin' => $want];
            case 'maintenance':
                $mode = ($args['mode'] ?? '') === 'off' ? 'off' : 'on';
                if ($mode === 'off') {
                    return ['mode' => 'off'];
                }
                $minutes = (int) ($args['minutes'] ?? 30);
                if ($minutes < 5 || $minutes > 1440) {
                    throw new RuntimeException('Maintenance can last 5 minutes to 24 hours.');
                }
                $message = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($args['message'] ?? ''))) ?? '');
                return ['mode' => 'on', 'minutes' => $minutes, 'message' => mb_substr($message, 0, 200)];
        }
        throw new RuntimeException('Unknown remote action.');
    }

    /**
     * Open commands for a heartbeat reply, signed; marks them as sent.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forReply(int $websiteId, string $secret): array
    {
        if ($secret === '') {
            return [];
        }
        $commands = [];
        $sent = [];
        foreach ($this->repo->openCommands($websiteId, utc_now()->format('Y-m-d H:i:s')) as $row) {
            $expires = (int) strtotime($row['expires_at'] . ' UTC');
            $commands[] = [
                'id'        => (int) $row['id'],
                'action'    => (string) $row['action'],
                'args_json' => (string) $row['args'],
                'expires'   => $expires,
                'sig'       => self::sign($secret, $websiteId, (int) $row['id'], (string) $row['action'], (string) $row['args'], $expires),
            ];
            if ($row['status'] === 'pending') {
                $sent[] = (int) $row['id'];
            }
        }
        if ($sent !== []) {
            $this->repo->markCommandsSent($sent);
        }
        return $commands;
    }

    /**
     * Store a "command_result" event from the plugin on its command.
     *
     * @param array<string, mixed> $data Event data: command_id, action, ok, message, details.
     */
    public function recordResult(int $websiteId, array $data): void
    {
        $id = (int) ($data['command_id'] ?? 0);
        $command = $id > 0 ? $this->repo->command($id) : null;
        if ($command === null || (int) $command['website_id'] !== $websiteId || in_array($command['status'], ['done', 'failed'], true)) {
            return;
        }
        $ok = !empty($data['ok']);
        $details = is_array($data['details'] ?? null) ? $data['details'] : [];
        $this->repo->finishCommand($id, $ok ? 'done' : 'failed', (string) json_encode([
            'message' => mb_substr((string) ($data['message'] ?? ''), 0, 500),
            'details' => $details,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

        if ($command['action'] === 'maintenance') {
            $args = json_decode((string) $command['args'], true);
            $on = is_array($args) && ($args['mode'] ?? '') === 'on';
            $until = is_numeric($details['until'] ?? null) ? (int) $details['until'] : null;
            if ($ok) {
                $this->repo->update($websiteId, ['maintenance_until' => $on && $until !== null ? gmdate('Y-m-d H:i:s', $until) : null]);
            } elseif ($on) {
                $this->repo->update($websiteId, ['maintenance_until' => null]);
            }
        }
    }

    /**
     * Recent commands for the website page, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $websiteId, int $limit = 10): array
    {
        $this->repo->expireCommands(utc_now()->format('Y-m-d H:i:s'));
        return array_map(static function (array $row): array {
            $args = json_decode((string) $row['args'], true);
            $result = json_decode((string) ($row['result'] ?? ''), true);
            return [
                'id'           => (int) $row['id'],
                'action'       => (string) $row['action'],
                'label'        => self::ACTIONS[$row['action']] ?? (string) $row['action'],
                'args'         => is_array($args) ? $args : [],
                'status'       => (string) $row['status'],
                'status_label' => self::STATUS_LABELS[$row['status']] ?? (string) $row['status'],
                'message'      => is_array($result) ? (string) ($result['message'] ?? '') : '',
                'details'      => is_array($result) && is_array($result['details'] ?? null) ? $result['details'] : [],
                'requested_by' => $row['requested_by_name'] ?? null,
                'created_ago'  => time_ago($row['created_at']),
                'finished_ago' => !empty($row['finished_at']) ? time_ago($row['finished_at']) : null,
            ];
        }, $this->repo->recentCommands($websiteId, $limit));
    }
}
