<?php
/**
 * Health and security snapshot, sent once a day and whenever SiteWatch asks for a fresh one.
 *
 * Read-only: nothing here changes the site. Personal data is kept out on purpose: administrator accounts are
 * reported by login name only, and no content, orders or visitor data is ever included.
 */

defined('ABSPATH') || exit;

final class SiteWatch_Connector_Health
{
    /** @return array<string, mixed> */
    public static function snapshot()
    {
        global $wp_version, $wpdb;

        self::load_admin_includes();

        $plugins = self::plugins();
        $themes = self::themes();
        $core = self::core_update();

        $snapshot = array(
            'generated_at' => time(),
            'environment'  => array(
                'wp_version'          => (string) $wp_version,
                'php_version'         => PHP_VERSION,
                'db_server'           => method_exists($wpdb, 'db_server_info') ? (string) $wpdb->db_server_info() : (string) $wpdb->db_version(),
                'server_software'     => isset($_SERVER['SERVER_SOFTWARE']) ? substr((string) $_SERVER['SERVER_SOFTWARE'], 0, 100) : '',
                'site_url'            => site_url('/'),
                'home_url'            => home_url('/'),
                'https'               => strpos(home_url('/'), 'https://') === 0,
                'multisite'           => is_multisite(),
                'locale'              => get_locale(),
                'timezone'            => function_exists('wp_timezone_string') ? wp_timezone_string() : (string) get_option('timezone_string'),
                'memory_limit'        => (string) ini_get('memory_limit'),
                'wp_memory_limit'     => defined('WP_MEMORY_LIMIT') ? (string) WP_MEMORY_LIMIT : '',
                'max_execution_time'  => (int) ini_get('max_execution_time'),
                'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
                'post_max_size'       => (string) ini_get('post_max_size'),
                'object_cache'        => (bool) wp_using_ext_object_cache(),
                'missing_extensions'  => array_values(array_filter(array('curl', 'mbstring', 'openssl', 'zip', 'intl', 'exif'), function ($ext) {
                    return !extension_loaded($ext);
                })),
                'image_library'       => extension_loaded('imagick') ? 'imagick' : (extension_loaded('gd') ? 'gd' : 'none'),
                'disk_free_bytes'     => function_exists('disk_free_space') ? (int) @disk_free_space(ABSPATH) : null,
            ),
            'updates'      => array(
                'core'    => $core,
                'plugins' => array_values(array_filter($plugins, function ($p) {
                    return $p['update'] !== null;
                })),
                'themes'  => array_values(array_filter($themes, function ($t) {
                    return $t['update'] !== null;
                })),
            ),
            'plugins'      => $plugins,
            'themes'       => $themes,
            'database'     => self::database(),
            'cron'         => self::cron(),
            'admins'       => self::admins(),
        );
        $snapshot['security'] = self::security_checks($snapshot);
        return $snapshot;
    }

    private static function load_admin_includes()
    {
        foreach (array('plugin.php', 'update.php', 'theme.php', 'file.php') as $file) {
            if (is_readable(ABSPATH . 'wp-admin/includes/' . $file)) {
                require_once ABSPATH . 'wp-admin/includes/' . $file;
            }
        }
    }

    private static function plugins()
    {
        if (!function_exists('get_plugins')) {
            return array();
        }
        $updates = get_site_transient('update_plugins');
        $auto = (array) get_site_option('auto_update_plugins', array());
        $list = array();
        foreach (get_plugins() as $file => $data) {
            $update = isset($updates->response[$file]->new_version) ? (string) $updates->response[$file]->new_version : null;
            $list[] = array(
                'file'        => $file,
                'slug'        => strpos($file, '/') !== false ? dirname($file) : basename($file, '.php'),
                'name'        => (string) $data['Name'],
                'version'     => (string) $data['Version'],
                'author'      => wp_strip_all_tags((string) $data['Author']),
                'active'      => is_plugin_active($file),
                'network'     => is_multisite() && is_plugin_active_for_network($file),
                'update'      => $update,
                'auto_update' => in_array($file, $auto, true),
            );
            // The version before the last update, for rollback (recorded since plugin 1.5.0).
            $previous = class_exists('SiteWatch_Connector_Autofix') ? SiteWatch_Connector_Autofix::previous($file) : null;
            if ($previous !== null) {
                $list[count($list) - 1]['previous_version'] = (string) $previous['previous'];
                $list[count($list) - 1]['updated_at'] = (int) $previous['updated_at'];
            }
            if (count($list) >= 300) {
                break;
            }
        }
        return $list;
    }

