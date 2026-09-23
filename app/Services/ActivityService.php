<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Request;
use App\Repositories\ActivityRepository;

/**
 * Convenience wrapper for recording administrative activity from web requests.
 */
final class ActivityService
{
    public const ACTION_LABELS = [
        'website.added'      => 'Website Added',
        'connector.connected'    => 'Plugin Connected',
        'connector.disconnected' => 'Plugin Disconnected',
        'connector.key_created'  => 'Plugin Key Created',
        'connector.revoked'      => 'Plugin Key Revoked',
        'website.edited'     => 'Website Edited',
        'website.deleted'    => 'Website Deleted',
        'website.imported'   => 'Websites Imported',
        'website.warning'    => 'Website Warning',
        'monitoring.paused'  => 'Monitoring Paused',
        'monitoring.resumed' => 'Monitoring Resumed',
        'incident.opened'    => 'Incident Opened',
        'incident.resolved'  => 'Incident Resolved',
        'ssl.warning'        => 'SSL Warning',
        'ssl.renewed'        => 'SSL Renewed',
        'settings.changed'   => 'Settings Changed',
        'profile.updated'    => 'Profile Updated',
        'password.changed'   => 'Password Changed',
        'auth.login'         => 'Signed In',
        'auth.logout'        => 'Signed Out',
        'notification.test'  => 'Test Notification',
        'check.manual'       => 'Manual Check',
        'bulk.action'        => 'Bulk Action',
        'user.created'       => 'User Added',
        'user.updated'       => 'User Updated',
        'user.deleted'       => 'User Deleted',
        'role.created'       => 'Role Created',
        'role.updated'       => 'Role Updated',
        'role.deleted'       => 'Role Deleted',
        'system.updated'     => 'Database Updated',
    ];

    public const ACTION_TONES = [
        'user.created'       => 'primary',
        'user.deleted'       => 'danger',
        'role.created'       => 'primary',
        'role.deleted'       => 'danger',
        'incident.opened'    => 'danger',
        'incident.resolved'  => 'success',
        'ssl.warning'        => 'warning',
        'website.warning'    => 'warning',
        'monitoring.paused'  => 'muted',
        'website.deleted'    => 'danger',
        'website.added'      => 'primary',
        'website.imported'   => 'primary',
        'ssl.renewed'        => 'success',
    ];

    /** @param array<string, mixed>|null $context */
    public static function log(string $action, string $description, ?int $websiteId = null, ?array $context = null): void
    {
        try {
            $repo = new ActivityRepository(App::db());
            $userId = App::isCli() ? null : App::auth()->id();
            $ip = App::isCli() ? null : Request::ip();
            $repo->log($action, $description, $websiteId, $userId, $context, $ip);
        } catch (\Throwable $e) {
            App::logger('app')->warning('Activity log failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        $context = null;
        if (!empty($row['context'])) {
            $decoded = json_decode((string) $row['context'], true);
            $context = is_array($decoded) ? $decoded : null;
        }
        return [
            'id'           => (int) $row['id'],
            'action'       => $row['action'],
            'action_label' => self::ACTION_LABELS[$row['action']] ?? ucwords(str_replace(['.', '_'], ' ', (string) $row['action'])),
            'tone'         => self::ACTION_TONES[$row['action']] ?? 'muted',
            'description'  => $row['description'],
            'user_name'    => $row['user_name'] ?? null,
            'website_id'   => isset($row['website_id']) ? (int) $row['website_id'] : null,
            'website_name' => $row['website_name'] ?? null,
            'domain'       => $row['domain'] ?? null,
            'context'      => $context,
            'ip'           => $row['ip'] ?? null,
            'created_at'   => $row['created_at'],
            'created_label' => format_datetime($row['created_at']),
            'created_ago'  => time_ago($row['created_at']),
        ];
    }
}
