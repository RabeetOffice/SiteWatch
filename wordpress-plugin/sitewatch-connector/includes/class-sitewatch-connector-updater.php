<?php
/**
 * Updates SiteWatch Connector from your SiteWatch server instead of WordPress.org.
 *
 * SiteWatch answers every report with the newest plugin version it ships and a signed, short-lived download link.
 * This class hands that to WordPress as an ordinary plugin update, so:
 *   - the update appears on the Plugins screen and "Update now" works as for any plugin;
 *   - when SiteWatch allows automatic updates (or you click "Update now" in SiteWatch), the plugin installs it itself
 *     through WordPress's automatic updater, which puts the site in maintenance mode, checks the site still loads and
 *     restores the previous version if the update causes a fatal error (WordPress 6.6+).
 */

defined('ABSPATH') || exit;

final class SiteWatch_Connector_Updater
{
    const CRON_HOOK = 'sitewatch_connector_self_update';
    const SLUG = 'sitewatch-connector';
    /** Minimum time between two automatic attempts at the same version. */
    const RETRY_AFTER = 21600;

    /** Set while an update requested from SiteWatch runs, so it proceeds even with automatic updates off. */
    private static $forced = false;

    public static function init()
    {
        add_filter('pre_set_site_transient_update_plugins', array(__CLASS__, 'inject'));
        add_filter('site_transient_update_plugins', array(__CLASS__, 'inject'));
        add_filter('plugins_api', array(__CLASS__, 'details'), 10, 3);
        add_filter('auto_update_plugin', array(__CLASS__, 'allow_auto_update'), 10, 2);
        add_action(self::CRON_HOOK, array(__CLASS__, 'run'));
        add_action('upgrader_process_complete', array(__CLASS__, 'after_upgrade'), 10, 2);
    }

    /**
     * The update SiteWatch offers, or null when this version is current.
     *
     * @return array{version: string, package: string, requires: string, requires_php: string, tested: string, url: string}|null
     */
    public static function offer()
    {
        $state = SiteWatch_Connector_Client::state();
        $offer = isset($state['update']) && is_array($state['update']) ? $state['update'] : null;
        if ($offer === null || empty($offer['version']) || empty($offer['package'])) {
            return null;
        }
        return version_compare((string) $offer['version'], SITEWATCH_CONNECTOR_VERSION, '>') ? $offer : null;
    }