    private static function themes()
    {
        $updates = get_site_transient('update_themes');
        $active = wp_get_theme();
        $list = array();
        foreach (wp_get_themes() as $slug => $theme) {
            $list[] = array(
                'slug'    => (string) $slug,
                'name'    => (string) $theme->get('Name'),
                'version' => (string) $theme->get('Version'),
                'active'  => $slug === $active->get_stylesheet(),
                'parent'  => $slug === $active->get_template() && $active->get_template() !== $active->get_stylesheet(),
                'update'  => isset($updates->response[$slug]['new_version']) ? (string) $updates->response[$slug]['new_version'] : null,
            );
            if (count($list) >= 50) {
                break;
            }
        }
        return $list;
    }

    private static function core_update()
    {
        global $wp_version;
        $latest = null;
        $transient = get_site_transient('update_core');
        if (isset($transient->updates) && is_array($transient->updates)) {
            foreach ($transient->updates as $offer) {
                if (isset($offer->response) && $offer->response === 'upgrade' && isset($offer->current)) {
                    $latest = (string) $offer->current;
                    break;
                }
            }
        }
        return array('current' => (string) $wp_version, 'latest' => $latest);
    }

    private static function database()
    {
        global $wpdb;
        $size = $wpdb->get_row($wpdb->prepare(
            'SELECT SUM(data_length + index_length) AS bytes, COUNT(*) AS tables FROM information_schema.TABLES WHERE table_schema = %s',
            DB_NAME
        ));
        $autoload = $wpdb->get_var("SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes', 'on', 'auto', 'auto-on')");
        $revisions = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'");
        $transients = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
            $wpdb->esc_like('_transient_timeout_') . '%',
            time()
        ));
        return array(
            'size_bytes'         => $size ? (int) $size->bytes : null,
            'tables'             => $size ? (int) $size->tables : null,
            'prefix'             => $wpdb->prefix,
            'autoload_bytes'     => $autoload !== null ? (int) $autoload : null,
            'revisions'          => $revisions !== null ? (int) $revisions : null,
            'expired_transients' => $transients !== null ? (int) $transients : null,
        );
    }

    private static function cron()
    {
        $events = 0;
        $overdue = 0;
        $crons = function_exists('_get_cron_array') ? _get_cron_array() : array();
        foreach ((array) $crons as $timestamp => $hooks) {
            foreach ((array) $hooks as $hook => $instances) {
                $events += count((array) $instances);
                if ((int) $timestamp < time() - 900) {
                    $overdue += count((array) $instances);
                }
            }
        }
        return array(
            'disabled'  => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'alternate' => defined('ALTERNATE_WP_CRON') && ALTERNATE_WP_CRON,
            'events'    => $events,
            'overdue'   => $overdue,
        );
    }

    private static function admins()
    {
        $users = get_users(array('role' => 'administrator', 'fields' => array('ID', 'user_login', 'display_name', 'user_registered'), 'number' => 50));
        $list = array();
        foreach ($users as $user) {
            $last = get_user_meta($user->ID, 'sitewatch_last_login', true);
            $list[] = array(
                'login'      => (string) $user->user_login,
                'name'       => (string) $user->display_name,
                'registered' => (string) $user->user_registered,
                'last_login' => $last !== '' ? (int) $last : null,
            );
        }
        return $list;
    }

    /**
     * Pass / warning / critical checks. Each has a stable id so SiteWatch can track it over time.
     *
     * @return array<int, array{id: string, label: string, status: string, detail: string}>
     */
    private static function security_checks(array $snapshot)
    {
        $checks = array();
        $add = function ($id, $label, $status, $detail = '') use (&$checks) {
            $checks[] = array('id' => $id, 'label' => $label, 'status' => $status, 'detail' => $detail);
        };

        $core = $snapshot['updates']['core'];
        $add('core_update', 'WordPress is up to date', $core['latest'] ? 'warning' : 'ok',
            $core['latest'] ? 'Version ' . $core['latest'] . ' is available (running ' . $core['current'] . ').' : '');

        $pluginUpdates = count($snapshot['updates']['plugins']);
        $add('plugin_updates', 'Plugins are up to date', $pluginUpdates > 0 ? 'warning' : 'ok',
            $pluginUpdates > 0 ? $pluginUpdates . ' plugin update(s) waiting.' : '');

        $themeUpdates = count($snapshot['updates']['themes']);
        $add('theme_updates', 'Themes are up to date', $themeUpdates > 0 ? 'warning' : 'ok',
            $themeUpdates > 0 ? $themeUpdates . ' theme update(s) waiting.' : '');

        $inactive = count(array_filter($snapshot['plugins'], function ($p) {
            return !$p['active'];
        }));
        $add('inactive_plugins', 'No unused plugins installed', $inactive > 0 ? 'info' : 'ok',
            $inactive > 0 ? $inactive . ' inactive plugin(s). Unused plugins can still be attacked; delete them.' : '');

        $php = PHP_VERSION;
        $add('php_version', 'PHP version is supported', version_compare($php, '8.1', '<') ? 'warning' : 'ok',
            version_compare($php, '8.1', '<') ? 'PHP ' . $php . ' no longer receives security fixes.' : '');

        $add('https', 'Site uses HTTPS', $snapshot['environment']['https'] ? 'ok' : 'warning',
            $snapshot['environment']['https'] ? '' : 'The home URL is not https://.');

        $display = defined('WP_DEBUG') && WP_DEBUG && (!defined('WP_DEBUG_DISPLAY') || WP_DEBUG_DISPLAY) && ini_get('display_errors');
        $add('debug_display', 'Errors are hidden from visitors', $display ? 'warning' : 'ok',
            $display ? 'WP_DEBUG is on and errors are displayed. Set WP_DEBUG_DISPLAY to false.' : '');

        $editable = !defined('DISALLOW_FILE_EDIT') || !DISALLOW_FILE_EDIT;
        $add('file_edit', 'Theme and plugin file editor is disabled', $editable ? 'info' : 'ok',
            $editable ? 'Add define(\'DISALLOW_FILE_EDIT\', true); to wp-config.php so a stolen admin login cannot edit PHP files.' : '');

        $adminUser = username_exists('admin');
        $add('admin_username', 'No account named "admin"', $adminUser ? 'warning' : 'ok',
            $adminUser ? 'The "admin" username is the first one attackers try.' : '');

        $register = (bool) get_option('users_can_register');
        $role = (string) get_option('default_role');
        $status = !$register ? 'ok' : (in_array($role, array('administrator', 'editor'), true) ? 'critical' : 'info');
        $add('registration', 'Open registration is safe', $status,
            $register ? 'Anyone can register and receives the "' . $role . '" role.' : '');

        $xmlrpc = file_exists(ABSPATH . 'xmlrpc.php') && apply_filters('xmlrpc_enabled', true);
        $add('xmlrpc', 'XML-RPC is disabled', $xmlrpc ? 'info' : 'ok',
            $xmlrpc ? 'XML-RPC is often used for password guessing. Disable it unless an app needs it.' : '');

        $admins = count($snapshot['admins']);
        $add('admin_count', 'Few administrator accounts', $admins > 5 ? 'warning' : 'ok', $admins . ' administrator account(s).');

        $cron = $snapshot['cron'];
        $cronStatus = $cron['overdue'] > 5 ? 'warning' : 'ok';
        $add('cron', 'Scheduled tasks are running', $cronStatus,
            $cron['overdue'] > 0 ? $cron['overdue'] . ' scheduled task(s) are overdue' . ($cron['disabled'] ? ' and WP-Cron is disabled (a server cron must call wp-cron.php).' : '.') : '');

        list($modified, $missing, $unknown) = self::core_integrity();
        if ($modified === null) {
            $add('core_files', 'WordPress core files are unmodified', 'info', 'Checksums could not be downloaded from WordPress.org.');
        } else {
            $bad = count($modified) + count($unknown);
            $detail = '';
            if ($modified) {
                $detail .= 'Modified: ' . implode(', ', array_slice($modified, 0, 10)) . (count($modified) > 10 ? ' …' : '') . '. ';
            }
            if ($unknown) {
                $detail .= 'Unexpected files: ' . implode(', ', array_slice($unknown, 0, 10)) . (count($unknown) > 10 ? ' …' : '') . '. ';
            }
            if ($missing) {
                $detail .= count($missing) . ' core file(s) missing.';
            }
            $add('core_files', 'WordPress core files are unmodified', $bad > 0 ? 'critical' : ($missing ? 'warning' : 'ok'), trim($detail));
        }

        $uploadsPhp = self::php_in_uploads();
        $add('uploads_php', 'No PHP files in the uploads folder', $uploadsPhp ? 'critical' : 'ok',
            $uploadsPhp ? 'Found: ' . implode(', ', array_slice($uploadsPhp, 0, 10)) . '. PHP files in uploads are a common sign of a hacked site.' : '');

        return $checks;
    }

    /**
     * Compare core files with the official checksums from WordPress.org.
     *
     * @return array{0: array|null, 1: array, 2: array} modified (null when checksums are unavailable), missing, unknown
     */
    private static function core_integrity()
    {
        global $wp_version;
        if (!function_exists('get_core_checksums')) {
            return array(null, array(), array());
        }
        $checksums = get_core_checksums($wp_version, get_locale() ? get_locale() : 'en_US');
        if (!is_array($checksums) || $checksums === array()) {
            $checksums = get_core_checksums($wp_version, 'en_US');
        }
        if (!is_array($checksums) || $checksums === array()) {
            return array(null, array(), array());
        }
        $modified = array();
        $missing = array();
        foreach ($checksums as $file => $md5) {
            if (strpos($file, 'wp-content/') === 0) {
                continue;
            }
            $path = ABSPATH . $file;
            if (!file_exists($path)) {
                $missing[] = $file;
            } elseif (md5_file($path) !== $md5) {
                $modified[] = $file;
            }
        }
        // PHP files inside wp-admin and wp-includes that WordPress does not ship.
        $unknown = array();
        foreach (array('wp-admin', 'wp-includes') as $dir) {
            $base = ABSPATH . $dir;
            if (!is_dir($base)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
            $seen = 0;
            foreach ($iterator as $item) {
                if (++$seen > 20000 || count($unknown) >= 25) {
                    break;
                }
                if ($item->isFile() && strtolower($item->getExtension()) === 'php') {
                    $relative = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen(ABSPATH))), '/');
                    if (!isset($checksums[$relative])) {
                        $unknown[] = $relative;
                    }
                }
            }
        }
        return array($modified, $missing, $unknown);
    }

    /** @return array<int, string> Up to 20 executable files found under wp-content/uploads. */
    private static function php_in_uploads()
    {
        $uploads = wp_get_upload_dir();
        $base = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
        if ($base === '' || !is_dir($base)) {
            return array();
        }
        $found = array();
        $seen = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if (++$seen > 50000 || count($found) >= 20) {
                break;
            }
            if ($item->isFile() && preg_match('/\.(php\d?|phtml|phar)$/i', $item->getFilename())) {
                // Folders like "sitewatch-connector" and caching plugins keep an index.php to block listings.
                if (strtolower($item->getFilename()) === 'index.php' && $item->getSize() < 100) {
                    continue;
                }
                $found[] = 'uploads/' . ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($base))), '/');
            }
        }
        return $found;
    }
}
