<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Countries\Countries;
use App\Countries\CountryClassifier;
use App\Monitoring\Status;
use App\Monitoring\StatusClassifier;
use App\Monitoring\UptimeCalculator;
use Throwable;

/**
 * Everything the Overview tab of a website shows: the health banner and score, the four key figures with their
 * small charts, 90 days of daily uptime and one timeline of recent events.
 */
final class WebsiteOverviewService
{
    /**
     * @param array<string, mixed> $website websites row
     * @param array{incidents?: bool, reports?: bool, domains?: bool} $can
     * @return array<string, mixed>
     */
    public function build(array $website, array $can): array
    {
        $id = (int) $website['id'];
        $settings = App::settings();
        $stats = ServiceFactory::uptime()->forWebsite($id);
        $open = ServiceFactory::incidents()->openFor($id);
        $monitored = (int) $website['monitoring_enabled'] === 1;
        $status = $monitored ? (string) $website['status'] : Status::PAUSED;
        $severity = Status::severity($status);

        // ---- Response time (24 h) -------------------------------------------------------------------------
        $from24 = utc_now()->modify('-24 hours')->format('Y-m-d H:i:s');
        $times = array_map('intval', App::db()->fetchColumnAll(
            'SELECT response_time FROM website_checks WHERE website_id = :id AND checked_at >= :f AND is_failure = 0 AND response_time IS NOT NULL ORDER BY response_time',
            ['id' => $id, 'f' => $from24]
        ));
        $p95 = $times !== [] ? $times[(int) min(count($times) - 1, ceil(count($times) * 0.95) - 1)] : null;
        $spark = array_map(static fn (array $p) => $p['avg'], ServiceFactory::checks()->responseSeries($id, $from24, 3600));
        $week = ServiceFactory::dailyStats()->rangeStats($id, UptimeCalculator::localDate(-6), UptimeCalculator::localDate());
        $prevWeek = ServiceFactory::dailyStats()->rangeStats($id, UptimeCalculator::localDate(-13), UptimeCalculator::localDate(-7));
        $change = $week['avg'] !== null && $prevWeek['avg'] !== null && $prevWeek['avg'] > 0
            ? (int) round(($week['avg'] - $prevWeek['avg']) / $prevWeek['avg'] * 100) : null;

        // ---- Uptime (30 days) -----------------------------------------------------------------------------
        $from30 = utc_now()->modify('-30 days')->format('Y-m-d H:i:s');
        $downtime = (int) (ServiceFactory::incidents()->downtimeSecondsPerWebsite($from30, utc_now()->format('Y-m-d H:i:s'))[$id] ?? 0);

        // ---- Certificate and domain ---------------------------------------------------------------------------
        $sslDays = StatusClassifier::sslDaysRemaining($website);
        $isHttps = str_starts_with(strtolower((string) $website['url']), 'https://');
        $ssl = WebsiteService::presentSsl($website, $sslDays, $isHttps, StatusClassifier::enabledChecks($website)['ssl']);
        $domain = null;
        if (!empty($can['domains'])) {
            $d = ServiceFactory::domains()->findForWebsite($id);
            if ($d !== null) {
                $expiresDays = $d['expires_at'] ? (int) floor((strtotime($d['expires_at'] . ' UTC') - time()) / 86400) : null;
                $domain = [
                    'expires_days'  => $expiresDays,
                    'expires_label' => format_date($d['expires_at']),
                    'age_label'     => $d['registered_at'] ? self::ageLabel((string) $d['registered_at']) : null,
                    'registrar'     => $d['registrar'],
                    'host'          => $d['hosting_provider'],
                    'country'       => $d['country_code'],
                ];
            }
        }

        // ---- Banner --------------------------------------------------------------------------------------
        if (!$monitored) {
            $state = 'paused';
            $since = null;
        } elseif ($open !== null) {
            $state = 'down';
            $since = (string) $open['started_at'];
        } else {
            $state = $severity === 'warning' ? 'warning' : ($website['last_checked_at'] ? 'up' : 'pending');
            $lastResolved = App::db()->fetchColumn('SELECT MAX(resolved_at) FROM incidents WHERE website_id = :id', ['id' => $id]);
            $since = is_string($lastResolved) && $lastResolved !== '' ? $lastResolved : (string) $website['created_at'];
        }
        $screenshot = null;
        if (!empty($can['reports'])) {
            try {
                $shot = ServiceFactory::screenshots()->latest($id);
                if ($shot !== null) {
                    $screenshot = [
                        'url'            => base_url('api/websites/screenshot.php?id=' . (int) $shot['id']),
                        'captured_at'    => $shot['captured_at'] ?? null,
                        'captured_label' => format_datetime($shot['captured_at'] ?? null),
                        'provider'       => (string) ($shot['provider'] ?? ''),
                    ];
                }
            } catch (Throwable) {
                // Screenshots are optional.
            }
        }

        // ---- 90 days --------------------------------------------------------------------------------------
        $rows = [];
        foreach (ServiceFactory::dailyStats()->daily($id, UptimeCalculator::localDate(-89), UptimeCalculator::localDate()) as $row) {
            $rows[(string) $row['stat_date']] = $row;
        }
        $days = [];
        $tz = app_timezone();
        $start = new \DateTimeImmutable(UptimeCalculator::localDate(-89), $tz);
        for ($i = 0; $i < 90; $i++) {
            $day = $start->modify("+{$i} days");
            $r = $rows[$day->format('Y-m-d')] ?? null;
            $days[] = [
                'label'     => $day->format('D j M'),
                'total'     => $r ? (int) $r['total_checks'] : 0,
                'uptime'    => $r && $r['uptime_percentage'] !== null ? (float) $r['uptime_percentage'] : null,
                'incidents' => $r ? (int) $r['incident_count'] : 0,
                'avg'       => $r && $r['average_response_time'] !== null ? (int) $r['average_response_time'] : null,
            ];
        }

        $score = $this->score($state, $stats['uptime_30d'], $stats['avg_24h'], $ssl, $isHttps, $domain, (int) $stats['incidents_30d'], $settings);

        return [
            'banner' => [
                'state'           => $state,
                'status_label'    => Status::label($status),
                'since'           => $since,
                'last_checked_at' => $website['last_checked_at'],
                'last_http'       => $website['last_http_status'] !== null ? (int) $website['last_http_status'] : null,
                'last_rt'         => $website['last_response_time'] !== null ? (int) $website['last_response_time'] : null,
                'next_check_at'   => $monitored ? $website['next_check_at'] : null,
                'error'           => $severity !== 'ok' ? $website['last_error_message'] : null,
                'screenshot'      => $screenshot,
            ],
            'score' => $score,
            'tiles' => [
                'uptime'   => ['value' => $stats['uptime_30d'], 'downtime' => $downtime, 'downtime_label' => format_duration($downtime, 'none'), 'incidents' => (int) $stats['incidents_30d']],
                'response' => ['avg' => $stats['avg_24h'], 'p95' => $p95, 'change' => $change, 'spark' => $spark, 'checks' => (int) $stats['checks_24h']],
                'ssl'      => $ssl + ['days' => $sslDays],
                'domain'   => $domain,
            ],
            'days'   => $days,
            'uptime_90d' => $stats['uptime_90d'],
            'events' => $this->events($website, $can),
        ];
    }

