<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ClientReportRepository;
use App\Repositories\DailyStatsRepository;
use App\Repositories\DomainRepository;
use App\Repositories\IncidentRepository;
use App\Repositories\ReportBrandRepository;
use App\Repositories\VitalsRepository;
use App\Repositories\WebsiteRepository;

/**
 * Branded client reports: brand kits, saved reports with a share link, and the data a report shows.
 *
 * A saved report stores its scope (a client, or chosen websites), a period and the sections to show. Rolling
 * periods ("last 30 days", "last month") are resolved every time the report is opened, so a share link sent to a
 * client always shows current figures. The same data feeds the web page (report.php) and the PDF
 * (ClientReportRenderer).
 */
final class ClientReportService
{
    public const PERIODS = [
        '7d'         => 'Last 7 days',
        '30d'        => 'Last 30 days',
        '90d'        => 'Last 90 days',
        'this_month' => 'This month so far',
        'last_month' => 'Last calendar month',
        'custom'     => 'Custom dates',
    ];

    /** @var array<string, array{0: string, 1: string}> key => [label, description] */
    public const SECTIONS = [
        'summary'      => ['Executive summary', 'Health verdict, key figures and a plain-language overview'],
        'availability' => ['Availability chart', 'Uptime for every day of the period'],
        'response'     => ['Response time chart', 'Average response time for every day of the period'],
        'websites'     => ['Website breakdown', 'Uptime, downtime, incidents and speed per website'],
        'incidents'    => ['Incident log', 'Every confirmed outage with its cause and duration'],
        'performance'  => ['Page speed scores', 'Latest Lighthouse scores and Core Web Vitals'],
        'security'     => ['SSL & domain renewals', 'Certificate and domain registration expiry dates'],
    ];

    public const DEFAULT_PRIMARY = '#EA580C';
    public const DEFAULT_ACCENT = '#0F172A';

    /** Incidents listed in a report; the rest are counted. */
    public const MAX_INCIDENTS = 60;

    public function __construct(
        private readonly ClientReportRepository $reports,
        private readonly ReportBrandRepository $brands,
        private readonly WebsiteRepository $websites,
        private readonly ReportService $reportService,
        private readonly DailyStatsRepository $dailyStats,
        private readonly IncidentRepository $incidents,
        private readonly VitalsRepository $vitals,
        private readonly DomainRepository $domains
    ) {
    }

    // ------------------------------------------------------------------
    // Brand kits
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $in
     * @return array{data: array<string, mixed>, errors: array<string, string>}
     */
    public function validateBrand(array $in): array
    {
        $errors = [];
        $text = static fn (string $key, int $max): string => mb_substr(trim((string) ($in[$key] ?? '')), 0, $max);

        $name = $text('name', 150);
        if ($name === '') {
            $errors['name'] = 'Enter the brand name shown on reports.';
        }
        $colors = [];
        foreach (['primary_color' => self::DEFAULT_PRIMARY, 'accent_color' => self::DEFAULT_ACCENT] as $key => $default) {
            $value = strtoupper(trim((string) ($in[$key] ?? '')));
            if ($value === '') {
                $value = $default;
            } elseif (preg_match('/^#[0-9A-F]{6}$/', $value) !== 1) {
                $errors[$key] = 'Use a hex colour such as #EA580C.';
            }
            $colors[$key] = $value;
        }
        $website = $text('website', 255);
        if ($website !== '' && !preg_match('#^https?://#i', $website)) {
            $website = 'https://' . $website;
        }
        if ($website !== '' && (filter_var($website, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $website))) {
            $errors['website'] = 'Enter a web address such as https://example.com.';
        }
        $email = $text('email', 190);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address.';
        }

