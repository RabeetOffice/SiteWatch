<?php
/**
 * Records changes that explain "what happened before it broke" and early signs of a break-in: plugin, theme and core
 * changes, administrator sign-ins, new administrators, and edits to settings attackers like to change.
 *
 * Events are queued in an option and delivered with the next heartbeat. Critical ones (a new administrator, a
 * changed site address) are sent at the end of the request so SiteWatch can alert within seconds.
 */

defined('ABSPATH') || exit;

final class SiteWatch_Connector_Activity
{
    const QUEUE_OPTION = 'sitewatch_connector_queue';
    const LOGIN_OPTION = 'sitewatch_connector_logins';
    const MAX_QUEUE = 200;
    const WATCHED_OPTIONS = array('siteurl', 'home', 'admin_email', 'users_can_register', 'default_role');

    private static $flush_now = false;

    public static function init()
    {
        add_action('activated_plugin', array(__CLASS__, 'plugin_activated'), 10, 2);
        add_action('deactivated_plugin', array(__CLASS__, 'plugin_deactivated'), 10, 2);
        add_action('deleted_plugin', array(__CLASS__, 'plugin_deleted'), 10, 2);
        add_action('upgrader_process_complete', array(__CLASS__, 'upgraded'), 10, 2);
        add_action('switch_theme', array(__CLASS__, 'theme_switched'), 10, 3);
        add_action('_core_updated_successfully', array(__CLASS__, 'core_updated'));
        add_action('wp_login', array(__CLASS__, 'login'), 10, 2);
        add_action('wp_login_failed', array(__CLASS__, 'login_failed'));
        // add_user_role fires both when an account is created with a role and when a role is changed or added.
        add_action('add_user_role', array(__CLASS__, 'role_added'), 10, 2);
        add_action('delete_user', array(__CLASS__, 'user_deleted'));
        add_action('updated_option', array(__CLASS__, 'option_updated'), 10, 3);
        add_action('shutdown', array(__CLASS__, 'maybe_flush'));
    }

    /**
     * Add an event to the queue.
     *
     * @param string $severity info | warning | critical
     */
    public static function record($type, $severity, $title, array $data = array())
    {
        $queue = get_option(self::QUEUE_OPTION, array());
        if (!is_array($queue)) {
            $queue = array();
        }
        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        $data['by'] = $user && $user->ID ? $user->user_login : (defined('DOING_CRON') && DOING_CRON ? 'WP-Cron' : (defined('WP_CLI') && WP_CLI ? 'WP-CLI' : 'system'));
        $queue[] = array(
            'uid'      => function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : md5(uniqid('', true)),
            'type'     => (string) $type,
            'severity' => (string) $severity,
            'title'    => substr((string) $title, 0, 250),
            'data'     => $data,
            'at'       => time(),
        );
        if (count($queue) > self::MAX_QUEUE) {
            $queue = array_slice($queue, -self::MAX_QUEUE);
        }
        update_option(self::QUEUE_OPTION, $queue, false);
        if ($severity === 'critical') {
            self::$flush_now = true;
        }
    }

    /** @return array<int, array<string, mixed>> Oldest first. */
    public static function queued($limit)
    {
        $queue = get_option(self::QUEUE_OPTION, array());
        return is_array($queue) ? array_slice($queue, 0, (int) $limit) : array();
    }

    public static function forget(array $uids)
    {
        if ($uids === array()) {
            return;
        }
        $queue = get_option(self::QUEUE_OPTION, array());
        if (!is_array($queue)) {
            return;
        }
        $queue = array_values(array_filter($queue, function ($event) use ($uids) {
            return !in_array($event['uid'], $uids, true);
        }));
        update_option(self::QUEUE_OPTION, $queue, false);
    }

    /** Send critical events straight away instead of waiting for the next heartbeat. */
    public static function maybe_flush()
    {
        if (!self::$flush_now || SiteWatch_Connector_Client::config() === null) {
            return;
        }
        self::$flush_now = false;
        $events = array_values(array_filter(self::queued(self::MAX_QUEUE), function ($event) {
            return $event['severity'] === 'critical';
        }));
        if ($events === array()) {
            return;
        }
        $result = SiteWatch_Connector_Client::send('event', array('events' => $events), 8);
        if ($result['ok']) {
            self::forget(wp_list_pluck($events, 'uid'));
        }
    }