    /**
     * Health score out of 100, with the reason for every point so it can be explained on screen.
     *
     * @param array<string, mixed>      $ssl
     * @param array<string, mixed>|null $domain
     * @return array{value: ?int, parts: list<array{label: string, points: int, max: int, note: string}>}
     */
    private function score(string $state, ?float $uptime, ?float $avg, array $ssl, bool $isHttps, ?array $domain, int $incidents, \App\Repositories\SettingsRepository $settings): array
    {
        if ($state === 'paused' || $state === 'pending') {
            return ['value' => null, 'parts' => []];
        }
        $parts = [];
        $u = $uptime === null ? 0 : (int) round(max(0.0, min(1.0, ($uptime - 95) / 5)) * 40);
        $parts[] = ['label' => 'Uptime, 30 days', 'points' => $u, 'max' => 40, 'note' => $uptime === null ? 'no checks yet' : format_uptime($uptime) . ' (95% or less scores 0)'];

        $moderate = $settings->getInt('moderate_threshold', 2000);
        $slow = $settings->getInt('slow_threshold', 5000);
        $critical = $settings->getInt('critical_performance_threshold', 10000);
        if ($avg === null) {
            $s = 10;
            $note = 'no responses in 24 hours';
        } elseif ($avg <= $moderate) {
            $s = 20;
            $note = format_ms($avg) . ' on average';
        } elseif ($avg >= $critical) {
            $s = 0;
            $note = format_ms($avg) . ', critically slow';
        } else {
            $s = (int) round(20 - ($avg - $moderate) / max(1, $critical - $moderate) * 20);
            $note = format_ms($avg) . ($avg >= $slow ? ', slow' : '');
        }
        $parts[] = ['label' => 'Speed, 24 hours', 'points' => $s, 'max' => 20, 'note' => $note];

        $i = max(0, 15 - 5 * $incidents);
        $parts[] = ['label' => 'Incidents, 30 days', 'points' => $i, 'max' => 15, 'note' => $incidents === 0 ? 'none' : $incidents . ' confirmed'];

        $days = $ssl['days_remaining'] ?? null;
        if (!$isHttps) {
            $c = 0;
            $note = 'no HTTPS';
        } elseif (empty($ssl['applicable'])) {
            $c = 15;
            $note = 'not monitored';
        } elseif ($ssl['valid'] === false) {
            $c = 0;
            $note = 'certificate invalid';
        } else {
            $c = $days === null ? 15 : ($days > 30 ? 15 : ($days > 7 ? 8 : 0));
            $note = $days === null ? 'valid' : $days . ' days left';
        }
        $parts[] = ['label' => 'SSL certificate', 'points' => $c, 'max' => 15, 'note' => $note];

        $dd = $domain['expires_days'] ?? null;
        $dp = $dd === null ? 10 : ($dd > 60 ? 10 : ($dd > 30 ? 6 : ($dd >= 0 ? 2 : 0)));
        $parts[] = ['label' => 'Domain registration', 'points' => $dp, 'max' => 10, 'note' => $dd === null ? 'expiry unknown' : ($dd >= 0 ? $dd . ' days left' : 'expired')];

        $value = array_sum(array_column($parts, 'points'));
        if ($state === 'down') {
            // A site that is down right now is never healthy, whatever its history.
            $value = min($value, 40);
        }
        return ['value' => $value, 'parts' => $parts];
    }

