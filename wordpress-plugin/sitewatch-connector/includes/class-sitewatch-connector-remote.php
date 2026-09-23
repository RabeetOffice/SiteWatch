<?php
/**
 * Remote actions: a short allowlist of things SiteWatch may ask this site to do, each switched off until an
 * administrator allows it under SiteWatch in the WordPress admin menu.
 *
 * Commands arrive in SiteWatch's reply to the signed heartbeat (the plugin opens no endpoint). Each one is checked
 * before it runs: HMAC signature with this site's secret, site ID, expiry, whether the action is allowed here, whether
 * it already ran (re-sent commands only repeat their result), and strict argument validation. Results go straight
 * back to SiteWatch as "command_result" events. Design and threat model: docs/sitewatch-connector.md, section 7.
 */

defined('ABSPATH') || exit;

final class SiteWatch_Connector_Remote
{
    /** {enabled: bool, actions: string[]} */
    const OPTION = 'sitewatch_connector_remote';
    /** Command ID => {action, at, ok, message, details}, newest last (replay protection and the settings screen). */
    const DONE_OPTION = 'sitewatch_connector_commands';
    /** {until: int, message: string}; autoloaded because the front end checks it on every request. */
    const MAINTENANCE_OPTION = 'sitewatch_connector_maintenance';
    /** Checked commands waiting to run in their own background request: id => command. */
    const QUEUE_OPTION = 'sitewatch_connector_command_queue';
    const CRON_HOOK = 'sitewatch_connector_run_command';
    /** Installs and downloads can take minutes, so they do not run inside the report request. */
    const LONG = array('update_plugins', 'update_themes', 'update_core', 'rollback_plugin');
    /** Seconds a long command may run (where the host lets PHP extend its time limit). */
    const LONG_TIME_LIMIT = 900;
    const KEEP_DONE = 100;
    const MAX_AHEAD = 7200;
    const MAX_UPDATES = 20;

    const ACTIONS = array(
        'clear_cache'       => 'Clear caches (page cache plugins and the object cache)',
        'deactivate_plugin' => 'Deactivate a plugin',
        'activate_plugin'   => 'Activate a plugin again',
        'update_plugins'    => 'Install plugin updates offered by WordPress.org',
        'maintenance'       => 'Switch a maintenance page on (5 minutes to 24 hours) and off',
        'rollback_plugin'   => 'Roll a WordPress.org plugin back to the version it had before its last update',
        'update_themes'     => 'Install theme updates offered by WordPress.org',
        'update_core'       => 'Install the WordPress update offered by WordPress.org (with WordPress\'s own rollback on failure)',
        'backup'            => 'Start a backup with UpdraftPlus or BackWPup (whichever is installed)',
    );

    public static function init()
    {
        add_action('template_redirect', array(__CLASS__, 'maintenance_gate'), 0);
        add_action(self::CRON_HOOK, array(__CLASS__, 'run_queued'));
    }

    /** @return array{enabled: bool, actions: string[]} */
    public static function settings()
    {
        $saved = get_option(self::OPTION);
        $saved = is_array($saved) ? $saved : array();
        $actions = isset($saved['actions']) && is_array($saved['actions']) ? array_values(array_intersect(array_keys(self::ACTIONS), $saved['actions'])) : array();
        return array('enabled' => !empty($saved['enabled']), 'actions' => $actions);
    }

    public static function save_settings($enabled, array $actions)
    {
        update_option(self::OPTION, array('enabled' => (bool) $enabled, 'actions' => array_values(array_intersect(array_keys(self::ACTIONS), $actions))), false);
    }

    /** Sent in every report, so SiteWatch only offers what this site allows. */
    public static function status()
    {
        $settings = self::settings();
        return array('enabled' => $settings['enabled'], 'actions' => $settings['enabled'] ? $settings['actions'] : array());
    }

    /** @return int|null End of the maintenance page, or null when it is off. */
    public static function maintenance_until()
    {
        $m = get_option(self::MAINTENANCE_OPTION);
        return is_array($m) && isset($m['until']) && (int) $m['until'] > time() ? (int) $m['until'] : null;
    }

