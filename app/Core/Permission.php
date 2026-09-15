<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Permission catalogue for role-based access control.
 *
 * Every signed-in user can open the dashboard, the website list and website details, and manage their own
 * profile. Everything else is granted through the user's role. The built-in Administrator role holds the
 * wildcard "*", which always means every permission (including ones added in future versions).
 */
final class Permission
{
    public const ALL = '*';

    /** @var array<string, array<string, array{0: string, 1: string}>> group => [key => [label, description]] */
    public const GROUPS = [
        'Websites' => [
            'websites.manage' => ['Add & edit websites', 'Add, edit and import websites, pause or resume monitoring and change intervals'],
            'websites.check'  => ['Run manual checks', 'Use "Check Now" on one or many websites'],
            'websites.delete' => ['Delete websites', 'Permanently remove websites and all of their monitoring history'],
        ],
        'Monitoring data' => [
            'incidents.view' => ['View incidents', 'Incident history, the dashboard incident panel and incident exports'],
            'reports.view'   => ['View reports', 'Uptime reports, performance, response times and report exports'],
        ],
        'Domains & hosting' => [
            'domains.view'   => ['View domain & hosting info', 'Domain age, WHOIS registration, expiry dates and hosting location'],
            'domains.lookup' => ['Run domain lookups', 'Refresh stored domain data and look up any domain name'],
        ],
        'System' => [
            'notifications.manage' => ['Manage notifications', 'Email and Telegram channels, alert rules, test messages and the delivery log'],
            'settings.manage'      => ['Manage settings', 'General and monitoring settings'],
            'activity.view'        => ['View activity log', 'Every administrative and monitoring event, including sign-ins and IP addresses'],
        ],
        'Team' => [
            'users.manage' => ['Manage users', 'Add, edit, deactivate and delete user accounts'],
            'roles.manage' => ['Manage roles', 'Create roles and choose their permissions'],
        ],
    ];

    /** @return array<int, string> */
    public static function keys(): array
    {
        $keys = [];
        foreach (self::GROUPS as $permissions) {
            foreach (array_keys($permissions) as $key) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    public static function exists(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    public static function label(string $key): string
    {
        foreach (self::GROUPS as $permissions) {
            if (isset($permissions[$key])) {
                return $permissions[$key][0];
            }
        }
        return $key;
    }

    /**
     * Decode a role's stored JSON permission list, dropping unknown keys.
     *
     * @return array<int, string>
     */
    public static function decode(?string $json): array
    {
        $list = json_decode((string) $json, true);
        if (!is_array($list)) {
            return [];
        }
        if (in_array(self::ALL, $list, true)) {
            return [self::ALL];
        }
        return self::normalize($list);
    }

    /**
     * Known, unique permission keys in catalogue order.
     *
     * @param array<int|string, mixed> $list
     * @return array<int, string>
     */
    public static function normalize(array $list): array
    {
        $list = array_values($list);
        return array_values(array_filter(self::keys(), static fn (string $key): bool => in_array($key, $list, true)));
    }

    /**
     * Concrete permission keys (the wildcard expanded).
     *
     * @param array<int, string> $granted
     * @return array<int, string>
     */
    public static function expand(array $granted): array
    {
        return in_array(self::ALL, $granted, true) ? self::keys() : self::normalize($granted);
    }

    /** @param array<int, string> $granted */
    public static function allows(array $granted, string $permission): bool
    {
        return in_array(self::ALL, $granted, true) || in_array($permission, $granted, true);
    }

    /**
     * True when $holder has every permission in $subject. Used to stop users from granting, or taking over
     * accounts with, more access than they hold themselves.
     *
     * @param array<int, string> $holder
     * @param array<int, string> $subject
     */
    public static function covers(array $holder, array $subject): bool
    {
        if (in_array(self::ALL, $holder, true)) {
            return true;
        }
        if (in_array(self::ALL, $subject, true)) {
            return false;
        }
        return array_diff(self::normalize($subject), $holder) === [];
    }
}