    /**
     * Incidents, WordPress events, country changes and changes made in SiteWatch, newest first.
     *
     * @param array<string, mixed> $website
     * @param array{incidents?: bool} $can
     * @return list<array{at: string, tone: string, title: string, meta: string, icon: string}>
     */
    private function events(array $website, array $can): array
    {
        $id = (int) $website['id'];
        $events = [];
        if (!empty($can['incidents'])) {
            foreach (ServiceFactory::incidents()->search(['website_id' => $id], 6, 0)['rows'] as $i) {
                if ($i['resolved_at']) {
                    $events[] = ['at' => (string) $i['resolved_at'], 'tone' => 'success', 'icon' => 'bi-check-circle', 'title' => 'Back online',
                        'meta' => 'after ' . format_duration((int) ($i['duration_seconds'] ?? 0)) . ' · ' . $i['title']];
                }
                $events[] = ['at' => (string) $i['started_at'], 'tone' => 'danger', 'icon' => 'bi-exclamation-octagon', 'title' => (string) $i['title'],
                    'meta' => $i['http_status'] ? 'HTTP ' . (int) $i['http_status'] : Status::incidentTypeLabel((string) $i['type'])];
            }
        }
        try {
            foreach ((new \App\Repositories\ConnectorRepository(App::db()))->events($id, null, 5, ['heartbeat']) as $e) {
                $tone = ($e['severity'] ?? '') === 'critical' ? 'danger' : (($e['severity'] ?? '') === 'warning' ? 'warning' : 'neutral');
                $events[] = ['at' => (string) $e['last_occurred_at'], 'tone' => $tone, 'icon' => 'bi-wordpress', 'title' => (string) $e['title'], 'meta' => 'WordPress'];
            }
        } catch (Throwable) {
            // No plugin on this site.
        }
        try {
            foreach (App::db()->fetchAll('SELECT country, result, changed_at FROM country_status WHERE website_id = :id AND changed_at >= :f', ['id' => $id, 'f' => utc_now()->modify('-30 days')->format('Y-m-d H:i:s')]) as $c) {
                if (!in_array($c['result'], CountryClassifier::PROBLEMS, true)) {
                    continue;
                }
                $events[] = ['at' => (string) $c['changed_at'], 'tone' => 'warning', 'icon' => 'bi-globe-europe-africa',
                    'title' => 'Not opening from ' . Countries::name((string) $c['country']), 'meta' => CountryClassifier::LABELS[$c['result']] ?? (string) $c['result']];
            }
        } catch (Throwable) {
            // Country checks not set up.
        }
        foreach (ServiceFactory::activity()->recent(6, $id) as $a) {
            if (in_array($a['action'], ['incident.opened', 'incident.resolved'], true)) {
                continue;
            }
            $events[] = ['at' => (string) $a['created_at'], 'tone' => $a['action'] === 'website.warning' ? 'warning' : 'neutral', 'icon' => 'bi-clock-history', 'title' => (string) $a['description'],
                'meta' => $a['user_name'] ? 'by ' . $a['user_name'] : 'SiteWatch'];
        }
        usort($events, static fn (array $a, array $b): int => strcmp($b['at'], $a['at']));
        return array_slice($events, 0, 8);
    }

    private static function ageLabel(string $registeredUtc): string
    {
        $diff = (new \DateTimeImmutable($registeredUtc))->diff(utc_now());
        if ($diff->y >= 1) {
            return $diff->y . ' year' . ($diff->y === 1 ? '' : 's') . ' old';
        }
        return max(1, $diff->m) . ' month' . ($diff->m === 1 ? '' : 's') . ' old';
    }
}
