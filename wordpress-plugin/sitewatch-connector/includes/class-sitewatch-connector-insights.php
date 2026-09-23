<?php
/**
 * Two views from inside WordPress, both controlled from SiteWatch (Monitoring Settings → WordPress plugin) and sent
 * with the daily health report:
 *
 *   PHP warnings (off unless switched on): warnings, notices and deprecations counted per file and line. The handler
 *   only counts and then hands the error on unchanged, so logging and display work as before. Deprecations matter
 *   before a PHP upgrade: today's deprecation is the next PHP version's fatal error.
 *
 *   Page speed (1 in 20 requests by default): page generation time, database query count and peak memory of a
 *   sample of requests, reported as percentiles. With SAVEQUERIES on, sampled requests also note queries slower
 *   than 50 ms, with numbers and strings replaced by "?" so no content leaves the site.
 *
 * Cost: an array increment per warning, and one small file write (a few KB) at the end of each request that saw
 * warnings and of each sampled request. Files live in wp-content/sitewatch-connector/; a file another request is
 * writing is skipped rather than waited for, so counts are a lower bound.
 */

defined('ABSPATH') || exit;

if (!class_exists('SiteWatch_Connector_Insights')) {

    final class SiteWatch_Connector_Insights
    {
        /** Autoloaded, so reading it costs no query: {warnings: bool, sample: int (0 = off)}. */
        const OPTION = 'sitewatch_connector_insights';
        const DEFAULT_SAMPLE = 20;
        const MAX_PLACES = 200;
        const MAX_PER_REQUEST = 50;
        const MAX_SAMPLES = 1000;
        const SLOW_QUERY = 0.05;
        const MAX_SLOW = 30;

        private static $initialised = false;
        private static $warnings = array();
        private static $previous = null;
        private static $sampled = false;

        public static function init()
        {
            if (self::$initialised) {
                return;
            }
            self::$initialised = true;
            $settings = self::settings();
            if ($settings['warnings']) {
                self::$previous = set_error_handler(
                    array(__CLASS__, 'on_error'),
                    E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING | E_USER_NOTICE | E_USER_DEPRECATED
                );
            }
            if ($settings['sample'] > 0 && PHP_SAPI !== 'cli' && mt_rand(1, $settings['sample']) === 1) {
                self::$sampled = true;
            }
            register_shutdown_function(array(__CLASS__, 'on_shutdown'));
        }

        /** @return array{warnings: bool, sample: int} */
        public static function settings()
        {
            $saved = function_exists('get_option') ? get_option(self::OPTION) : null;
            $saved = is_array($saved) ? $saved : array();
            return array(
                'warnings' => !empty($saved['warnings']),
                'sample'   => isset($saved['sample']) ? max(0, min(1000, (int) $saved['sample'])) : self::DEFAULT_SAMPLE,
            );
        }

        /** Store what SiteWatch asked for in its reply (only when it changed, to avoid a write per report). */
        public static function apply_reply(array $data)
        {
            if (!array_key_exists('collect_warnings', $data) && !array_key_exists('perf_sample', $data)) {
                return;
            }
            $current = self::settings();
            $wanted = array(
                'warnings' => array_key_exists('collect_warnings', $data) ? !empty($data['collect_warnings']) : $current['warnings'],
                'sample'   => array_key_exists('perf_sample', $data) ? max(0, min(1000, (int) $data['perf_sample'])) : $current['sample'],
            );
            if ($wanted !== $current || get_option(self::OPTION) === false) {
                update_option(self::OPTION, $wanted, true);
            }
        }

        // --------------------------------------------------------------
        // Collecting
        // --------------------------------------------------------------

        public static function on_error($errno, $errstr, $errfile = '', $errline = 0)
        {
            $level = error_reporting();
            // Silenced with @: error_reporting() is 0 before PHP 8 and only the fatal levels from PHP 8 on.
            $silenced = $level === 0 || $level === (E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR | E_PARSE);
            if (!$silenced) {
                $key = $errno . '|' . $errfile . '|' . $errline;
                if (isset(self::$warnings[$key])) {
                    self::$warnings[$key]['count']++;
                } elseif (count(self::$warnings) < self::MAX_PER_REQUEST) {
                    self::$warnings[$key] = array('type' => (int) $errno, 'message' => substr((string) $errstr, 0, 400), 'file' => (string) $errfile, 'line' => (int) $errline, 'count' => 1);
                }
            }
            if (self::$previous !== null) {
                return call_user_func(self::$previous, $errno, $errstr, $errfile, $errline);
            }
            return false; // PHP's own handling (log, display) continues unchanged
        }

        public static function on_shutdown()
        {
            try {
                if (self::$warnings !== array()) {
                    self::save_warnings();
                }
                if (self::$sampled) {
                    self::save_sample();
                }
            } catch (Throwable $e) {
                // Never break a request for statistics.
            }
        }

        private static function save_warnings()
        {
            $new = self::$warnings;
            self::$warnings = array();
            $now = time();
            self::update(self::path('warnings'), function (array $data) use ($new, $now) {
                $entries = isset($data['entries']) && is_array($data['entries']) ? $data['entries'] : array();
                foreach ($new as $w) {
                    $id = substr(sha1($w['type'] . '|' . $w['file'] . '|' . $w['line']), 0, 16);
                    if (isset($entries[$id])) {
                        $entries[$id]['count'] += $w['count'];
                        $entries[$id]['last_at'] = $now;
                    } elseif (count($entries) < SiteWatch_Connector_Insights::MAX_PLACES) {
                        $component = SiteWatch_Connector_Errors::component($w['file']);
                        $entries[$id] = array(
                            'type'      => $w['type'],
                            'message'   => SiteWatch_Connector_Errors::scrub($w['message']),
                            'file'      => SiteWatch_Connector_Errors::relative($w['file']),
                            'line'      => $w['line'],
                            'component' => $component,
                            'count'     => $w['count'],
                            'first_at'  => $now,
                            'last_at'   => $now,
                        );
                    }
                }
                return array('since' => isset($data['since']) ? (int) $data['since'] : $now, 'entries' => $entries);
            });
        }

        private static function save_sample()
        {
            if ((defined('DOING_CRON') && DOING_CRON) || (defined('WP_CLI') && WP_CLI)) {
                return;
            }
            global $wpdb;
            $start = isset($GLOBALS['timestart']) && is_numeric($GLOBALS['timestart'])
                ? (float) $GLOBALS['timestart']
                : (isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime(true));
            $status = function_exists('http_response_code') ? (int) http_response_code() : 200;
            $sample = array(
                'ms'  => (int) round((microtime(true) - $start) * 1000),
                'q'   => function_exists('get_num_queries') ? (int) get_num_queries() : 0,
                'mem' => round(memory_get_peak_usage(true) / 1048576, 1),
                'c'   => self::context(),
                's'   => $status > 0 ? $status : 200,
                'p'   => substr((string) strtok(isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '', '?'), 0, 120),
            );
            $slow = array();
            if (defined('SAVEQUERIES') && SAVEQUERIES && isset($wpdb->queries) && is_array($wpdb->queries)) {
                foreach ($wpdb->queries as $q) {
                    if (is_array($q) && isset($q[0], $q[1]) && (float) $q[1] >= self::SLOW_QUERY) {
                        $callers = isset($q[2]) ? explode(',', (string) $q[2]) : array();
                        $slow[] = array('sql' => self::normalise_sql((string) $q[0]), 'ms' => (int) round((float) $q[1] * 1000), 'caller' => substr(trim((string) end($callers)), 0, 120));
                    }
                }
            }
            $now = time();
            self::update(self::path('perf'), function (array $data) use ($sample, $slow, $now) {
                $samples = isset($data['samples']) && is_array($data['samples']) ? $data['samples'] : array();
                $seen = isset($data['seen']) ? (int) $data['seen'] + 1 : 1;
                // Reservoir sampling keeps an even spread over the whole day once the list is full.
                if (count($samples) < SiteWatch_Connector_Insights::MAX_SAMPLES) {
                    $samples[] = $sample;
                } else {
                    $slot = mt_rand(0, $seen - 1);
                    if ($slot < SiteWatch_Connector_Insights::MAX_SAMPLES) {
                        $samples[$slot] = $sample;
                    }
                }
                $queries = isset($data['slow']) && is_array($data['slow']) ? $data['slow'] : array();
                foreach ($slow as $q) {
                    $id = substr(sha1($q['sql']), 0, 16);
                    if (isset($queries[$id])) {
                        $queries[$id]['count']++;
                        $queries[$id]['total_ms'] += $q['ms'];
                        $queries[$id]['max_ms'] = max($queries[$id]['max_ms'], $q['ms']);
                    } elseif (count($queries) < SiteWatch_Connector_Insights::MAX_SLOW) {
                        $queries[$id] = array('sql' => $q['sql'], 'caller' => $q['caller'], 'count' => 1, 'total_ms' => $q['ms'], 'max_ms' => $q['ms']);
                    }
                }
                return array('since' => isset($data['since']) ? (int) $data['since'] : $now, 'seen' => $seen, 'samples' => array_values($samples), 'slow' => $queries);
            });
        }

        /** Literals become "?" so the text says which query is slow without carrying any data. */
        public static function normalise_sql($sql)
        {
            $sql = (string) preg_replace("/'(?:[^'\\\\]|\\\\.)*'/s", '?', $sql);
            $sql = (string) preg_replace('/"(?:[^"\\\\]|\\\\.)*"/s', '?', $sql);
            $sql = (string) preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $sql);
            $sql = (string) preg_replace('/\s+/', ' ', trim($sql));
            $sql = (string) preg_replace('/\(\s*\?(?:\s*,\s*\?)+\s*\)/', '(?, …)', $sql);
            return substr($sql, 0, 400);
        }

        private static function context()
        {
            if (defined('DOING_AJAX') && DOING_AJAX) {
                return 'ajax';
            }
            if (defined('REST_REQUEST') && REST_REQUEST) {
                return 'rest';
            }
            return function_exists('is_admin') && is_admin() ? 'admin' : 'front';
        }

        // --------------------------------------------------------------
        // Daily summaries (sent with the health report)
        // --------------------------------------------------------------

        /** @return array<string, mixed> */
        public static function warnings_summary()
        {
            $labels = array(
                E_WARNING => 'Warning', E_USER_WARNING => 'Warning', E_NOTICE => 'Notice', E_USER_NOTICE => 'Notice',
                E_DEPRECATED => 'Deprecated', E_USER_DEPRECATED => 'Deprecated',
            );
            $data = self::read(self::path('warnings'));
            $entries = isset($data['entries']) && is_array($data['entries']) ? array_values($data['entries']) : array();
            usort($entries, function ($a, $b) {
                return (int) $b['count'] - (int) $a['count'];
            });
            $total = 0;
            foreach ($entries as $e) {
                $total += (int) $e['count'];
            }
            $list = array();
            foreach (array_slice($entries, 0, 50) as $e) {
                $e['type'] = isset($labels[(int) $e['type']]) ? $labels[(int) $e['type']] : 'Warning';
                $list[] = $e;
            }
            return array(
                'enabled' => self::settings()['warnings'],
                'since'   => isset($data['since']) ? (int) $data['since'] : null,
                'total'   => $total,
                'places'  => count($entries),
                'entries' => $list,
            );
        }

        /** @return array<string, mixed> */
        public static function performance_summary()
        {
            $data = self::read(self::path('perf'));
            $samples = isset($data['samples']) && is_array($data['samples']) ? $data['samples'] : array();
            $groups = array();
            foreach ($samples as $s) {
                if (is_array($s) && isset($s['ms'], $s['c'])) {
                    $groups[(string) $s['c']][] = $s;
                }
            }
            $contexts = array();
            foreach ($groups as $context => $list) {
                $ms = array_map('intval', wp_list_pluck($list, 'ms'));
                sort($ms);
                $contexts[$context] = array(
                    'samples'     => count($ms),
                    'p50'         => self::percentile($ms, 50),
                    'p75'         => self::percentile($ms, 75),
                    'p95'         => self::percentile($ms, 95),
                    'p99'         => self::percentile($ms, 99),
                    'max'         => end($ms),
                    'avg_queries' => round(array_sum(wp_list_pluck($list, 'q')) / count($list), 1),
                    'avg_memory'  => round(array_sum(wp_list_pluck($list, 'mem')) / count($list), 1),
                    'errors'      => count(array_filter($list, function ($s) {
                        return isset($s['s']) && (int) $s['s'] >= 500;
                    })),
                );
            }
            usort($samples, function ($a, $b) {
                return (int) $b['ms'] - (int) $a['ms'];
            });
            $slow = isset($data['slow']) && is_array($data['slow']) ? array_values($data['slow']) : array();
            usort($slow, function ($a, $b) {
                return (int) $b['total_ms'] - (int) $a['total_ms'];
            });
            return array(
                'sample_rate'  => self::settings()['sample'],
                'since'        => isset($data['since']) ? (int) $data['since'] : null,
                'requests'     => isset($data['seen']) ? (int) $data['seen'] : 0,
                'contexts'     => $contexts,
                'slowest'      => array_slice($samples, 0, 5),
                'savequeries'  => defined('SAVEQUERIES') && SAVEQUERIES,
                'slow_queries' => array_slice($slow, 0, 10),
            );
        }

        /** Start a new period after the summaries were delivered. */
        public static function reset()
        {
            foreach (array('warnings', 'perf') as $name) {
                if (is_file(self::path($name))) {
                    @unlink(self::path($name));
                }
            }
        }

        private static function percentile(array $sorted, $p)
        {
            $n = count($sorted);
            return $n === 0 ? null : $sorted[max(0, (int) ceil($p / 100 * $n) - 1)];
        }

        // --------------------------------------------------------------
        // Storage
        // --------------------------------------------------------------

        public static function path($name)
        {
            return SiteWatch_Connector_Errors::data_path($name);
        }

        private static function read($file)
        {
            $data = is_readable($file) ? json_decode(SiteWatch_Connector_Errors::unguard((string) @file_get_contents($file)), true) : null;
            return is_array($data) ? $data : array();
        }

        /**
         * Read-modify-write under a lock. A file locked by another request is skipped, never waited for.
         *
         * @param callable $change Receives the current data, returns the new data or null to leave the file alone.
         */
        private static function update($file, $change)
        {
            if (!is_dir(dirname($file))) {
                return; // created (with its access rules) by the first heartbeat
            }
            $handle = @fopen($file, 'c+');
            if ($handle === false) {
                return;
            }
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                $raw = stream_get_contents($handle);
                $data = is_string($raw) && $raw !== '' ? json_decode(SiteWatch_Connector_Errors::unguard($raw), true) : array();
                $result = call_user_func($change, is_array($data) ? $data : array());
                if (is_array($result)) {
                    ftruncate($handle, 0);
                    rewind($handle);
                    fwrite($handle, SiteWatch_Connector_Errors::GUARD . (string) json_encode($result));
                    fflush($handle);
                }
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        }
    }
}
