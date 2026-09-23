<?php
/**
 * Captures PHP fatal errors with their real cause, without WP_DEBUG and without showing anything to visitors.
 *
 * Two hooks, whichever runs first:
 *   - the 'wp_php_error_message' filter, which WordPress's own fatal error handler applies before it prints the
 *     "There has been a critical error" page (that handler then exits, so a later shutdown function never runs);
 *   - a shutdown function, for sites that disable the WordPress handler or replace it with a php-error.php drop-in.
 *
 * Each error is identified by a fingerprint (type, file, line, message). The first occurrence is sent at once;
 * repeats within THROTTLE seconds are only counted and go out with the next heartbeat, so a site that crashes on
 * every request does not send a report per visitor. Counts are kept in wp-content/sitewatch-connector/, because the
 * database may be the thing that failed.
 */

defined('ABSPATH') || exit;

if (!class_exists('SiteWatch_Connector_Errors')) {

    final class SiteWatch_Connector_Errors
    {
        const THROTTLE = 600;
        const MAX_ENTRIES = 30;
        const FATAL_TYPES = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR);

        private static $initialised = false;
        private static $captured = false;
        private static $in_fatal = false;

        public static function init()
        {
            if (self::$initialised) {
                return;
            }
            self::$initialised = true;
            add_filter('wp_php_error_message', array(__CLASS__, 'from_wp_handler'), 1, 2);
            register_shutdown_function(array(__CLASS__, 'on_shutdown'));
        }

        public static function in_fatal()
        {
            return self::$in_fatal;
        }

        /** WordPress fatal error handler: capture, then return the message unchanged. */
        public static function from_wp_handler($message, $error)
        {
            if (is_array($error)) {
                self::capture($error);
            }
            return $message;
        }

        public static function on_shutdown()
        {
            $error = error_get_last();
            if (is_array($error) && isset($error['type']) && in_array((int) $error['type'], self::FATAL_TYPES, true)) {
                self::capture($error);
            }
        }

        /**
         * Record an error and send it right away unless the same error was sent recently.
         */
        public static function capture(array $error)
        {
            if (self::$captured || empty($error['message'])) {
                return;
            }
            self::$captured = true;
            self::$in_fatal = true;
            try {
                $event = self::describe($error);
                $now = time();

                $store = self::read();
                $fp = $event['data']['fingerprint'];
                $entry = isset($store[$fp]) ? $store[$fp] : array('first_at' => $now, 'count' => 0, 'sent_count' => 0, 'last_sent' => 0);
                $entry['count']++;
                $entry['last_at'] = $now;
                // Recent occurrence times, for auto-fix's "3 times in 10 minutes" rule.
                $entry['recent'] = array_slice(array_merge(isset($entry['recent']) ? (array) $entry['recent'] : array(), array($now)), -10);
                $entry['event'] = $event;
                $store[$fp] = $entry;

                $send_now = ($now - (int) $entry['last_sent']) >= self::THROTTLE;
                if ($send_now) {
                    // Claim the slot before sending so parallel crashing requests do not all send.
                    $store[$fp]['last_sent'] = $now;
                }
                self::write($store);
                if (class_exists('SiteWatch_Connector_Pulse')) {
                    SiteWatch_Connector_Pulse::mark_error();
                }
                if (class_exists('SiteWatch_Connector_Autofix') && !(defined('WP_CLI') && WP_CLI)) {
                    $d = $event['data'];
                    SiteWatch_Connector_Autofix::consider($d['component'], $entry['recent'], array(
                        'fingerprint' => $fp,
                        'error_type'  => $d['error_type'],
                        'message'     => substr((string) preg_replace('/\s*Stack trace:.*$/s', '', $d['message']), 0, 300),
                        'file'        => $d['file'],
                        'line'        => $d['line'],
                    ));
                }

                if ($send_now && class_exists('SiteWatch_Connector_Client') && SiteWatch_Connector_Client::config() !== null) {
                    $payload = self::to_event($fp, $entry);
                    $result = SiteWatch_Connector_Client::send('fatal', array('events' => array($payload)), 4);
                    if ($result['ok']) {
                        $store = self::read();
                        if (isset($store[$fp])) {
                            $store[$fp]['sent_count'] = $entry['count'];
                            self::write($store);
                        }
                    }
                }
            } catch (Throwable $e) {
                // Never make a crash worse.
            }
            self::$in_fatal = false;
        }

        /**
         * Errors with occurrences SiteWatch has not heard about yet, as events for the heartbeat.
         *
         * @return array<int, array<string, mixed>>
         */
        public static function pending_events()
        {
            $events = array();
            foreach (self::read() as $fp => $entry) {
                if ((int) $entry['count'] > (int) $entry['sent_count'] && isset($entry['event'])) {
                    $events[] = self::to_event($fp, $entry);
                }
            }
            return $events;
        }

        /** Mark errors included in a successful heartbeat as delivered. */
        public static function mark_sent(array $events)
        {
            if ($events === array()) {
                return;
            }
            $store = self::read();
            foreach ($events as $event) {
                $fp = isset($event['data']['fingerprint']) ? $event['data']['fingerprint'] : '';
                if ($fp !== '' && isset($store[$fp])) {
                    $store[$fp]['sent_count'] = (int) $event['data']['total_count'];
                }
            }
            self::write($store);
        }

        /** Recent errors for the settings screen, newest first. */
        public static function recent()
        {
            $store = self::read();
            uasort($store, function ($a, $b) {
                return (int) $b['last_at'] - (int) $a['last_at'];
            });
            return $store;
        }

        public static function clear()
        {
            self::write(array());
        }

        private static function to_event($fp, array $entry)
        {
            $event = $entry['event'];
            $event['uid'] = substr($fp, 0, 16) . '-' . (int) $entry['count'];
            $event['at'] = (int) $entry['last_at'];
            $event['data']['total_count'] = (int) $entry['count'];
            $event['data']['new_count'] = (int) $entry['count'] - (int) $entry['sent_count'];
            $event['data']['first_at'] = (int) $entry['first_at'];
            $event['data']['last_at'] = (int) $entry['last_at'];
            return $event;
        }

        /**
         * Turn error_get_last() output into a report: readable type, paths relative to the WordPress root, and the
         * plugin or theme the failing file belongs to.
         */
        private static function describe(array $error)
        {
            $types = array(
                E_ERROR => 'Fatal error', E_PARSE => 'Parse error', E_CORE_ERROR => 'Core error',
                E_COMPILE_ERROR => 'Compile error', E_USER_ERROR => 'User error', E_RECOVERABLE_ERROR => 'Recoverable fatal error',
            );
            $type = isset($error['type']) ? (int) $error['type'] : E_ERROR;
            $file = isset($error['file']) ? self::relative((string) $error['file']) : '';
            $line = isset($error['line']) ? (int) $error['line'] : 0;
            $message = self::scrub((string) $error['message']);
            $component = self::component(isset($error['file']) ? (string) $error['file'] : '');

            // The same bug reached from different pages has different stack traces, and numbers in messages
            // (memory sizes, object IDs) change between occurrences, so neither is part of the fingerprint.
            $headline = trim((string) preg_replace('/\s*Stack trace:.*$/s', '', $message));
            $normalised = preg_replace('/\d{3,}/', '#', $headline);
            $fingerprint = sha1($type . '|' . $file . '|' . $line . '|' . $normalised);

            $title = ($component['name'] !== '' ? $component['name'] . ': ' : '') . (isset($types[$type]) ? $types[$type] : 'PHP error');

            return array(
                'type'     => 'fatal_error',
                'severity' => 'critical',
                'title'    => $title,
                'data'     => array(
                    'fingerprint' => $fingerprint,
                    'error_type'  => isset($types[$type]) ? $types[$type] : 'PHP error',
                    'message'     => function_exists('mb_substr') ? mb_substr($message, 0, 2000) : substr($message, 0, 2000),
                    'file'        => $file,
                    'line'        => $line,
                    'component'   => $component,
                    'request'     => self::request(),
                ),
            );
        }

        /** Which plugin, theme or core area a file belongs to. */
        public static function component($path)
        {
            $path = str_replace('\\', '/', $path);
            $result = array('type' => 'other', 'slug' => '', 'name' => '', 'version' => '');
            $roots = array(
                'mu-plugin' => defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : '',
                'plugin'    => defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : '',
                'theme'     => defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/themes' : '',
            );
            foreach ($roots as $type => $root) {
                $root = rtrim(str_replace('\\', '/', (string) $root), '/') . '/';
                if ($root !== '/' && strpos($path, $root) === 0) {
                    $rest = substr($path, strlen($root));
                    $slug = strpos($rest, '/') !== false ? substr($rest, 0, strpos($rest, '/')) : preg_replace('/\.php$/', '', $rest);
                    $result = array('type' => $type, 'slug' => $slug, 'name' => $slug, 'version' => '');
                    try {
                        if ($type === 'plugin') {
                            $main = self::plugin_main_file($slug);
                            if ($main !== '' && function_exists('get_file_data')) {
                                $data = get_file_data(WP_PLUGIN_DIR . '/' . $main, array('Name' => 'Plugin Name', 'Version' => 'Version'));
                                $result['name'] = $data['Name'] !== '' ? $data['Name'] : $slug;
                                $result['version'] = (string) $data['Version'];
                            }
                        } elseif ($type === 'theme' && function_exists('get_file_data')) {
                            $style = WP_CONTENT_DIR . '/themes/' . $slug . '/style.css';
                            if (is_readable($style)) {
                                $data = get_file_data($style, array('Name' => 'Theme Name', 'Version' => 'Version'));
                                $result['name'] = $data['Name'] !== '' ? $data['Name'] : $slug;
                                $result['version'] = (string) $data['Version'];
                            }
                        }
                    } catch (Throwable $e) {
                        // Keep the slug.
                    }
                    return $result;
                }
            }
            $abs = defined('ABSPATH') ? str_replace('\\', '/', ABSPATH) : '';
            if ($abs !== '' && (strpos($path, $abs . 'wp-includes/') === 0 || strpos($path, $abs . 'wp-admin/') === 0)) {
                global $wp_version;
                return array('type' => 'core', 'slug' => 'wordpress', 'name' => 'WordPress core', 'version' => isset($wp_version) ? (string) $wp_version : '');
            }
            return $result;
        }

        /** The main file of a plugin folder, from the active plugin list (works before wp-admin includes load). */
        private static function plugin_main_file($slug)
        {
            if (!function_exists('get_option')) {
                return '';
            }
            $active = (array) get_option('active_plugins', array());
            if (function_exists('is_multisite') && is_multisite()) {
                $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', array())));
            }
            foreach ($active as $file) {
                if (strpos((string) $file, $slug . '/') === 0) {
                    return (string) $file;
                }
            }
            $guess = $slug . '/' . $slug . '.php';
            return is_readable(WP_PLUGIN_DIR . '/' . $guess) ? $guess : '';
        }

        /** Where the error happened: request type and path (no query string, which may contain personal data). */
        private static function request()
        {
            $context = 'front';
            if (defined('WP_CLI') && WP_CLI) {
                $context = 'cli';
            } elseif (defined('DOING_CRON') && DOING_CRON) {
                $context = 'cron';
            } elseif (defined('DOING_AJAX') && DOING_AJAX) {
                $context = 'ajax';
            } elseif (defined('REST_REQUEST') && REST_REQUEST) {
                $context = 'rest';
            } elseif (function_exists('is_admin') && is_admin()) {
                $context = 'admin';
            }
            $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
            $path = (string) strtok($uri, '?');
            return array(
                'context' => $context,
                'method'  => isset($_SERVER['REQUEST_METHOD']) ? substr((string) $_SERVER['REQUEST_METHOD'], 0, 10) : '',
                'path'    => substr($path, 0, 300),
            );
        }

        /** Paths relative to the WordPress root, so server layout is not disclosed. */
        public static function relative($path)
        {
            $path = str_replace('\\', '/', $path);
            $abs = defined('ABSPATH') ? str_replace('\\', '/', ABSPATH) : '';
            if ($abs !== '' && strpos($path, $abs) === 0) {
                return substr($path, strlen($abs));
            }
            $content = defined('WP_CONTENT_DIR') ? rtrim(str_replace('\\', '/', WP_CONTENT_DIR), '/') . '/' : '';
            if ($content !== '/' && strpos($path, $content) === 0) {
                return 'wp-content/' . substr($path, strlen($content));
            }
            return basename($path);
        }

        public static function scrub($message)
        {
            // Stack traces print Windows paths with doubled backslashes.
            $message = str_replace(array('\\\\', '\\'), '/', $message);
            foreach (array(defined('ABSPATH') ? ABSPATH : '', defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/' : '') as $prefix) {
                $prefix = str_replace('\\', '/', (string) $prefix);
                if ($prefix !== '' && $prefix !== '/') {
                    $message = str_replace($prefix, '', $message);
                    // Stack trace arguments are cut to 15 characters: 'C:/xampp/htdocs...' or '/home/u5740130...'.
                    $message = str_replace("'" . substr($prefix, 0, 15) . "...'", "'…'", $message);
                }
            }
            return trim($message);
        }

        // --------------------------------------------------------------
        // Storage: small files in wp-content/sitewatch-connector, falling back to an option.
        //
        // Every data file is a .php file that starts with GUARD, so requesting it over the web returns nothing on
        // any server that runs PHP. The .htaccess in the folder only protects Apache and LiteSpeed; nginx ignores it.
        // --------------------------------------------------------------

        const GUARD = "<?php exit; ?>\n";
        /** Data files before plugin 1.6.0 (readable over the web on nginx), converted by ensure_dir(). */
        const LEGACY = array('errors.json' => 'errors', 'warnings.json' => 'warnings', 'perf.json' => 'perf', 'autofix.json' => 'autofix',
            'ok.stamp' => 'ok.stamp', 'error.stamp' => 'error.stamp', 'probe.stamp' => 'probe.stamp');

        public static function dir()
        {
            return (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH . 'wp-content') . '/sitewatch-connector';
        }

        /** Path of a guarded data file, e.g. data_path('errors') → …/errors.php. */
        public static function data_path($name)
        {
            return self::dir() . '/' . $name . '.php';
        }

        /** Contents without the guard line, or null when the file does not exist. */
        public static function read_data($name)
        {
            $raw = @file_get_contents(self::data_path($name));
            return $raw === false ? null : self::unguard($raw);
        }

        public static function write_data($name, $content)
        {
            return @file_put_contents(self::data_path($name), self::GUARD . $content, LOCK_EX) !== false;
        }

        public static function unguard($raw)
        {
            return strpos($raw, self::GUARD) === 0 ? (string) substr($raw, strlen(self::GUARD)) : (string) $raw;
        }

        /** Create wp-content/sitewatch-connector (closed to web access) and return its path. */
        public static function ensure_dir()
        {
            $dir = self::dir();
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
                @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
                @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
            }
            foreach (self::LEGACY as $old => $name) {
                if (is_file($dir . '/' . $old)) {
                    $mtime = @filemtime($dir . '/' . $old);
                    if (!is_file(self::data_path($name)) && self::write_data($name, (string) @file_get_contents($dir . '/' . $old)) && $mtime) {
                        @touch(self::data_path($name), $mtime); // stamps are read by their modification time
                    }
                    @unlink($dir . '/' . $old);
                }
            }
            return $dir;
        }

        private static function read()
        {
            $raw = self::read_data('errors');
            if ($raw !== null) {
                $data = json_decode($raw, true);
                return is_array($data) ? $data : array();
            }
            if (function_exists('get_option')) {
                try {
                    $data = get_option('sitewatch_connector_errors');
                    return is_array($data) ? $data : array();
                } catch (Throwable $e) {
                    return array();
                }
            }
            return array();
        }

        private static function write(array $store)
        {
            // Keep the most recent entries only.
            if (count($store) > self::MAX_ENTRIES) {
                uasort($store, function ($a, $b) {
                    return (int) $b['last_at'] - (int) $a['last_at'];
                });
                $store = array_slice($store, 0, self::MAX_ENTRIES, true);
            }
            $dir = self::ensure_dir();
            $json = json_encode($store);
            if (is_dir($dir) && is_writable($dir) && self::write_data('errors', $json)) {
                return;
            }
            if (function_exists('update_option')) {
                try {
                    update_option('sitewatch_connector_errors', $store, false);
                } catch (Throwable $e) {
                    // Nowhere left to write.
                }
            }
        }
    }
}
