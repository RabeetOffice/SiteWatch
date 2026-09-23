<?php
/**
 * Watches wp-config.php and .htaccess, two files attackers change to hide backdoors, redirect visitors or turn off
 * security settings.
 *
 * Only a fingerprint is kept: size, modification time and a SHA-256 hash in the option below. SiteWatch receives
 * which file changed, how (created, modified, deleted), the sizes and the first 12 characters of the hash; the
 * contents never leave the site.
 *
 * Changes made while WordPress itself was working on those files (a plugin activated or updated, permalinks saved,
 * a caching plugin writing its .htaccess rules) are recorded as warnings with who did it. Any other change is found
 * by the 5-minute heartbeat and reported as critical, because it came from outside WordPress: FTP, the hosting
 * panel, another program, or malware.
 */

defined('ABSPATH') || exit;

final class SiteWatch_Connector_Files
{
    const OPTION = 'sitewatch_connector_files';

    /** @var string|null What WordPress was doing in this request that may explain a change. */
    private static $expected = null;

    public static function init()
    {
        add_action('activated_plugin', array(__CLASS__, 'plugin_activity'), 10, 1);
        add_action('deactivated_plugin', array(__CLASS__, 'plugin_activity'), 10, 1);
        add_action('upgrader_process_complete', array(__CLASS__, 'upgraded'), 5, 0);
        // Fires inside insert_with_markers(), which WordPress and most caching and security plugins use for .htaccess.
        add_filter('insert_with_markers_inline_instructions', array(__CLASS__, 'markers_written'), 10, 2);
        add_action('shutdown', array(__CLASS__, 'after_request'), 5);
    }

    public static function plugin_activity($file)
    {
        $name = dirname((string) $file) !== '.' ? dirname((string) $file) : basename((string) $file, '.php');
        self::expect(current_action() === 'activated_plugin' ? 'activating ' . $name : 'deactivating ' . $name);
    }

    public static function upgraded()
    {
        self::expect('installing updates');
    }

    public static function markers_written($instructions, $marker = '')
    {
        self::expect('writing "' . substr(sanitize_text_field((string) $marker), 0, 40) . '" rules');
        return $instructions;
    }

    private static function expect($reason)
    {
        if (self::$expected === null) {
            self::$expected = $reason;
        }
    }

    /** Check straight away after WordPress may have changed the files, so the change is attributed correctly. */
    public static function after_request()
    {
        if (self::$expected !== null && SiteWatch_Connector_Client::config() !== null) {
            self::check();
        }
    }

    /**
     * The watched files, keyed by the name shown in SiteWatch.
     *
     * @return array<string, string>
     */
    public static function files()
    {
        $files = array();
        // WordPress also accepts wp-config.php one folder above, unless that folder is another WordPress install.
        if (is_file(ABSPATH . 'wp-config.php')) {
            $files['wp-config.php'] = ABSPATH . 'wp-config.php';
        } elseif (is_file(dirname(ABSPATH) . '/wp-config.php') && !is_file(dirname(ABSPATH) . '/wp-settings.php')) {
            $files['wp-config.php'] = dirname(ABSPATH) . '/wp-config.php';
        } else {
            $files['wp-config.php'] = ABSPATH . 'wp-config.php';
        }
        $files['.htaccess'] = ABSPATH . '.htaccess';
        // WordPress in its own folder with the site at the domain root: the root .htaccess matters too.
        if (!function_exists('get_home_path') && is_readable(ABSPATH . 'wp-admin/includes/file.php')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (function_exists('get_home_path')) {
            $home = trailingslashit(wp_normalize_path(get_home_path()));
            if ($home !== trailingslashit(wp_normalize_path(ABSPATH))) {
                $files['.htaccess (site root)'] = $home . '.htaccess';
            }
        }
        return $files;
    }

    /**
     * Compare the files with their last fingerprints and record an event for each change. The first run only saves
     * the fingerprints. Runs from every heartbeat and after requests in which WordPress may have changed the files.
     *
     * @return int Number of changes recorded.
     */
    public static function check()
    {
        $known = get_option(self::OPTION, null);
        $baseline = !is_array($known);
        $known = is_array($known) ? $known : array();
        $current = array();
        $changes = 0;

        foreach (self::files() as $label => $path) {
            $before = isset($known[$label]) && is_array($known[$label]) ? $known[$label] : null;
            $after = self::fingerprint($path, $before);
            $current[$label] = $after;
            if ($baseline || ($before === null && $after === null)) {
                continue;
            }
            if ($before !== null && $after !== null && $before['hash'] === $after['hash']) {
                continue;
            }
            $change = $before === null ? 'created' : ($after === null ? 'deleted' : 'modified');
            self::report($label, $change, $before, $after);
            $changes++;
        }

        if ($baseline || $changes > 0 || $current != $known) {
            update_option(self::OPTION, array_filter($current), false);
        }
        return $changes;
    }

    /**
     * @param array|null $before Previous fingerprint; when size and time are unchanged the file is not hashed again.
     * @return array{hash: string, size: int, mtime: int}|null Null when the file does not exist.
     */
    private static function fingerprint($path, $before)
    {
        clearstatcache(true, $path);
        if (!is_file($path)) {
            return null;
        }
        $size = (int) @filesize($path);
        $mtime = (int) @filemtime($path);
        if ($before !== null && (int) $before['size'] === $size && (int) $before['mtime'] === $mtime) {
            return $before;
        }
        $hash = is_readable($path) ? (string) @hash_file('sha256', $path) : '';
        return array('hash' => $hash !== '' ? $hash : 'unreadable-' . $size . '-' . $mtime, 'size' => $size, 'mtime' => $mtime);
    }

    private static function report($label, $change, $before, $after)
    {
        $verbs = array('created' => 'created', 'modified' => 'changed', 'deleted' => 'deleted');
        $explained = self::$expected !== null;
        $data = array(
            'file'        => $label,
            'change'      => $change,
            'size_before' => $before !== null ? (int) $before['size'] : null,
            'size_after'  => $after !== null ? (int) $after['size'] : null,
            'modified_at' => $after !== null ? (int) $after['mtime'] : null,
            'hash_before' => $before !== null ? substr((string) $before['hash'], 0, 12) : null,
            'hash_after'  => $after !== null ? substr((string) $after['hash'], 0, 12) : null,
            'source'      => $explained ? 'wordpress' : 'outside',
        );
        if ($explained) {
            $data['during'] = self::$expected;
            $title = sprintf('%s %s while %s', $label, $verbs[$change], self::$expected);
        } else {
            // Nobody in WordPress did this, so do not name the cron run that noticed it.
            $data['by'] = 'outside WordPress';
            $title = sprintf('%s %s outside WordPress', $label, $verbs[$change]);
        }
        SiteWatch_Connector_Activity::record('file_changed', $explained ? 'warning' : 'critical', $title, $data);
    }
}
