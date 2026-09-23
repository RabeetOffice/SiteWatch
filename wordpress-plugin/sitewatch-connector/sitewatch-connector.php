<?php
/**
 * Plugin Name:       SiteWatch Connector
 * Plugin URI:        https://github.com/RabeetOffice/SiteWatch
 * Description:       Connects this WordPress site to SiteWatch monitoring: real causes of fatal errors (without turning on debug), a daily health and security report, and a log of plugin, theme and admin changes.
 * Version:           1.3.0
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            SiteWatch
 * License:           GPL-2.0-or-later
 * Text Domain:       sitewatch-connector
 *
 * Everything is pushed from this site to your SiteWatch server over HTTPS, signed with a secret that only this
 * site and SiteWatch know. The plugin adds no public endpoints and shows nothing to visitors.
 */

defined('ABSPATH') || exit;

define('SITEWATCH_CONNECTOR_VERSION', '1.3.0');
define('SITEWATCH_CONNECTOR_FILE', __FILE__);
define('SITEWATCH_CONNECTOR_DIR', __DIR__);
define('SITEWATCH_CONNECTOR_BASENAME', plugin_basename(__FILE__));

require_once __DIR__ . '/includes/class-sitewatch-connector-client.php';
require_once __DIR__ . '/includes/class-sitewatch-connector-errors.php';
require_once __DIR__ . '/includes/class-sitewatch-connector-pulse.php';
require_once __DIR__ . '/includes/class-sitewatch-connector-insights.php';
require_once __DIR__ . '/includes/class-sitewatch-connector-updater.php';
require_once __DIR__ . '/includes/class-sitewatch-connector-health.php';
require_once __DIR__ . '/includes/class-sitewatch-connector-activity.php';
require_once __DIR__ . '/includes/class-sitewatch-connector-files.php';
require_once __DIR__ . '/includes/class-sitewatch-connector-admin.php';

/**
 * Wires the plugin together: cron heartbeat, activity hooks, admin screen and the early error loader.
 */
final class SiteWatch_Connector
{
    const CRON_HOOK = 'sitewatch_connector_heartbeat';
    const CRON_SCHEDULE = 'sitewatch_connector_5min';
    const LOADER_FILE = 'sitewatch-connector-loader.php';

    public static function init()
    {
        // Normally already started by the must-use loader; this covers sites where it could not be installed.
        SiteWatch_Connector_Errors::init();
        SiteWatch_Connector_Pulse::init();
        SiteWatch_Connector_Insights::init();
        SiteWatch_Connector_Updater::init();

        add_filter('cron_schedules', array(__CLASS__, 'cron_schedules'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'heartbeat'));
        add_action('init', array(__CLASS__, 'ensure_cron'));
        add_action('admin_init', array(__CLASS__, 'ensure_loader'));

        SiteWatch_Connector_Activity::init();
        SiteWatch_Connector_Files::init();
        if (is_admin()) {
            SiteWatch_Connector_Admin::init();
        }
    }

    public static function cron_schedules($schedules)
    {
        $schedules[self::CRON_SCHEDULE] = array('interval' => 300, 'display' => 'Every 5 minutes (SiteWatch)');
        return $schedules;
    }

    public static function ensure_cron()
    {
        if (SiteWatch_Connector_Client::config() !== null && !wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK);
        }
    }

    /**
     * Send the regular report: status, queued activity, captured errors and (daily or on request) the full snapshot.
     *
     * @return array Result from SiteWatch_Connector_Client::send().
     */
    public static function heartbeat($force_snapshot = false)
    {
        if (SiteWatch_Connector_Client::config() === null) {
            return array('ok' => false, 'error' => 'Not connected.');
        }
        // Keep the error/pulse folder and the early loader in place (cron runs without a signed-in user).
        SiteWatch_Connector_Errors::ensure_dir();
        if (!self::loader_installed()) {
            self::install_loader();
        }

        $state = SiteWatch_Connector_Client::state();
        $snapshot_due = $force_snapshot || !empty($state['want_snapshot'])
            || empty($state['last_snapshot']) || (time() - (int) $state['last_snapshot']) > DAY_IN_SECONDS;

        SiteWatch_Connector_Files::check();
        SiteWatch_Connector_Activity::collect_login_failures();
        $events = SiteWatch_Connector_Activity::queued(100);
        $errors = SiteWatch_Connector_Errors::pending_events();

        $extra = array('events' => array_merge($errors, $events));
        if ($snapshot_due) {
            $extra['snapshot'] = SiteWatch_Connector_Health::snapshot();
            $extra['snapshot']['performance'] = SiteWatch_Connector_Insights::performance_summary();
            $extra['snapshot']['php_warnings'] = SiteWatch_Connector_Insights::warnings_summary();
        }

        $result = SiteWatch_Connector_Client::send('heartbeat', $extra, 20);
        if ($result['ok']) {
            SiteWatch_Connector_Activity::forget(wp_list_pluck($events, 'uid'));
            SiteWatch_Connector_Errors::mark_sent($errors);
            if ($snapshot_due) {
                SiteWatch_Connector_Client::update_state(array('last_snapshot' => time(), 'want_snapshot' => false));
                SiteWatch_Connector_Insights::reset();
            }
        }
        return $result;
    }

    /**
     * Copy the must-use loader so fatal errors are captured even when another plugin crashes while loading.
     *
     * @return bool Whether the loader is in place.
     */
    public static function install_loader()
    {
        if (!defined('WPMU_PLUGIN_DIR')) {
            return false;
        }
        $target = WPMU_PLUGIN_DIR . '/' . self::LOADER_FILE;
        if (!is_dir(WPMU_PLUGIN_DIR) && !wp_mkdir_p(WPMU_PLUGIN_DIR)) {
            return false;
        }
        $source = __DIR__ . '/includes/mu-loader.php';
        return (bool) @copy($source, $target);
    }

    public static function loader_installed()
    {
        if (!defined('WPMU_PLUGIN_DIR')) {
            return false;
        }
        $path = WPMU_PLUGIN_DIR . '/' . self::LOADER_FILE;
        if (!is_readable($path)) {
            return false;
        }
        $data = get_file_data($path, array('Version' => 'Version'));
        return isset($data['Version']) && $data['Version'] === SITEWATCH_CONNECTOR_VERSION;
    }

    public static function ensure_loader()
    {
        if (!self::loader_installed() && current_user_can('activate_plugins')) {
            self::install_loader();
        }
    }

    public static function remove_loader()
    {
        if (defined('WPMU_PLUGIN_DIR') && is_file(WPMU_PLUGIN_DIR . '/' . self::LOADER_FILE)) {
            @unlink(WPMU_PLUGIN_DIR . '/' . self::LOADER_FILE);
        }
    }

    public static function activate()
    {
        self::install_loader();
        if (SiteWatch_Connector_Client::config() !== null && !wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK);
        }
    }

    public static function deactivate()
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        self::remove_loader();
        // Tell SiteWatch, so the dashboard shows the plugin as switched off rather than silently stale.
        if (SiteWatch_Connector_Client::config() !== null) {
            SiteWatch_Connector_Client::send('deactivated', array(), 5);
        }
    }
}

register_activation_hook(__FILE__, array('SiteWatch_Connector', 'activate'));
register_deactivation_hook(__FILE__, array('SiteWatch_Connector', 'deactivate'));
SiteWatch_Connector::init();
