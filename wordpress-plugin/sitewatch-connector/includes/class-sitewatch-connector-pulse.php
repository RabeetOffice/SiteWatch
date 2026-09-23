<?php
/**
 * Evidence that the site is (or is not) serving pages, seen from inside WordPress.
 *
 * SiteWatch checks the site from outside. When those checks fail but WordPress keeps serving pages to real
 * visitors, the checks are probably being blocked (firewall, WAF, rate limit) rather than the site being down.
 * To tell the two apart, this records three timestamps, reported with every heartbeat:
 *   ok     the last page served without a server error
 *   error  the last page answered with a 5xx status (or a fatal error)
 *   probe  the last SiteWatch check that reached WordPress, with the status it got and the address it came from
 *          (shown in SiteWatch as the address to allow-list when checks are blocked)
 *
 * Cost per request: one filemtime(); a file is touched at most once a minute (always for SiteWatch's own checks,
 * which come every few minutes).
 */

defined('ABSPATH') || exit;

if (!class_exists('SiteWatch_Connector_Pulse')) {

    final class SiteWatch_Connector_Pulse
    {
        const MIN_GAP = 60;

        private static $initialised = false;

        public static function init()
        {
            if (self::$initialised) {
                return;
            }
            self::$initialised = true;
            register_shutdown_function(array(__CLASS__, 'on_shutdown'));
        }

        private static function dir()
        {
            return (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH . 'wp-content') . '/sitewatch-connector';
        }

        public static function on_shutdown()
        {
            // Background and command-line requests say nothing about what visitors get.
            if ((defined('DOING_CRON') && DOING_CRON) || (defined('WP_CLI') && WP_CLI) || PHP_SAPI === 'cli') {
                return;
            }
            $status = function_exists('http_response_code') ? (int) http_response_code() : 200;
            if ($status <= 0) {
                $status = 200;
            }
            $agent = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
            if (stripos($agent, 'SiteWatch/') !== false) {
                $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) : '';
                self::write('probe', trim($status . ' ' . $ip), true);
            }
            self::write($status >= 500 ? 'error' : 'ok', (string) $status, false);
        }

        /** Record a server error (fatal errors end the request before the shutdown hook sees the status). */
        public static function mark_error()
        {
            self::write('error', '500', false);
        }

        private static function write($name, $content, $always)
        {
            $file = self::dir() . '/' . $name . '.stamp';
            $mtime = @filemtime($file);
            if (!$always && $mtime !== false && time() - $mtime < self::MIN_GAP) {
                return;
            }
            if (!is_dir(self::dir())) {
                // SiteWatch_Connector_Errors creates the folder with its access rules; nothing to record until then.
                return;
            }
            @file_put_contents($file, $content, LOCK_EX);
        }

        /**
         * @return array{ok_at: int|null, error_at: int|null, probe_at: int|null, probe_status: int|null, probe_ip: string|null}
         */
        public static function read()
        {
            $read = function ($name) {
                $file = SiteWatch_Connector_Pulse::path($name);
                $mtime = @filemtime($file);
                return $mtime === false ? array(null, '') : array((int) $mtime, trim((string) @file_get_contents($file)));
            };
            list($ok) = $read('ok');
            list($error) = $read('error');
            list($probe, $content) = $read('probe');
            $parts = explode(' ', $content, 2);
            return array(
                'ok_at'        => $ok,
                'error_at'     => $error,
                'probe_at'     => $probe,
                'probe_status' => $probe !== null ? (int) $parts[0] : null,
                'probe_ip'     => $probe !== null && isset($parts[1]) && filter_var($parts[1], FILTER_VALIDATE_IP) ? $parts[1] : null,
            );
        }

        public static function path($name)
        {
            return self::dir() . '/' . $name . '.stamp';
        }
    }
}