    // --------------------------------------------------------------
    // Running commands
    // --------------------------------------------------------------

    /**
     * Check and run the commands from a heartbeat reply, then report the results.
     *
     * @param array $commands Reply "commands" list.
     */
    public static function handle(array $commands)
    {
        $config = SiteWatch_Connector_Client::config();
        if ($config === null) {
            return;
        }
        $done = get_option(self::DONE_OPTION, array());
        $done = is_array($done) ? $done : array();
        $queue = get_option(self::QUEUE_OPTION, array());
        $queue = is_array($queue) ? $queue : array();
        $queued_now = false;
        $events = array();
        $plugins_changed = false;

        foreach (array_slice($commands, 0, 10) as $command) {
            if (!is_array($command) || !isset($command['id'], $command['action'], $command['args_json'], $command['expires'], $command['sig'])) {
                continue;
            }
            $id = (string) $command['id'];
            if (!preg_match('/^\d{1,18}$/', $id)) {
                continue;
            }
            if (isset($done[$id])) {
                $events[] = self::event($id, $done[$id]); // SiteWatch did not get the result yet: send it again
                continue;
            }
            if (isset($queue[$id])) {
                continue; // already waiting for its background run
            }
            $result = self::check($command, $config);
            if ($result !== null && !empty($result['forged'])) {
                // Not stored as done: a forged command must not block the genuine one with the same ID.
                unset($result['forged']);
                $result['action'] = (string) $command['action'];
                $events[] = self::event($id, $result);
                continue;
            }
            if ($result === null && in_array($command['action'], self::LONG, true)) {
                // Checked; runs in a request of its own (see run_queued()), and reports its result from there.
                $queue[$id] = $command;
                $queued_now = true;
                continue;
            }
            if ($result === null) {
                $result = self::execute((string) $command['action'], json_decode((string) $command['args_json'], true));
                if (in_array($command['action'], array('deactivate_plugin', 'activate_plugin', 'update_plugins', 'rollback_plugin', 'update_themes', 'update_core'), true)) {
                    $plugins_changed = true;
                }
            }
            $result['action'] = (string) $command['action'];
            $result['at'] = time();
            $done[$id] = $result;
            $events[] = self::event($id, $result);
        }

        if ($queued_now) {
            update_option(self::QUEUE_OPTION, $queue, false);
            self::schedule_queue();
        }
        if ($events === array()) {
            return;
        }
        self::finish($done, $events, $plugins_changed);
    }

    /** Store results and send them to SiteWatch (with a fresh snapshot when plugins or themes changed). */
    private static function finish(array $done, array $events, $plugins_changed)
    {
        if (count($done) > self::KEEP_DONE) {
            $done = array_slice($done, -self::KEEP_DONE, null, true);
        }
        update_option(self::DONE_OPTION, $done, false);

        $extra = array('events' => $events);
        if ($plugins_changed) {
            // The plugin list changed, so send a fresh snapshot with the results.
            $extra['snapshot'] = SiteWatch_Connector_Health::snapshot();
        }
        $sent = SiteWatch_Connector_Client::send('event', $extra, 20);
        if (!$sent['ok'] && $plugins_changed) {
            SiteWatch_Connector_Client::update_state(array('want_snapshot' => true));
        }
        // Unsent results are repeated when SiteWatch re-sends the command.
    }