    // --------------------------------------------------------------
    // Plugins, themes and core
    // --------------------------------------------------------------

    private static function plugin_info($file)
    {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $path = WP_PLUGIN_DIR . '/' . $file;
        $data = is_readable($path) ? get_plugin_data($path, false, false) : array();
        return array(
            'file'    => (string) $file,
            'name'    => !empty($data['Name']) ? (string) $data['Name'] : (string) $file,
            'version' => isset($data['Version']) ? (string) $data['Version'] : '',
        );
    }

    public static function plugin_activated($file, $network = false)
    {
        $info = self::plugin_info($file);
        self::record('plugin_activated', 'info', 'Plugin activated: ' . $info['name'] . ' ' . $info['version'], $info + array('network' => (bool) $network));
    }

    public static function plugin_deactivated($file, $network = false)
    {
        if ($file === SITEWATCH_CONNECTOR_BASENAME) {
            return; // Reported separately by the deactivation hook.
        }
        $info = self::plugin_info($file);
        self::record('plugin_deactivated', 'info', 'Plugin deactivated: ' . $info['name'], $info + array('network' => (bool) $network));
    }

    public static function plugin_deleted($file, $deleted)
    {
        if ($deleted) {
            self::record('plugin_deleted', 'info', 'Plugin deleted: ' . $file, array('file' => (string) $file));
        }
    }

    public static function upgraded($upgrader, $extra)
    {
        if (!is_array($extra) || empty($extra['type'])) {
            return;
        }
        $action = isset($extra['action']) ? (string) $extra['action'] : 'update';
        if ($extra['type'] === 'plugin') {
            $files = !empty($extra['plugins']) ? (array) $extra['plugins'] : (!empty($extra['plugin']) ? array($extra['plugin']) : array());
            if ($action === 'install' && $files === array() && is_object($upgrader) && method_exists($upgrader, 'plugin_info')) {
                $file = $upgrader->plugin_info();
                if ($file) {
                    $files = array($file);
                }
            }
            foreach ($files as $file) {
                if ($file === SITEWATCH_CONNECTOR_BASENAME) {
                    continue; // Self-updates are reported by SiteWatch_Connector_Updater.
                }
                $info = self::plugin_info($file);
                $verb = $action === 'install' ? 'installed' : 'updated';
                self::record('plugin_' . $verb, 'info', 'Plugin ' . $verb . ': ' . $info['name'] . ' ' . $info['version'], $info);
            }
        } elseif ($extra['type'] === 'theme') {
            $slugs = !empty($extra['themes']) ? (array) $extra['themes'] : (!empty($extra['theme']) ? array($extra['theme']) : array());
            foreach ($slugs as $slug) {
                $theme = wp_get_theme($slug);
                $verb = $action === 'install' ? 'installed' : 'updated';
                self::record('theme_' . $verb, 'info', 'Theme ' . $verb . ': ' . $theme->get('Name') . ' ' . $theme->get('Version'),
                    array('slug' => (string) $slug, 'name' => (string) $theme->get('Name'), 'version' => (string) $theme->get('Version')));
            }
        } elseif ($extra['type'] === 'translation') {
            return;
        }
    }

    public static function theme_switched($name, $new_theme, $old_theme = null)
    {
        self::record('theme_switched', 'warning', 'Theme switched to ' . $name, array(
            'name'     => (string) $name,
            'version'  => is_object($new_theme) ? (string) $new_theme->get('Version') : '',
            'previous' => is_object($old_theme) ? (string) $old_theme->get('Name') : '',
        ));
    }

    public static function core_updated($version)
    {
        self::record('core_updated', 'info', 'WordPress updated to ' . $version, array('version' => (string) $version));
    }

    // --------------------------------------------------------------
    // Users and sign-ins
    // --------------------------------------------------------------