        return [
            'data' => [
                'name'          => $name,
                'client_name'   => $text('client_name', 150),
                'primary_color' => $colors['primary_color'],
                'accent_color'  => $colors['accent_color'],
                'prepared_by'   => $text('prepared_by', 150) ?: null,
                'website'       => $website ?: null,
                'email'         => $email ?: null,
                'phone'         => $text('phone', 60) ?: null,
                'footer_text'   => $text('footer_text', 500) ?: null,
                'white_label'   => filter_var($in['white_label'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            ],
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $b
     * @return array<string, mixed>
     */
    public static function presentBrand(array $b): array
    {
        return [
            'id'            => (int) $b['id'],
            'name'          => (string) $b['name'],
            'client_name'   => (string) $b['client_name'],
            'logo_url'      => !empty($b['logo']) ? base_url('api/brands/logo.php') . '?id=' . (int) $b['id'] . '&v=' . rawurlencode(pathinfo((string) $b['logo'], PATHINFO_FILENAME)) : null,
            'primary_color' => (string) $b['primary_color'],
            'accent_color'  => (string) $b['accent_color'],
            'prepared_by'   => (string) ($b['prepared_by'] ?? ''),
            'website'       => (string) ($b['website'] ?? ''),
            'email'         => (string) ($b['email'] ?? ''),
            'phone'         => (string) ($b['phone'] ?? ''),
            'footer_text'   => (string) ($b['footer_text'] ?? ''),
            'white_label'   => (int) ($b['white_label'] ?? 0) === 1,
            'report_count'  => (int) ($b['report_count'] ?? 0),
            'updated_label' => format_datetime($b['updated_at'] ?? null, 'j M Y'),
        ];
    }

    /**
     * The brand a report is shown in: its own brand, else the brand linked to its client, else a neutral
     * default built from the application name.
     *
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    public function brandFor(array $report): array
    {
        $brand = null;
        if (!empty($report['brand_id'])) {
            $brand = $this->brands->find((int) $report['brand_id']);
        }
        $brand ??= $this->brands->forClient((string) ($report['client_name'] ?? ''));
        if ($brand !== null) {
            return $brand;
        }
        return [
            'id' => 0, 'name' => (string) ($report['client_name'] ?: config('app.name', 'SiteWatch')), 'client_name' => '',
            'logo' => null, 'primary_color' => self::DEFAULT_PRIMARY, 'accent_color' => self::DEFAULT_ACCENT,
            'prepared_by' => (string) config('app.name', 'SiteWatch'), 'website' => null, 'email' => null, 'phone' => null,
            'footer_text' => null, 'white_label' => 0,
        ];
    }

    // ------------------------------------------------------------------
    // Saved reports
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $in
     * @return array{data: array<string, mixed>, errors: array<string, string>}
     */
    public function validateReport(array $in): array
    {
        $errors = [];
        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 150);
        if ($title === '') {
            $errors['title'] = 'Give the report a title, e.g. "Monthly website report".';
        }

        $brandId = (int) ($in['brand_id'] ?? 0);
        if ($brandId > 0 && $this->brands->find($brandId) === null) {
            $errors['brand_id'] = 'That brand no longer exists.';
        }

        $client = mb_substr(trim((string) ($in['client_name'] ?? '')), 0, 150);
        $known = $this->websites->options();
        if ($client !== '' && !in_array($client, array_column($known, 'client_name'), true)) {
            $errors['client_name'] = 'No website belongs to this client.';
        }

        $requested = $in['website_ids'] ?? [];
        if (is_string($requested)) {
            $requested = $requested === '' ? [] : explode(',', $requested);
        }
        $ids = [];
        foreach ((array) $requested as $id) {
            $id = (int) $id;
            foreach ($known as $w) {
                if ((int) $w['id'] === $id && ($client === '' || $w['client_name'] === $client)) {
                    $ids[$id] = $id;
                }
            }
        }
        if ($requested !== [] && $ids === [] && !isset($errors['client_name'])) {
            $errors['website_ids'] = 'Choose websites that belong to the selected client.';
        }

        $period = (string) ($in['period'] ?? '30d');
        if (!isset(self::PERIODS[$period])) {
            $errors['period'] = 'Choose a period.';
            $period = '30d';
        }
        $from = null;
        $to = null;
        if ($period === 'custom') {
            $from = self::date((string) ($in['date_from'] ?? ''));
            $to = self::date((string) ($in['date_to'] ?? ''));
            if ($from === null) {
                $errors['date_from'] = 'Choose the first day.';
            }
            if ($to === null) {
                $errors['date_to'] = 'Choose the last day.';
            }
            if ($from !== null && $to !== null) {
                if ($from > $to) {
                    $errors['date_to'] = 'The last day must be on or after the first day.';
                } elseif ((new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days > 365) {
                    $errors['date_to'] = 'A report can cover at most one year.';
                }
            }
        }

        $sections = $in['sections'] ?? [];
        if (is_string($sections)) {
            $sections = $sections === '' ? [] : explode(',', $sections);
        }
        $sections = array_values(array_intersect(array_keys(self::SECTIONS), array_map('strval', (array) $sections)));
        if ($sections === []) {
            $errors['sections'] = 'Choose at least one section.';
        }

        $expiresAt = null;
        $expires = trim((string) ($in['expires_on'] ?? ''));
        if ($expires !== '') {
            $date = self::date($expires);
            $expiresAt = $date !== null ? IncidentRepository::localDateToUtc($date, true) : null;
            if ($expiresAt === null) {
                $errors['expires_on'] = 'Choose a valid date.';
            } elseif ($expiresAt <= utc_now()->format('Y-m-d H:i:s')) {
                $errors['expires_on'] = 'Choose a date in the future, or leave it empty for a link that does not expire.';
            }
        }

        return [
            'data' => [
                'title'       => $title,
                'brand_id'    => $brandId > 0 ? $brandId : null,
                'client_name' => $client,
                'website_ids' => $ids === [] ? null : json_encode(array_values($ids)),
                'period'      => $period,
                'date_from'   => $from,
                'date_to'     => $to,
                'sections'    => json_encode($sections),
                'intro'       => ($intro = mb_substr(trim((string) ($in['intro'] ?? '')), 0, 2000)) !== '' ? $intro : null,
                'is_active'   => filter_var($in['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                'expires_at'  => $expiresAt,
            ],
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    public function presentReport(array $r): array
    {
        $range = $this->periodRange($r);
        $status = self::linkStatus($r);
        $ids = self::decodeIds($r['website_ids'] ?? null);
        return [
            'id'              => (int) $r['id'],
            'title'           => (string) $r['title'],
            'brand_id'        => $r['brand_id'] !== null ? (int) $r['brand_id'] : null,
            'brand_name'      => $r['brand_name'] ?? null,
            'client_name'     => (string) $r['client_name'],
            'website_ids'     => $ids,
            'scope_label'     => $ids !== [] ? count($ids) . ' selected website' . (count($ids) === 1 ? '' : 's') : ($r['client_name'] !== '' ? 'All websites of ' . $r['client_name'] : 'All websites'),
            'period'          => (string) $r['period'],
            'period_label'    => self::PERIODS[$r['period']] ?? $r['period'],
            'range_label'     => $range['label'],
            'date_from'       => $r['date_from'],
            'date_to'         => $r['date_to'],
            'sections'        => self::decodeSections($r['sections'] ?? null),
            'intro'           => (string) ($r['intro'] ?? ''),
            'is_active'       => (int) $r['is_active'] === 1,
            'expires_on'      => $r['expires_at'] !== null ? to_local((string) $r['expires_at'])?->format('Y-m-d') : null,
            'expires_label'   => $r['expires_at'] !== null ? format_datetime((string) $r['expires_at'], 'j M Y') : 'Never',
            'status'          => $status,
            'view_count'      => (int) $r['view_count'],
            'last_viewed'     => $r['last_viewed_at'] !== null ? time_ago((string) $r['last_viewed_at']) : null,
            'created_by_name' => $r['created_by_name'] ?? null,
            'updated_label'   => format_datetime((string) $r['updated_at'], 'j M Y'),
            'share_url'       => self::shareUrl((string) $r['token']),
            'share_pdf_url'   => self::shareUrl((string) $r['token'], true),
            'pdf_url'         => base_url('api/client-reports/pdf.php') . '?id=' . (int) $r['id'],
            'preview_url'     => base_url('api/client-reports/pdf.php') . '?id=' . (int) $r['id'] . '&format=html',
        ];
    }

    public static function shareUrl(string $token, bool $pdf = false): string
    {
        return base_url('r/' . $token . ($pdf ? '.pdf' : ''));
    }

    /**
     * active | disabled | expired
     *
     * @param array<string, mixed> $r
     */
    public static function linkStatus(array $r): string
    {
        if ((int) $r['is_active'] !== 1) {
            return 'disabled';
        }
        if ($r['expires_at'] !== null && (string) $r['expires_at'] <= utc_now()->format('Y-m-d H:i:s')) {
            return 'expired';
        }
        return 'active';
    }

    /**
     * Local date range a report covers.
     *
     * @param array<string, mixed> $r
     * @return array{from: string, to: string, days: int, label: string}
     */
    public function periodRange(array $r): array
    {
        $today = utc_now()->setTimezone(app_timezone());
        $range = match ((string) ($r['period'] ?? '30d')) {
            '7d'         => ReportService::range($today->modify('-6 days')->format('Y-m-d'), $today->format('Y-m-d')),
            '90d'        => ReportService::range($today->modify('-89 days')->format('Y-m-d'), $today->format('Y-m-d')),
            'this_month' => ReportService::range($today->format('Y-m-01'), $today->format('Y-m-d')),
            'last_month' => ReportService::range(
                $today->modify('first day of last month')->format('Y-m-d'),
                $today->modify('last day of last month')->format('Y-m-d')
            ),
            'custom'     => ReportService::range($r['date_from'] ?? null, $r['date_to'] ?? null),
            default      => ReportService::range($today->modify('-29 days')->format('Y-m-d'), $today->format('Y-m-d')),
        };
        $range['label'] = self::rangeLabel($range['from'], $range['to']);
        return $range;
    }

    public static function rangeLabel(string $from, string $to): string
    {
        $a = new \DateTimeImmutable($from);
        $b = new \DateTimeImmutable($to);
        if ($from === $to) {
            return $a->format('j M Y');
        }
        if ($a->format('Y') === $b->format('Y')) {
            return $a->format('j M') . ' – ' . $b->format('j M Y');
        }
        return $a->format('j M Y') . ' – ' . $b->format('j M Y');
    }

    // ------------------------------------------------------------------
    // Report data
    // ------------------------------------------------------------------

    /**
     * Everything a report page or PDF shows.
     *
     * @param array<string, mixed> $report client_reports row (or the same fields for an unsaved preview)
     * @return array<string, mixed>
     */
    public function build(array $report): array
    {
        $range = $this->periodRange($report);
        $client = (string) ($report['client_name'] ?? '');
        $onlyIds = self::decodeIds($report['website_ids'] ?? null);
        $sections = self::decodeSections($report['sections'] ?? null);

        $filter = static fn (array $rows): array => array_values(array_filter(
            $rows,
            static fn (array $row): bool => $onlyIds === [] || in_array((int) $row['id'], $onlyIds, true)
        ));

        $current = $this->reportService->uptimeReport(['client' => $client, 'from' => $range['from'], 'to' => $range['to']]);
        $rows = $filter($current['rows']);
        usort($rows, static fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));
        $summary = $this->reportService->summarise($rows);
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);

        // The period before, of the same length, for "compared with the previous period".
        $prevTo = (new \DateTimeImmutable($range['from']))->modify('-1 day');
        $prevFrom = $prevTo->modify('-' . ($range['days'] - 1) . ' days');
        $previousRows = $filter($this->reportService->uptimeReport(['client' => $client, 'from' => $prevFrom->format('Y-m-d'), 'to' => $prevTo->format('Y-m-d')])['rows']);
        $previous = $this->reportService->summarise($previousRows);

        // One entry per day, including days without data.
        $byDate = $this->dailyStats->groupDaily($range['from'], $range['to'], $ids);
        $daily = [];
        for ($d = new \DateTimeImmutable($range['from']); $d->format('Y-m-d') <= $range['to']; $d = $d->modify('+1 day')) {
            $key = $d->format('Y-m-d');
            $daily[] = ['date' => $key] + ($byDate[$key] ?? ['uptime' => null, 'avg' => null, 'checks' => 0, 'down' => 0, 'incidents' => 0]);
        }

        $incidents = [];
        $incidentTotal = 0;
        if ($ids !== [] && in_array('incidents', $sections, true)) {
            foreach ($this->incidents->searchAll(['client' => $client, 'from' => $range['from'], 'to' => $range['to']], 2000) as $i) {
                if (!in_array((int) $i['website_id'], $ids, true)) {
                    continue;
                }
                $incidentTotal++;
                if (count($incidents) < self::MAX_INCIDENTS) {
                    $incidents[] = ReportService::presentIncident($i);
                }
            }
        }

        $performance = [];
        if ($ids !== [] && in_array('performance', $sections, true)) {
            $mobile = $this->vitals->latestPerWebsite('mobile');
            $desktop = $this->vitals->latestPerWebsite('desktop');
            foreach ($rows as $row) {
                $m = $mobile[(int) $row['id']] ?? null;
                $dk = $desktop[(int) $row['id']] ?? null;
                if ($m === null && $dk === null) {
                    continue;
                }
                $performance[] = [
                    'name'    => $row['name'],
                    'domain'  => $row['domain'],
                    'mobile'  => $m !== null && $m['performance_score'] !== null ? (int) $m['performance_score'] : null,
                    'desktop' => $dk !== null && $dk['performance_score'] !== null ? (int) $dk['performance_score'] : null,
                    'lcp'     => $m !== null ? ($m['field_lcp'] ?? $m['lab_lcp']) : null,
                    'cls'     => $m !== null ? ($m['field_cls'] ?? $m['lab_cls']) : null,
                    'tested'  => format_datetime((string) ($m['fetched_at'] ?? $dk['fetched_at']), 'j M Y'),
                ];
            }
        }

        $security = [];
        if ($ids !== [] && in_array('security', $sections, true)) {
            $domains = $this->domains->forWebsites($ids);
            $now = utc_now();
            foreach ($rows as $row) {
                $d = $domains[(int) $row['id']] ?? null;
                $domainDays = null;
                if ($d !== null && $d['expires_at'] !== null) {
                    $domainDays = (int) floor((strtotime($d['expires_at'] . ' UTC') - $now->getTimestamp()) / 86400);
                }
                $security[] = [
                    'name'          => $row['name'],
                    'domain'        => $d['domain'] ?? $row['domain'],
                    'ssl'           => $row['ssl'],
                    'domain_expiry' => $d !== null && $d['expires_at'] !== null ? format_datetime((string) $d['expires_at'], 'j M Y') : null,
                    'domain_days'   => $domainDays,
                    'registrar'     => $d['registrar'] ?? null,
                    'hosting'       => $d['hosting_provider'] ?? null,
                ];
            }
        }

        $brand = $this->brandFor($report);

        return [
            'title'        => (string) ($report['title'] ?? 'Website report'),
            'intro'        => (string) ($report['intro'] ?? ''),
            'client'       => $client,
            'brand'        => $brand,
            'sections'     => $sections,
            'range'        => $range,
            'generated'    => format_datetime(utc_now()->format('Y-m-d H:i:s'), 'j M Y, g:i A'),
            'timezone'     => app_timezone()->getName(),
            'summary'      => $summary,
            'previous'     => $previous,
            'verdict'      => self::verdict($summary['uptime'], (int) $summary['checks']),
            'narrative'    => self::narrative($summary, $range, $incidentTotal),
            'rows'         => $rows,
            'daily'        => $daily,
            'incidents'    => $incidents,
            'incident_total' => $incidentTotal,
            'performance'  => $performance,
            'security'     => $security,
        ];
    }

    /**
     * Overall health of the period in one word, for the top of the report.
     *
     * @return array{label: string, tone: string, text: string}
     */
    public static function verdict(?float $uptime, int $checks): array
    {
        if ($uptime === null || $checks === 0) {
            return ['label' => 'No data yet', 'tone' => 'neutral', 'text' => 'Monitoring has not recorded any checks in this period yet.'];
        }
        return match (true) {
            $uptime >= 99.9 => ['label' => 'Excellent', 'tone' => 'success', 'text' => 'Your websites were available almost all of the time.'],
            $uptime >= 99.5 => ['label' => 'Good', 'tone' => 'success', 'text' => 'Your websites were reliably available, with only brief interruptions.'],
            $uptime >= 98.0 => ['label' => 'Fair', 'tone' => 'warning', 'text' => 'There were some interruptions. The incident log below explains each one.'],
            default         => ['label' => 'Needs attention', 'tone' => 'danger', 'text' => 'Availability was below target. The incident log below explains each outage.'],
        };
    }

    /**
     * A short plain-language paragraph describing the period.
     *
     * @param array<string, mixed> $summary
     * @param array{days: int, label: string} $range
     */
    public static function narrative(array $summary, array $range, int $incidents): string
    {
        $sites = (int) $summary['websites'];
        if ($sites === 0) {
            return 'No websites are included in this report yet.';
        }
        if ((int) $summary['checks'] === 0) {
            return sprintf('We are monitoring %d website%s for you. No checks were recorded between %s, so there are no figures to report yet.', $sites, $sites === 1 ? '' : 's', $range['label']);
        }
        $text = sprintf(
            'Between %s we checked %s %s times. %s available %s of the time',
            $range['label'],
            $sites === 1 ? 'your website' : 'your ' . $sites . ' websites',
            number_format((int) $summary['checks']),
            $sites === 1 ? 'It was' : 'Together they were',
            $summary['uptime_label']
        );
        $text .= $incidents === 0
            ? ', with no confirmed outages.'
            : sprintf(', with %d confirmed outage%s adding up to %s of downtime.', $incidents, $incidents === 1 ? '' : 's', $summary['downtime_label']);
        if ($summary['avg_response'] !== null) {
            $text .= ' Pages answered in ' . $summary['avg_response_label'] . ' on average.';
        }
        return $text;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return list<int> */
    public static function decodeIds(mixed $json): array
    {
        $list = is_string($json) ? json_decode($json, true) : null;
        return is_array($list) ? array_values(array_unique(array_map('intval', $list))) : [];
    }

    /** @return list<string> */
    public static function decodeSections(mixed $json): array
    {
        $list = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($list)) {
            return array_keys(self::SECTIONS);
        }
        return array_values(array_intersect(array_keys(self::SECTIONS), array_map('strval', $list)));
    }

    private static function date(string $value): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d !== false && $d->format('Y-m-d') === $value ? $value : null;
    }

    public function reports(): ClientReportRepository
    {
        return $this->reports;
    }

    public function brands(): ReportBrandRepository
    {
        return $this->brands;
    }
}