    private static function schedule_queue()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time(), self::CRON_HOOK);
        }
    }

    /**
     * WP-Cron: run the oldest queued long command in this request, with a longer time limit, then report it. Further
     * commands get a request each.
     */
    public static function run_queued()
    {
        $queue = get_option(self::QUEUE_OPTION, array());
        if (!is_array($queue) || $queue === array() || SiteWatch_Connector_Client::config() === null) {
            return;
        }
        $id = (string) key($queue);
        $command = $queue[$id];
        unset($queue[$id]); // taken before it runs, so a second cron request does not run it too
        update_option(self::QUEUE_OPTION, $queue, false);

        if ((int) $command['expires'] < time()) {
            $result = self::fail('Not run: the command expired while it waited for its turn.');
        } else {
            if (function_exists('set_time_limit') && (int) ini_get('max_execution_time') > 0 && (int) ini_get('max_execution_time') < self::LONG_TIME_LIMIT) {
                @set_time_limit(self::LONG_TIME_LIMIT);
            }
            if (function_exists('ignore_user_abort')) {
                @ignore_user_abort(true);
            }
            $result = self::execute((string) $command['action'], json_decode((string) $command['args_json'], true));
        }
        $result['action'] = (string) $command['action'];
        $result['at'] = time();
        $done = get_option(self::DONE_OPTION, array());
        $done = is_array($done) ? $done : array();
        $done[$id] = $result;
        if ($queue !== array()) {
            self::schedule_queue();
        }
        self::finish($done, array(self::event($id, $result)), true);
    }

    /**
     * Why a command must not run, as a failed result, or null when it may run.
     *
     * @return array|null
     */
    private static function check(array $command, array $config)
    {
        $action = (string) $command['action'];
        $expires = (int) $command['expires'];
        $expected = hash_hmac('sha256', 'command|' . (int) $config['site_id'] . '|' . (string) $command['id'] . '|' . $action . '|' . (string) $command['args_json'] . '|' . $expires, (string) $config['secret']);
        if (!is_string($command['sig']) || !hash_equals($expected, $command['sig'])) {
            return self::fail('Rejected: the command signature is not valid for this site.') + array('forged' => true);
        }
        if ($expires < time() || $expires > time() + self::MAX_AHEAD) {
            return self::fail('Rejected: the command has expired.');
        }
        if (!array_key_exists($action, self::ACTIONS)) {
            return self::fail('Rejected: this plugin version does not know the action "' . substr(sanitize_key($action), 0, 40) . '".');
        }
        $settings = self::settings();
        if (!$settings['enabled'] || !in_array($action, $settings['actions'], true)) {
            return self::fail('Refused: this action is not allowed under SiteWatch → Remote actions in the WordPress admin menu.');
        }
        return null;
    }

    private static function execute($action, $args)
    {
        $args = is_array($args) ? $args : array();
        try {
            switch ($action) {
                case 'clear_cache':
                    return self::clear_cache();
                case 'deactivate_plugin':
                    return self::deactivate_plugin(isset($args['plugin']) ? (string) $args['plugin'] : '');
                case 'activate_plugin':
                    return self::activate_plugin(isset($args['plugin']) ? (string) $args['plugin'] : '');
                case 'update_plugins':
                    return self::update_plugins(isset($args['plugins']) && is_array($args['plugins']) ? $args['plugins'] : array());
                case 'maintenance':
                    return self::maintenance($args);
                case 'update_themes':
                    return self::update_themes(isset($args['themes']) && is_array($args['themes']) ? $args['themes'] : array());
                case 'update_core':
                    return self::update_core(isset($args['version']) ? (string) $args['version'] : '');
                case 'backup':
                    return self::backup(isset($args['plugin']) ? (string) $args['plugin'] : '');
                case 'rollback_plugin':
                    return self::rollback_plugin(isset($args['plugin']) ? (string) $args['plugin'] : '', isset($args['version']) ? (string) $args['version'] : '');
            }
        } catch (Throwable $e) {
            return self::fail('Failed: ' . substr($e->getMessage(), 0, 200));
        }
        return self::fail('Unknown action.');
    }

    private static function fail($message, array $details = array())
    {
        return array('ok' => false, 'message' => $message, 'details' => $details);
    }

    private static function ok($message, array $details = array())
    {
        return array('ok' => true, 'message' => $message, 'details' => $details);
    }

    private static function event($id, array $result)
    {
        return array(
            'uid'      => 'command-result-' . $id,
            'type'     => 'command_result',
            'severity' => $result['ok'] ? 'info' : 'warning',
            'title'    => substr((string) $result['message'], 0, 250),
            'data'     => array('command_id' => (int) $id, 'action' => $result['action'], 'ok' => (bool) $result['ok'], 'message' => (string) $result['message'], 'details' => $result['details']),
            'at'       => isset($result['at']) ? (int) $result['at'] : time(),
        );
    }

    // --------------------------------------------------------------
    // Actions
    // --------------------------------------------------------------

    private static function clear_cache()
    {
        $cleared = array();
        if (has_action('litespeed_purge_all')) {
            do_action('litespeed_purge_all');
            $cleared[] = 'LiteSpeed Cache';
        }
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
            $cleared[] = 'WP Rocket';
        }
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
            $cleared[] = 'W3 Total Cache';
        }
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
            $cleared[] = 'WP Super Cache';
        }
        if (has_action('wpfc_clear_all_cache')) {
            do_action('wpfc_clear_all_cache', true);
            $cleared[] = 'WP Fastest Cache';
        }
        if (function_exists('sg_cachepress_purge_cache')) {
            sg_cachepress_purge_cache();
            $cleared[] = 'SiteGround Optimizer';
        }
        if (class_exists('autoptimizeCache') && method_exists('autoptimizeCache', 'clearall')) {
            autoptimizeCache::clearall();
            $cleared[] = 'Autoptimize';
        }
        if (wp_cache_flush()) {
            $cleared[] = wp_using_ext_object_cache() ? 'Object cache' : 'WordPress runtime cache';
        }
        return self::ok('Caches cleared: ' . implode(', ', $cleared) . '.', array('cleared' => $cleared));
    }

    /** A plugin file known to WordPress, other than this plugin, or an error message. */
    private static function plugin_file($file)
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();
        if ($file === '' || !isset($plugins[$file])) {
            return array(null, 'Refused: no plugin "' . substr(sanitize_text_field($file), 0, 120) . '" is installed.');
        }
        if ($file === SITEWATCH_CONNECTOR_BASENAME) {
            return array(null, 'Refused: SiteWatch Connector does not change itself through remote actions.');
        }
        return array($plugins[$file], '');
    }

    private static function deactivate_plugin($file)
    {
        list($data, $error) = self::plugin_file($file);
        if ($data === null) {
            return self::fail($error);
        }
        if (!is_plugin_active($file)) {
            return self::ok($data['Name'] . ' was already inactive.', array('plugin' => $file));
        }
        $network = is_multisite() && is_plugin_active_for_network($file);
        deactivate_plugins($file, false, $network);
        if (is_plugin_active($file)) {
            return self::fail($data['Name'] . ' could not be deactivated.', array('plugin' => $file));
        }
        return self::ok($data['Name'] . ' ' . $data['Version'] . ' deactivated' . ($network ? ' network-wide' : '') . '.', array('plugin' => $file, 'name' => $data['Name']));
    }

    private static function activate_plugin($file)
    {
        list($data, $error) = self::plugin_file($file);
        if ($data === null) {
            return self::fail($error);
        }
        if (is_plugin_active($file)) {
            return self::ok($data['Name'] . ' was already active.', array('plugin' => $file));
        }
        // activate_plugin() loads the plugin in a sandbox and refuses it if it produces output or errors.
        $result = activate_plugin($file, '', false, false);
        if (is_wp_error($result)) {
            return self::fail($data['Name'] . ' was not activated: ' . $result->get_error_message(), array('plugin' => $file));
        }
        return self::ok($data['Name'] . ' ' . $data['Version'] . ' activated.', array('plugin' => $file, 'name' => $data['Name']));
    }

    /**
     * Install updates WordPress.org offers for these plugins, through WordPress's automatic updater (maintenance
     * mode during the update, rollback of a broken update on WordPress 6.6+).
     */
    private static function update_plugins(array $files)
    {
        foreach (array('admin.php', 'class-wp-upgrader.php', 'update.php', 'plugin.php') as $include) {
            require_once ABSPATH . 'wp-admin/includes/' . $include;
        }
        if (!class_exists('WP_Automatic_Updater')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
        }
        $files = array_slice(array_values(array_unique(array_filter(array_map('strval', $files)))), 0, self::MAX_UPDATES);
        if ($files === array()) {
            return self::fail('Refused: no plugins were named.');
        }
        wp_update_plugins(); // ask WordPress.org for the current list first
        $available = get_site_transient('update_plugins');
        $installed = get_plugins();

        $updater = new WP_Automatic_Updater();
        if ($updater->is_disabled()) {
            return self::fail('Automatic updates are disabled on this site (AUTOMATIC_UPDATER_DISABLED or a filter).');
        }
        if (!WP_Upgrader::create_lock('auto_updater')) {
            return self::fail('Another update is running. Try again in a few minutes.');
        }
        $allow = function ($update, $item) use ($files) {
            return is_object($item) && isset($item->plugin) && in_array($item->plugin, $files, true) ? true : $update;
        };
        add_filter('auto_update_plugin', $allow, 1000, 2);

        $details = array();
        $updated = 0;
        foreach ($files as $file) {
            $name = isset($installed[$file]['Name']) ? $installed[$file]['Name'] : $file;
            if (!isset($installed[$file]) || $file === SITEWATCH_CONNECTOR_BASENAME) {
                $details[] = array('plugin' => $file, 'name' => $name, 'ok' => false, 'message' => 'Not an installed plugin that can be updated remotely.');
                continue;
            }
            $item = isset($available->response[$file]) ? $available->response[$file] : null;
            if (!is_object($item) || empty($item->package)) {
                $details[] = array('plugin' => $file, 'name' => $name, 'ok' => false, 'message' => 'No update offered by WordPress.org.');
                continue;
            }
            $from = $installed[$file]['Version'];
            $result = $updater->update('plugin', $item);
            $ok = $result === true || (is_object($result) && !is_wp_error($result));
            $updated += $ok ? 1 : 0;
            $details[] = array(
                'plugin' => $file, 'name' => $name, 'ok' => $ok, 'from' => $from, 'to' => (string) $item->new_version,
                'message' => $ok ? 'Updated' : (is_wp_error($result) ? implode(' ', $result->get_error_messages()) : 'WordPress did not install the update (files not writable, or rolled back).'),
            );
        }

        remove_filter('auto_update_plugin', $allow, 1000);
        WP_Upgrader::release_lock('auto_updater');
        wp_clean_plugins_cache();
        $message = sprintf('%d of %d plugin update(s) installed.', $updated, count($files));
        return $updated === count($files) ? self::ok($message, array('plugins' => $details)) : self::fail($message, array('plugins' => $details));
    }

    /**
     * Install the version a plugin had before its last update, from WordPress.org. Only that recorded version is
     * accepted, and the package address is built here: SiteWatch cannot choose what is installed.
     */
    private static function rollback_plugin($file, $version)
    {
        list($data, $error) = self::plugin_file($file);
        if ($data === null) {
            return self::fail($error);
        }
        $previous = SiteWatch_Connector_Autofix::previous($file);
        if ($previous === null) {
            return self::fail('Refused: no earlier version of ' . $data['Name'] . ' is recorded (versions are recorded from plugin 1.5.0 on, at each update).');
        }
        if ($version !== $previous['previous'] || !preg_match('/^[0-9][0-9A-Za-z.\-]{0,30}$/', $version)) {
            return self::fail('Refused: ' . $data['Name'] . ' can only be rolled back to ' . $previous['previous'] . ', the version before its last update.');
        }
        $slug = strpos($file, '/') !== false ? dirname($file) : basename($file, '.php');
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug)) {
            return self::fail('Refused: only WordPress.org plugins can be rolled back.');
        }
        foreach (array('admin.php', 'class-wp-upgrader.php', 'update.php', 'plugin.php') as $include) {
            require_once ABSPATH . 'wp-admin/includes/' . $include;
        }
        if (!class_exists('WP_Automatic_Updater')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
        }
        $item = (object) array(
            'id' => 'w.org/plugins/' . $slug, 'slug' => $slug, 'plugin' => $file, 'new_version' => $version,
            'package' => 'https://downloads.wordpress.org/plugin/' . rawurlencode($slug) . '.' . rawurlencode($version) . '.zip',
            'url' => 'https://wordpress.org/plugins/' . $slug . '/',
        );
        // Plugin_Upgrader reads the package from WordPress's update list, so offer the old version there for this run.
        $offer = function ($transient) use ($file, $item) {
            // The list may not exist (e.g. right after another update); the upgrader then reports "up to date".
            $transient = is_object($transient) ? $transient : (object) array('last_checked' => 0, 'checked' => array(), 'response' => array());
            if (!isset($transient->response) || !is_array($transient->response)) {
                $transient->response = array();
            }
            $transient->response[$file] = $item;
            return $transient;
        };
        $allow = function ($update, $candidate) use ($file) {
            return is_object($candidate) && isset($candidate->plugin) && $candidate->plugin === $file ? true : $update;
        };
        $updater = new WP_Automatic_Updater();
        if ($updater->is_disabled()) {
            return self::fail('Automatic updates are disabled on this site (AUTOMATIC_UPDATER_DISABLED or a filter).');
        }
        if (!WP_Upgrader::create_lock('auto_updater')) {
            return self::fail('Another update is running. Try again in a few minutes.');
        }
        add_filter('site_transient_update_plugins', $offer, 1000);
        add_filter('auto_update_plugin', $allow, 1000, 2);
        SiteWatch_Connector_Autofix::$rolling_back = true;
        $from = $data['Version'];
        $result = $updater->update('plugin', $item);
        SiteWatch_Connector_Autofix::$rolling_back = false;
        remove_filter('site_transient_update_plugins', $offer, 1000);
        remove_filter('auto_update_plugin', $allow, 1000);
        WP_Upgrader::release_lock('auto_updater');
        wp_clean_plugins_cache();

        $now = get_plugins();
        $installed = isset($now[$file]['Version']) ? $now[$file]['Version'] : '';
        if ($installed !== $version) {
            $why = is_wp_error($result) ? implode(' ', $result->get_error_messages()) : 'WordPress did not install it (package not found on WordPress.org, files not writable, or the update was rolled back).';
            return self::fail($data['Name'] . ' was not rolled back: ' . $why, array('plugin' => $file, 'from' => $from, 'to' => $version));
        }
        SiteWatch_Connector_Autofix::forget_previous($file); // a second click must not flip back to the broken version
        delete_site_transient('update_plugins');
        return self::ok($data['Name'] . ' rolled back from ' . $from . ' to ' . $version . '.', array('plugin' => $file, 'from' => $from, 'to' => $version, 'active' => is_plugin_active($file)));
    }

    /** WordPress's updater classes, loaded on demand (commands run from WP-Cron, where wp-admin is not loaded). */
    private static function load_updater()
    {
        foreach (array('admin.php', 'class-wp-upgrader.php', 'update.php', 'plugin.php', 'theme.php') as $include) {
            require_once ABSPATH . 'wp-admin/includes/' . $include;
        }
        if (!class_exists('WP_Automatic_Updater')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
        }
        $updater = new WP_Automatic_Updater();
        if ($updater->is_disabled()) {
            return 'Automatic updates are disabled on this site (AUTOMATIC_UPDATER_DISABLED or a filter).';
        }
        if (!WP_Upgrader::create_lock('auto_updater')) {
            return 'Another update is running. Try again in a few minutes.';
        }
        return $updater;
    }

    /** Install updates WordPress.org offers for these themes (stylesheet folder names). */
    private static function update_themes(array $slugs)
    {
        $slugs = array_slice(array_values(array_unique(array_filter(array_map('strval', $slugs)))), 0, self::MAX_UPDATES);
        if ($slugs === array()) {
            return self::fail('Refused: no themes were named.');
        }
        require_once ABSPATH . 'wp-admin/includes/update.php';
        wp_update_themes();
        $available = get_site_transient('update_themes');
        $updater = self::load_updater();
        if (is_string($updater)) {
            return self::fail($updater);
        }
        $allow = function ($update, $item) use ($slugs) {
            return is_object($item) && isset($item->theme) && in_array($item->theme, $slugs, true) ? true : $update;
        };
        add_filter('auto_update_theme', $allow, 1000, 2);
        $details = array();
        $updated = 0;
        foreach ($slugs as $slug) {
            $theme = wp_get_theme($slug);
            if (!$theme->exists()) {
                $details[] = array('theme' => $slug, 'name' => $slug, 'ok' => false, 'message' => 'Not an installed theme.');
                continue;
            }
            $offer = isset($available->response[$slug]) ? (object) $available->response[$slug] : null;
            if ($offer === null || empty($offer->package)) {
                $details[] = array('theme' => $slug, 'name' => $theme->get('Name'), 'ok' => false, 'message' => 'No update offered by WordPress.org.');
                continue;
            }
            $from = $theme->get('Version');
            $result = $updater->update('theme', $offer);
            $ok = $result === true || (is_object($result) && !is_wp_error($result));
            $updated += $ok ? 1 : 0;
            $details[] = array('theme' => $slug, 'name' => $theme->get('Name'), 'ok' => $ok, 'from' => $from, 'to' => (string) $offer->new_version,
                'message' => $ok ? 'Updated' : (is_wp_error($result) ? implode(' ', $result->get_error_messages()) : 'WordPress did not install the update.'));
        }
        remove_filter('auto_update_theme', $allow, 1000);
        WP_Upgrader::release_lock('auto_updater');
        wp_clean_themes_cache();
        $message = sprintf('%d of %d theme update(s) installed.', $updated, count($slugs));
        return $updated === count($slugs) ? self::ok($message, array('themes' => $details)) : self::fail($message, array('themes' => $details));
    }

    /**
     * Install the WordPress version WordPress.org offers, only if it is the version SiteWatch saw in the last health
     * report. Uses WordPress's automatic updater, which checks the files and restores the old version when the
     * update fails. Major versions are allowed for this one run only.
     */
    private static function update_core($version)
    {
        if (!preg_match('/^\d+\.\d+(\.\d+)?$/', $version)) {
            return self::fail('Refused: not a WordPress version number.');
        }
        require_once ABSPATH . 'wp-admin/includes/update.php';
        wp_version_check(array(), true);
        $offer = null;
        foreach ((array) get_core_updates() as $candidate) {
            if (is_object($candidate) && isset($candidate->response, $candidate->current) && $candidate->response === 'upgrade' && $candidate->current === $version) {
                $offer = $candidate;
                break;
            }
        }
        $from = self::installed_wp_version();
        if ($offer === null) {
            return self::fail(version_compare($from, $version, '>=') ? 'WordPress is already at ' . $from . '.' : 'WordPress.org does not offer ' . $version . ' for this site now.');
        }
        $updater = self::load_updater();
        if (is_string($updater)) {
            return self::fail($updater);
        }
        $allow = function () {
            return true;
        };
        foreach (array('auto_update_core', 'allow_major_auto_core_updates', 'allow_minor_auto_core_updates') as $filter) {
            add_filter($filter, $allow, 1000);
        }
        $result = $updater->update('core', $offer);
        foreach (array('auto_update_core', 'allow_major_auto_core_updates', 'allow_minor_auto_core_updates') as $filter) {
            remove_filter($filter, $allow, 1000);
        }
        WP_Upgrader::release_lock('auto_updater');
        $now = self::installed_wp_version();
        if ($now !== $version) {
            $why = is_wp_error($result) ? implode(' ', $result->get_error_messages()) : 'WordPress did not install it (files not writable, a version-control checkout, or the update was rolled back).';
            return self::fail('WordPress was not updated: ' . $why, array('from' => $from, 'to' => $version));
        }
        return self::ok('WordPress updated from ' . $from . ' to ' . $version . '.', array('from' => $from, 'to' => $version));
    }

    /** The version in wp-includes/version.php now (the global keeps the value this request started with). */
    private static function installed_wp_version()
    {
        $wp_version = '';
        include ABSPATH . WPINC . '/version.php';
        return (string) $wp_version;
    }

    /**
     * Start a backup the way the backup plugin's own button does, in a request of its own; the outcome shows in the
     * next health report. $plugin is "updraftplus" or "backwpup"; empty picks the one that is installed.
     */
    private static function backup($plugin)
    {
        $updraft = class_exists('UpdraftPlus') && has_action('updraft_backupnow_backup_all');
        $backwpup = class_exists('BackWPup_Job') && class_exists('BackWPup_Option');
        if ($plugin === 'backwpup' || ($plugin === '' && !$updraft && $backwpup)) {
            return self::backup_backwpup();
        }
        if (!$updraft) {
            return self::fail($plugin === 'updraftplus' || !$backwpup ? 'UpdraftPlus is not installed and active on this site.' : 'No supported backup plugin is active.');
        }
        if (wp_next_scheduled('updraft_backupnow_backup_all', array(array('always_keep' => false)))) {
            return self::ok('An UpdraftPlus backup is already about to start.');
        }
        wp_schedule_single_event(time(), 'updraft_backupnow_backup_all', array(array('always_keep' => false)));
        if (function_exists('spawn_cron')) {
            spawn_cron();
        }
        // The next heartbeat carries a fresh health report with UpdraftPlus's result.
        SiteWatch_Connector_Client::update_state(array('want_snapshot' => true));
        return self::ok('UpdraftPlus backup started (files and database). Its result appears here with a later report.');
    }

    /** BackWPup: run its first backup job now, through the same request BackWPup's "Run now" link makes. */
    private static function backup_backwpup()
    {
        if (!class_exists('BackWPup_Job') || !class_exists('BackWPup_Option')) {
            return self::fail('BackWPup is not installed and active on this site.');
        }
        $job = SiteWatch_Connector_Health::backwpup_job();
        if (!$job) {
            return self::fail('BackWPup has no backup job. Create one in BackWPup first.');
        }
        if (BackWPup_Job::get_working_data()) {
            return self::ok('A BackWPup job is already running.');
        }
        BackWPup_Job::get_jobrun_url('runnow', $job);
        SiteWatch_Connector_Client::update_state(array('want_snapshot' => true));
        return self::ok(sprintf('BackWPup job "%s" started. Its result appears here with a later report.', BackWPup_Option::get($job, 'name')), array('job' => $job));
    }

    private static function maintenance(array $args)
    {
        $mode = isset($args['mode']) ? (string) $args['mode'] : '';
        if ($mode === 'off') {
            $was = self::maintenance_until();
            delete_option(self::MAINTENANCE_OPTION);
            return self::ok($was !== null ? 'Maintenance page switched off.' : 'The maintenance page was already off.', array('until' => null));
        }
        if ($mode !== 'on') {
            return self::fail('Refused: maintenance mode must be "on" or "off".');
        }
        $minutes = max(5, min(1440, isset($args['minutes']) ? (int) $args['minutes'] : 30));
        $message = isset($args['message']) ? substr(sanitize_text_field((string) $args['message']), 0, 200) : '';
        $until = time() + $minutes * 60;
        update_option(self::MAINTENANCE_OPTION, array('until' => $until, 'message' => $message), true);
        return self::ok(sprintf('Maintenance page on for %d minutes.', $minutes), array('until' => $until));
    }

    // --------------------------------------------------------------
    // Maintenance page
    // --------------------------------------------------------------

    /** Visitors get a 503 page; signed-in editors and administrators keep using the site. */
    public static function maintenance_gate()
    {
        $m = get_option(self::MAINTENANCE_OPTION);
        if (!is_array($m) || empty($m['until'])) {
            return;
        }
        if ((int) $m['until'] <= time()) {
            delete_option(self::MAINTENANCE_OPTION); // ends on time even when SiteWatch cannot be reached
            return;
        }
        if (is_user_logged_in() && current_user_can('edit_posts')) {
            return;
        }
        $retry = max(60, (int) $m['until'] - time());
        status_header(503);
        nocache_headers();
        header('Retry-After: ' . $retry);
        header('Content-Type: text/html; charset=utf-8');
        $text = isset($m['message']) && $m['message'] !== '' ? (string) $m['message'] : 'We are carrying out scheduled maintenance and will be back shortly.';
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
            . '<title>' . esc_html(get_bloginfo('name')) . ' – Briefly unavailable for scheduled maintenance</title>'
            . '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f6f7f7;color:#1d2327;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:24px}main{max-width:520px;text-align:center}h1{font-size:24px}</style>'
            . '</head><body><main><h1>Briefly unavailable for scheduled maintenance</h1><p>' . esc_html($text) . '</p></main></body></html>';
        exit;
    }

    /** Last remote actions for the settings screen, newest first. */
    public static function recent($limit = 20)
    {
        $done = get_option(self::DONE_OPTION, array());
        return is_array($done) ? array_slice(array_reverse($done, true), 0, (int) $limit, true) : array();
    }
}
