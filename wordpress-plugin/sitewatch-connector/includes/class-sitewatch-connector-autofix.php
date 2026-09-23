<?php
/**
 * Auto-fix: deactivate a plugin that keeps crashing the site (off until an administrator switches it on under
 * Settings → SiteWatch), and remember the version each plugin had before its last update so SiteWatch can offer a
 * rollback. Design and limits: docs/sitewatch-connector.md, section 7 (v4).
 *
 * Two steps, because a fatal error is a bad moment to change anything:
 *   1. While PHP is dying, consider() only writes wp-content/sitewatch-connector/autofix.php when the same error from one
 *      plugin's folder happened THRESHOLD times within WINDOW seconds.
 *   2. On the next request, apply() runs from the must-use loader before normal plugins load and removes the flagged
 *      plugin from the active_plugins option, so that request already loads without it.
 * The event is reported (critical) once the plugin has loaded far enough to send it.
 */

defined('ABSPATH') || exit;

if (!class_exists('SiteWatch_Connector_Autofix')) {

    final class SiteWatch_Connector_Autofix
    {
        /** {enabled: bool, protected: string[] plugin files}; autoloaded so it can be read during a fatal error. */
        const OPTION = 'sitewatch_connector_autofix';
        /** Plugin file => time it was last deactivated automatically, plus events waiting to be reported. */
        const LOG_OPTION = 'sitewatch_connector_autofix_log';
        /** Plugin file => {previous, current, updated_at}: the version before the last update. */
        const VERSIONS_OPTION = 'sitewatch_connector_versions';
        const THRESHOLD = 3;
        const WINDOW = 600;
        const COOLDOWN = 86400;

        /** Set while a rollback runs, so the version it replaces is not recorded as "previous". */
        public static $rolling_back = false;

        /** @return array{enabled: bool, protected: string[]} */
        public static function settings()
        {
            $saved = function_exists('get_option') ? get_option(self::OPTION) : null;
            $saved = is_array($saved) ? $saved : array();
            return array(
                'enabled'   => !empty($saved['enabled']),
                'protected' => isset($saved['protected']) && is_array($saved['protected']) ? array_values(array_map('strval', $saved['protected'])) : array(),
            );
        }

        public static function save_settings($enabled, array $protected)
        {
            update_option(self::OPTION, array('enabled' => (bool) $enabled, 'protected' => array_values(array_unique(array_map('strval', $protected)))), true);
        }

        public static function init()
        {
            add_filter('upgrader_pre_install', array(__CLASS__, 'before_update'), 10, 2);
            add_action('init', array(__CLASS__, 'report_pending'), 20);
        }

        // --------------------------------------------------------------
        // Step 1: during a fatal error
        // --------------------------------------------------------------

        /**
         * Called by the error capture with the error's recent occurrence times. Writes the flag file only.
         *
         * @param array $component From SiteWatch_Connector_Errors::component().
         * @param int[] $times     Unix times of recent occurrences of this error.
         */
        public static function consider(array $component, array $times, array $error)
        {
            if (!isset($component['type'], $component['slug']) || $component['type'] !== 'plugin' || $component['slug'] === '' || $component['slug'] === 'sitewatch-connector') {
                return;
            }
            $recent = array_filter($times, function ($t) {
                return (int) $t >= time() - SiteWatch_Connector_Autofix::WINDOW;
            });
            if (count($recent) < self::THRESHOLD || !self::settings()['enabled']) {
                return;
            }
            $raw = SiteWatch_Connector_Errors::read_data('autofix');
            $flags = $raw !== null ? json_decode($raw, true) : array();
            $flags = is_array($flags) ? $flags : array();
            if (isset($flags[$component['slug']])) {
                return;
            }
            $flags[$component['slug']] = array(
                'at'      => time(),
                'count'   => count($recent),
                'name'    => isset($component['name']) ? (string) $component['name'] : $component['slug'],
                'version' => isset($component['version']) ? (string) $component['version'] : '',
                'error'   => $error,
            );
            SiteWatch_Connector_Errors::write_data('autofix', json_encode($flags));
        }

        private static function flag_path()
        {
            return SiteWatch_Connector_Errors::data_path('autofix');
        }

        // --------------------------------------------------------------
        // Step 2: early in the next request (must-use loader)
        // --------------------------------------------------------------

        public static function apply()
        {
            $path = self::flag_path();
            if (!is_file($path)) {
                return; // the usual case: one stat per request
            }
            $flags = json_decode(SiteWatch_Connector_Errors::unguard((string) @file_get_contents($path)), true);
            @unlink($path);
            if (!is_array($flags) || !self::settings()['enabled']) {
                return;
            }
            $settings = self::settings();
            $log = get_option(self::LOG_OPTION);
            $log = is_array($log) ? $log : array('last' => array(), 'pending' => array());
            $active = (array) get_option('active_plugins', array());
            $changed = false;

            foreach ($flags as $slug => $flag) {
                $file = self::active_file((string) $slug, $active);
                if ($file === null || $file === 'sitewatch-connector/sitewatch-connector.php' || in_array($file, $settings['protected'], true)) {
                    continue;
                }
                if (isset($log['last'][$file]) && time() - (int) $log['last'][$file] < self::COOLDOWN) {
                    continue; // re-activated by a person after an earlier auto-fix: leave it to them
                }
                $active = array_values(array_diff($active, array($file)));
                $changed = true;
                $log['last'][$file] = time();
                $log['pending'][] = array('file' => $file) + (is_array($flag) ? $flag : array());
            }
            if ($changed) {
                update_option('active_plugins', $active);
                update_option(self::LOG_OPTION, $log, false);
            }
        }

        /** The active_plugins entry for a plugin folder (or single-file plugin), or null. */
        private static function active_file($slug, array $active)
        {
            foreach ($active as $file) {
                $file = (string) $file;
                if (strpos($file, $slug . '/') === 0 || $file === $slug . '.php') {
                    return $file;
                }
            }
            return null;
        }

        /** Report deactivations once the plugin is fully loaded (critical: sent at the end of this request). */
        public static function report_pending()
        {
            $log = get_option(self::LOG_OPTION);
            if (!is_array($log) || empty($log['pending'])) {
                return;
            }
            foreach ($log['pending'] as $p) {
                $error = isset($p['error']) && is_array($p['error']) ? $p['error'] : array();
                SiteWatch_Connector_Activity::record(
                    'plugin_auto_deactivated',
                    'critical',
                    'Deactivated automatically: ' . (isset($p['name']) ? $p['name'] : $p['file']) . (!empty($p['version']) ? ' ' . $p['version'] : ''),
                    array(
                        'file'    => (string) $p['file'],
                        'name'    => isset($p['name']) ? (string) $p['name'] : '',
                        'version' => isset($p['version']) ? (string) $p['version'] : '',
                        'count'   => isset($p['count']) ? (int) $p['count'] : 0,
                        'error'   => $error,
                        'by'      => 'SiteWatch auto-fix',
                    )
                );
            }
            $log['pending'] = array();
            update_option(self::LOG_OPTION, $log, false);
            // The plugin list changed: send a fresh health report with the next heartbeat.
            SiteWatch_Connector_Client::update_state(array('want_snapshot' => true));
        }

        /** Plugins deactivated automatically in the last 7 days, newest first (settings screen). */
        public static function recent()
        {
            $log = get_option(self::LOG_OPTION);
            $last = is_array($log) && isset($log['last']) && is_array($log['last']) ? $log['last'] : array();
            arsort($last);
            return array_filter($last, function ($t) {
                return (int) $t >= time() - 7 * 86400;
            });
        }

        // --------------------------------------------------------------
        // Versions before updates (for rollback)
        // --------------------------------------------------------------

        /** upgrader_pre_install: runs before WordPress replaces a plugin's files. */
        public static function before_update($response, $hook_extra)
        {
            if (self::$rolling_back || !is_array($hook_extra) || empty($hook_extra['plugin']) || (isset($hook_extra['action']) && $hook_extra['action'] !== 'update')) {
                return $response;
            }
            $file = (string) $hook_extra['plugin'];
            if (!function_exists('get_plugin_data')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $path = WP_PLUGIN_DIR . '/' . $file;
            if (is_readable($path)) {
                $data = get_plugin_data($path, false, false);
                $versions = get_option(self::VERSIONS_OPTION);
                $versions = is_array($versions) ? $versions : array();
                $versions[$file] = array('previous' => (string) $data['Version'], 'updated_at' => time());
                update_option(self::VERSIONS_OPTION, $versions, false);
            }
            return $response;
        }

        /** @return array{previous: string, updated_at: int}|null */
        public static function previous($file)
        {
            $versions = get_option(self::VERSIONS_OPTION);
            return is_array($versions) && isset($versions[$file]['previous']) && $versions[$file]['previous'] !== '' ? $versions[$file] : null;
        }

        public static function forget_previous($file)
        {
            $versions = get_option(self::VERSIONS_OPTION);
            if (is_array($versions) && isset($versions[$file])) {
                unset($versions[$file]);
                update_option(self::VERSIONS_OPTION, $versions, false);
            }
        }

        /** Sent in every report. */
        public static function status()
        {
            $settings = self::settings();
            return array('enabled' => $settings['enabled'], 'protected' => $settings['protected']);
        }
    }
}