    /**
     * Remember what SiteWatch said about updates in its reply, and start an update when it should happen now.
     *
     * @param array<string, mixed> $data Reply data from SiteWatch.
     */
    public static function handle_reply(array $data)
    {
        $offer = isset($data['plugin_update']) && is_array($data['plugin_update']) ? $data['plugin_update'] : null;
        SiteWatch_Connector_Client::update_state(array(
            'update'      => $offer,
            'auto_update' => !empty($data['auto_update']),
        ));
        if ($offer === null || !version_compare((string) $offer['version'], SITEWATCH_CONNECTOR_VERSION, '>')) {
            return;
        }

        $state = SiteWatch_Connector_Client::state();
        $now = !empty($data['update_now']);
        $recentAttempt = isset($state['last_update']['version'], $state['last_update']['at'])
            && $state['last_update']['version'] === $offer['version']
            && time() - (int) $state['last_update']['at'] < ($now ? 10 * MINUTE_IN_SECONDS : self::RETRY_AFTER);
        if (($now || !empty($data['auto_update'])) && !$recentAttempt && !wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + 30, self::CRON_HOOK, array($now));
        }
    }

    /** Add the SiteWatch offer to WordPress's list of plugin updates. */
    public static function inject($transient)
    {
        $offer = self::offer();
        if (!is_object($transient)) {
            return $transient;
        }
        if ($offer === null) {
            if (isset($transient->response[SITEWATCH_CONNECTOR_BASENAME])) {
                unset($transient->response[SITEWATCH_CONNECTOR_BASENAME]);
            }
            return $transient;
        }
        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = array();
        }
        $transient->response[SITEWATCH_CONNECTOR_BASENAME] = self::item($offer);
        return $transient;
    }

    private static function item(array $offer)
    {
        return (object) array(
            'id'           => 'sitewatch-connector',
            'slug'         => self::SLUG,
            'plugin'       => SITEWATCH_CONNECTOR_BASENAME,
            'new_version'  => (string) $offer['version'],
            'package'      => (string) $offer['package'],
            'url'          => isset($offer['url']) ? (string) $offer['url'] : '',
            'requires'     => isset($offer['requires']) ? (string) $offer['requires'] : '5.2',
            'requires_php' => isset($offer['requires_php']) ? (string) $offer['requires_php'] : '7.2',
            'tested'       => isset($offer['tested']) ? (string) $offer['tested'] : '',
            'icons'        => array(),
            'banners'      => array(),
        );
    }

    /** "View details" on the Plugins screen. */
    public static function details($result, $action, $args)
    {
        if ($action !== 'plugin_information' || !isset($args->slug) || $args->slug !== self::SLUG) {
            return $result;
        }
        $offer = self::offer();
        return (object) array(
            'name'          => 'SiteWatch Connector',
            'slug'          => self::SLUG,
            'version'       => $offer ? (string) $offer['version'] : SITEWATCH_CONNECTOR_VERSION,
            'author'        => 'SiteWatch',
            'requires'      => $offer && isset($offer['requires']) ? $offer['requires'] : '5.2',
            'requires_php'  => $offer && isset($offer['requires_php']) ? $offer['requires_php'] : '7.2',
            'download_link' => $offer ? (string) $offer['package'] : '',
            'sections'      => array(
                'description' => 'Connects this site to your SiteWatch monitoring. Updates come from your own SiteWatch server.',
                'changelog'   => $offer && !empty($offer['notes']) ? wp_kses_post((string) $offer['notes']) : 'See the release notes in SiteWatch (System → Updates).',
            ),
        );
    }

    public static function allow_auto_update($update, $item)
    {
        if (!is_object($item) || !isset($item->plugin) || $item->plugin !== SITEWATCH_CONNECTOR_BASENAME) {
            return $update;
        }
        if (self::$forced) {
            return true;
        }
        $state = SiteWatch_Connector_Client::state();
        return !empty($state['auto_update']) ? true : $update;
    }

    /**
     * Install the offered version now (runs from WP-Cron a few seconds after SiteWatch's reply).
     *
     * @param bool $forced Requested with "Update now" in SiteWatch.
     */
    public static function run($forced = false)
    {
        $offer = self::offer();
        if ($offer === null) {
            return;
        }
        SiteWatch_Connector_Client::update_state(array('last_update' => array(
            'version' => (string) $offer['version'], 'at' => time(), 'result' => 'started', 'message' => '',
        )));

        require_once ABSPATH . 'wp-admin/includes/admin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        if (!class_exists('WP_Automatic_Updater')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
        }

        $from = SITEWATCH_CONNECTOR_VERSION;
        $updater = new WP_Automatic_Updater();
        $message = '';
        if ($updater->is_disabled()) {
            $result = false;
            $message = 'Automatic updates are disabled on this site (AUTOMATIC_UPDATER_DISABLED or a filter). Update from the Plugins screen instead.';
        } else {
            self::$forced = (bool) $forced;
            // WordPress refuses to run two updates at once; this lock is the one its own updater uses.
            if (!WP_Upgrader::create_lock('auto_updater')) {
                $result = false;
                $message = 'Another update is running. SiteWatch will try again later.';
            } else {
                $result = $updater->update('plugin', self::item($offer));
                WP_Upgrader::release_lock('auto_updater');
            }
            self::$forced = false;
        }

        if ($result === true || (is_object($result) && !is_wp_error($result))) {
            $outcome = 'updated';
            $message = 'Updated from ' . $from . ' to ' . $offer['version'] . '.';
        } elseif (is_wp_error($result)) {
            $outcome = 'failed';
            $message = implode(' ', $result->get_error_messages());
        } else {
            $outcome = 'failed';
            if ($message === '') {
                $message = 'WordPress did not install the update. Common causes: plugin files are not writable by the web server, the site is a version-control checkout, or FTP credentials are required.';
            }
        }

        SiteWatch_Connector_Client::update_state(array('last_update' => array(
            'version' => (string) $offer['version'], 'at' => time(), 'result' => $outcome, 'message' => substr($message, 0, 250),
        )));
        SiteWatch_Connector_Activity::record(
            $outcome === 'updated' ? 'connector_updated' : 'connector_update_failed',
            $outcome === 'updated' ? 'info' : 'warning',
            $outcome === 'updated' ? 'SiteWatch Connector updated to ' . $offer['version'] : 'SiteWatch Connector update to ' . $offer['version'] . ' failed',
            array('from' => $from, 'to' => (string) $offer['version'], 'message' => $message)
        );
        // Report the new version (or the failure) straight away; the next request runs the new code.
        wp_schedule_single_event(time() + 15, SiteWatch_Connector::CRON_HOOK);
    }

    /** New plugin files include a new must-use loader. */
    public static function after_upgrade($upgrader, $extra)
    {
        $plugins = isset($extra['plugins']) ? (array) $extra['plugins'] : (isset($extra['plugin']) ? array($extra['plugin']) : array());
        if (isset($extra['type']) && $extra['type'] === 'plugin' && in_array(SITEWATCH_CONNECTOR_BASENAME, $plugins, true)) {
            SiteWatch_Connector::install_loader();
        }
    }
}
