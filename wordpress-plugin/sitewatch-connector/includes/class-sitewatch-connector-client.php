<?php
/**
 * Talks to SiteWatch: parses the connection key, signs and sends reports.
 *
 * Every request carries three headers:
 *   X-SiteWatch-Site       the website ID in SiteWatch
 *   X-SiteWatch-Timestamp  Unix time; SiteWatch rejects requests more than 5 minutes off
 *   X-SiteWatch-Signature  hex HMAC-SHA256 of "<timestamp>.<raw JSON body>" with the site's secret
 *
 * This file can be loaded very early (from the must-use loader) and during a fatal error, so it only uses core
 * WordPress functions when they exist and falls back to cURL or PHP streams otherwise.
 */

defined('ABSPATH') || exit;

if (!class_exists('SiteWatch_Connector_Client')) {

    final class SiteWatch_Connector_Client
    {
        const OPTION = 'sitewatch_connector';
        const STATE_OPTION = 'sitewatch_connector_state';
        const KEY_PREFIX = 'swc1_';
        const PROTOCOL = 1;

        /**
         * Connection settings, or null when this site is not connected.
         *
         * @return array{endpoint: string, site_id: int, secret: string, sitewatch_url: string, connected_at: int}|null
         */
        public static function config()
        {
            $config = get_option(self::OPTION);
            if (!is_array($config) || empty($config['endpoint']) || empty($config['site_id']) || empty($config['secret'])) {
                return null;
            }
            return $config;
        }

        /** @return array<string, mixed> */
        public static function state()
        {
            $state = get_option(self::STATE_OPTION);
            return is_array($state) ? $state : array();
        }

        public static function update_state(array $changes)
        {
            update_option(self::STATE_OPTION, array_merge(self::state(), $changes), false);
        }

        /**
         * Decode a connection key copied from SiteWatch (Website → WordPress → Connect).
         *
         * @return array|WP_Error Array with sitewatch_url, endpoint, site_id and secret.
         */
        public static function parse_key($key)
        {
            $key = preg_replace('/\s+/', '', (string) $key);
            if (strpos($key, self::KEY_PREFIX) !== 0) {
                return new WP_Error('sitewatch_key', 'This is not a SiteWatch connection key. Copy the whole key from the website page in SiteWatch.');
            }
            $json = base64_decode(strtr(substr($key, strlen(self::KEY_PREFIX)), '-_', '+/'), true);
            $data = is_string($json) ? json_decode($json, true) : null;
            if (!is_array($data) || empty($data['u']) || empty($data['i']) || empty($data['s'])) {
                return new WP_Error('sitewatch_key', 'The connection key is incomplete. Copy it again from SiteWatch.');
            }
            $url = esc_url_raw((string) $data['u']);
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                return new WP_Error('sitewatch_key', 'The SiteWatch address in the key is not valid.');
            }
            if (!preg_match('/^[a-f0-9]{64}$/', (string) $data['s'])) {
                return new WP_Error('sitewatch_key', 'The secret in the key is not valid. Copy the key again from SiteWatch.');
            }
            return array(
                'sitewatch_url' => untrailingslashit($url),
                'endpoint'      => untrailingslashit($url) . '/api/connector/ingest.php',
                'site_id'       => (int) $data['i'],
                'secret'        => (string) $data['s'],
            );
        }

        /**
         * Small status block sent with every report.
         *
         * @return array<string, mixed>
         */
        public static function status()
        {
            global $wp_version;
            return array(
                'wp_version'     => isset($wp_version) ? (string) $wp_version : '',
                'php_version'    => PHP_VERSION,
                'plugin_version' => defined('SITEWATCH_CONNECTOR_VERSION') ? SITEWATCH_CONNECTOR_VERSION : '',
                'multisite'      => function_exists('is_multisite') && is_multisite(),
                'maintenance'    => file_exists(ABSPATH . '.maintenance'),
                'loader'         => class_exists('SiteWatch_Connector') ? SiteWatch_Connector::loader_installed() : true,
                'pulse'          => class_exists('SiteWatch_Connector_Pulse') ? SiteWatch_Connector_Pulse::read() : null,
                'last_update'    => isset(self::state()['last_update']) ? self::state()['last_update'] : null,
            );
        }

        /**
         * Sign and send a report.
         *
         * @param string $reason hello | heartbeat | fatal | event | deactivated | disconnect
         * @param array  $extra  Keys merged into the payload (events, snapshot).
         * @param int    $timeout Seconds.
         * @param array|null $config Connection to use (defaults to the saved one; the connect screen passes a new key).
         * @return array{ok: bool, code: int, error: string, data: array}
         */
        public static function send($reason, array $extra = array(), $timeout = 15, $config = null)
        {
            $config = $config !== null ? $config : self::config();
            if ($config === null) {
                return array('ok' => false, 'code' => 0, 'error' => 'Not connected to SiteWatch.', 'data' => array());
            }

            $payload = array_merge(array(
                'v'        => self::PROTOCOL,
                'reason'   => (string) $reason,
                'site_url' => function_exists('home_url') ? home_url('/') : '',
                'sent_at'  => time(),
                'status'   => self::status(),
            ), $extra);
            $body = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);
            if (!is_string($body)) {
                return array('ok' => false, 'code' => 0, 'error' => 'The report could not be encoded.', 'data' => array());
            }

            $timestamp = (string) time();
            $headers = array(
                'Content-Type'          => 'application/json',
                'Accept'                => 'application/json',
                'User-Agent'            => 'SiteWatchConnector/' . (defined('SITEWATCH_CONNECTOR_VERSION') ? SITEWATCH_CONNECTOR_VERSION : '1') . '; ' . (function_exists('home_url') ? home_url('/') : ''),
                'X-SiteWatch-Site'      => (string) (int) $config['site_id'],
                'X-SiteWatch-Timestamp' => $timestamp,
                'X-SiteWatch-Signature' => hash_hmac('sha256', $timestamp . '.' . $body, (string) $config['secret']),
            );

            list($code, $response, $error) = self::post((string) $config['endpoint'], $body, $headers, (int) $timeout);
            $decoded = is_string($response) ? json_decode($response, true) : null;
            $data = is_array($decoded) && isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : array();
            $ok = $code >= 200 && $code < 300 && is_array($decoded) && !empty($decoded['success']);
            if (!$ok && $error === '') {
                $error = is_array($decoded) && !empty($decoded['message'])
                    ? (string) $decoded['message']
                    : ($code > 0 ? 'SiteWatch answered with HTTP ' . $code . '.' : 'No answer from SiteWatch.');
            }

            // Keep a short record for the settings screen. Skipped during a fatal error to avoid touching the database.
            if ($reason !== 'fatal' && function_exists('update_option')) {
                $changes = array('last_contact' => time(), 'last_code' => $code, 'last_error' => $ok ? '' : $error);
                if ($ok) {
                    $changes['last_ok'] = time();
                    if (!empty($data['want_snapshot'])) {
                        $changes['want_snapshot'] = true;
                    }
                }
                self::update_state($changes);
                if ($ok && class_exists('SiteWatch_Connector_Updater')) {
                    SiteWatch_Connector_Updater::handle_reply($data);
                }
            }

            return array('ok' => $ok, 'code' => $code, 'error' => $ok ? '' : $error, 'data' => $data);
        }

        /**
         * POST with wp_remote_post when WordPress is fully loaded, otherwise cURL or a PHP stream.
         *
         * @return array{0: int, 1: string|null, 2: string}
         */
        private static function post($url, $body, array $headers, $timeout)
        {
            if (function_exists('wp_remote_post') && did_action('init') && !SiteWatch_Connector_Errors::in_fatal()) {
                $response = wp_remote_post($url, array(
                    'timeout'     => $timeout,
                    'redirection' => 0,
                    'headers'     => $headers,
                    'body'        => $body,
                    'sslverify'   => true,
                ));
                if (is_wp_error($response)) {
                    return array(0, null, $response->get_error_message());
                }
                return array((int) wp_remote_retrieve_response_code($response), (string) wp_remote_retrieve_body($response), '');
            }

            $lines = array();
            foreach ($headers as $name => $value) {
                $lines[] = $name . ': ' . $value;
            }

            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, array(
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $body,
                    CURLOPT_HTTPHEADER     => $lines,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
                    CURLOPT_TIMEOUT        => $timeout,
                    CURLOPT_FOLLOWLOCATION => false,
                ));
                $response = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = $response === false ? curl_error($ch) : '';
                curl_close($ch);
                return array($code, $response === false ? null : (string) $response, $error);
            }

            $context = stream_context_create(array('http' => array(
                'method'        => 'POST',
                'header'        => implode("\r\n", $lines),
                'content'       => $body,
                'timeout'       => $timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
            )));
            $response = @file_get_contents($url, false, $context);
            $code = 0;
            if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
                $code = (int) $m[1];
            }
            return array($code, $response === false ? null : $response, $response === false ? 'Connection failed.' : '');
        }
    }
}