    private static function ip()
    {
        return isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 45) : '';
    }

    private static function is_admin_user($user)
    {
        return $user instanceof WP_User && (in_array('administrator', (array) $user->roles, true) || is_super_admin($user->ID));
    }

    public static function login($login, $user)
    {
        if (!self::is_admin_user($user)) {
            return;
        }
        update_user_meta($user->ID, 'sitewatch_last_login', time());
        self::record('admin_login', 'info', 'Administrator signed in: ' . $login, array('login' => (string) $login, 'ip' => self::ip()));
    }

    /** Failed sign-ins are counted, not queued one by one, so a password-guessing attack cannot flood the queue. */
    public static function login_failed($username)
    {
        $stats = get_option(self::LOGIN_OPTION, array());
        if (!is_array($stats)) {
            $stats = array();
        }
        $stats['count'] = isset($stats['count']) ? (int) $stats['count'] + 1 : 1;
        $ip = self::ip();
        if (!isset($stats['ips']) || !is_array($stats['ips'])) {
            $stats['ips'] = array();
        }
        if ($ip !== '' && (isset($stats['ips'][$ip]) || count($stats['ips']) < 20)) {
            $stats['ips'][$ip] = isset($stats['ips'][$ip]) ? (int) $stats['ips'][$ip] + 1 : 1;
        }
        if (!isset($stats['users']) || !is_array($stats['users'])) {
            $stats['users'] = array();
        }
        $username = substr(sanitize_user((string) $username), 0, 60);
        if ($username !== '' && (isset($stats['users'][$username]) || count($stats['users']) < 10)) {
            $stats['users'][$username] = isset($stats['users'][$username]) ? (int) $stats['users'][$username] + 1 : 1;
        }
        update_option(self::LOGIN_OPTION, $stats, false);
    }

    /** Turn the failed sign-in counter into one event per heartbeat. */
    public static function collect_login_failures()
    {
        $stats = get_option(self::LOGIN_OPTION, array());
        if (!is_array($stats) || empty($stats['count'])) {
            return;
        }
        delete_option(self::LOGIN_OPTION);
        arsort($stats['ips']);
        $count = (int) $stats['count'];
        self::record('login_failures', $count >= 50 ? 'warning' : 'info', $count . ' failed sign-in attempt(s)', array(
            'count' => $count,
            'ips'   => array_slice($stats['ips'], 0, 10, true),
            'users' => isset($stats['users']) ? $stats['users'] : array(),
        ));
    }

    public static function role_added($user_id, $role)
    {
        if ($role !== 'administrator') {
            return;
        }
        $user = get_userdata($user_id);
        $login = $user ? $user->user_login : '#' . $user_id;
        // An account registered a moment ago is a new administrator; otherwise an existing account was promoted.
        $new = $user && strtotime($user->user_registered . ' UTC') >= time() - 120;
        if ($new) {
            self::record('admin_created', 'critical', 'New administrator account: ' . $login, array('login' => $login));
        } else {
            self::record('admin_granted', 'critical', 'Administrator role given to ' . $login, array('login' => $login));
        }
    }

    public static function user_deleted($user_id)
    {
        $user = get_userdata($user_id);
        if (self::is_admin_user($user)) {
            self::record('admin_deleted', 'warning', 'Administrator account deleted: ' . $user->user_login, array('login' => $user->user_login));
        }
    }

    public static function option_updated($option, $old, $new)
    {
        if (!in_array($option, self::WATCHED_OPTIONS, true)) {
            return;
        }
        $severity = 'warning';
        if (in_array($option, array('siteurl', 'home'), true)) {
            $severity = 'critical';
        } elseif ($option === 'default_role' && in_array($new, array('administrator', 'editor'), true)) {
            $severity = 'critical';
        } elseif ($option === 'users_can_register' && $new && in_array(get_option('default_role'), array('administrator', 'editor'), true)) {
            $severity = 'critical';
        }
        $show = function ($value) {
            return is_scalar($value) ? substr((string) $value, 0, 200) : '';
        };
        self::record('setting_changed', $severity, 'Setting changed: ' . $option, array(
            'option' => $option,
            'from'   => $show($old),
            'to'     => $show($new),
        ));
    }
}
