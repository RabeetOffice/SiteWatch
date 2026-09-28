<?php

declare(strict_types=1);

/**
 * Branded client report (ClientReportRenderer). One template for the share page, the signed-in preview and the
 * PDF, so the three always match. Layout uses tables and floats only: Dompdf has no flexbox or grid.
 *
 * @var array<string, mixed> $report ClientReportService::build() output plus 'charts'
 * @var string               $mode   web | pdf
 * @var array<string, mixed> $opts   logo_src, pdf_url, nonce, font_url
 */

use App\Services\ClientReportRenderer as R;
use App\Services\ClientReportService;

$brand = $report['brand'];
$primary = (string) $brand['primary_color'];
$accent = (string) $brand['accent_color'];
$primaryText = R::readable($primary);
$onPrimary = R::onColor($primary);
$onAccent = R::onColor($accent);
$tint = R::tint($primary, 0.92);
$tintBorder = R::tint($primary, 0.75);
$isPdf = $mode === 'pdf';
$has = static fn (string $section): bool => in_array($section, $report['sections'], true);
$summary = $report['summary'];
$previous = $report['previous'];
$range = $report['range'];
$logo = $opts['logo_src'] ?? null;
$tones = [
    'success' => ['#DCFCE7', '#166534'],
    'warning' => ['#FEF3C7', '#92400E'],
    'danger'  => ['#FEE2E2', '#991B1B'],
    'neutral' => ['#F1F5F9', '#475569'],
];
$pill = static function (string $label, string $tone) use ($tones): string {
    [$bg, $fg] = $tones[$tone] ?? $tones['neutral'];
    return '<span class="pill" style="background:' . $bg . ';color:' . $fg . '">' . e($label) . '</span>';
};
$uptimeTone = static fn (?float $u): string => $u === null ? 'neutral' : ($u >= 99.9 ? 'success' : ($u >= 99 ? 'success' : ($u >= 97 ? 'warning' : 'danger')));
$keep = static fn (int $rows): string => $rows <= 12 ? ' avoid-break' : '';
$scoreTone = static fn (?int $s): string => $s === null ? 'neutral' : ($s >= 90 ? 'success' : ($s >= 50 ? 'warning' : 'danger'));

// "Compared with the previous period" lines under the key figures.
$hasPrevious = (int) $previous['checks'] > 0;
$delta = static function (?float $now, ?float $before, bool $higherIsBetter, callable $format) use ($hasPrevious): string {
    if (!$hasPrevious || $now === null || $before === null) {
        return '<span class="delta muted">No earlier data to compare</span>';
    }
    $diff = $now - $before;
    if (abs($diff) < 0.0005) {
        return '<span class="delta muted">Same as last period</span>';
    }
    $better = $higherIsBetter ? $diff > 0 : $diff < 0;
    return '<span class="delta ' . ($better ? 'up' : 'down') . '">' . ($diff > 0 ? '▲ ' : '▼ ') . e($format(abs($diff))) . ' vs last period</span>';
};
$verdictTone = $tones[$report['verdict']['tone']] ?? $tones['neutral'];
$contact = array_values(array_filter([
    $brand['website'] ? preg_replace('#^https?://#i', '', rtrim((string) $brand['website'], '/')) : null,
    $brand['email'] ?? null,
    $brand['phone'] ?? null,
]));
$dateOnly = static fn (string $ymd): string => (new DateTimeImmutable($ymd))->format('j M');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= e($report['title']) ?> · <?= e($brand['name']) ?> · <?= e($range['label']) ?></title>
<?php if (!$isPdf): ?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<meta name="theme-color" content="<?= e($primary) ?>">
<?php endif; ?>
<style>
<?php if (!$isPdf && !empty($opts['font_url'])): ?>
@font-face { font-family: 'Inter'; font-style: normal; font-weight: 100 900; font-display: swap; src: url('<?= e($opts['font_url']) ?>') format('woff2'); }
<?php endif; ?>
@page { margin: 16mm 15mm 20mm 15mm; }
* { box-sizing: border-box; }
<?= $isPdf ? 'body' : 'html, body' ?> { margin: 0; padding: 0; }
body { font-family: <?= $isPdf ? "'DejaVu Sans', sans-serif" : "'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif" ?>; color: #1E293B; font-size: <?= $isPdf ? '9.2pt' : '15px' ?>; line-height: 1.5; background: <?= $isPdf ? '#FFFFFF' : '#EEF2F6' ?>; }
table { border-collapse: collapse; width: 100%; }
td, th { vertical-align: top; }
h1, h2, h3, p { margin: 0; }
a { color: <?= e($primaryText) ?>; text-decoration: none; }
.muted { color: #64748B; }
.faint { color: #94A3B8; }
.num { text-align: right; white-space: nowrap; }
.nowrap { white-space: nowrap; }

/* Header */
.masthead td { vertical-align: middle; }
.logo { max-height: <?= $isPdf ? '46px' : '56px' ?>; max-width: 240px; }
.brand-word { font-size: <?= $isPdf ? '16pt' : '24px' ?>; font-weight: 800; color: <?= e($accent === '#FFFFFF' ? '#0F172A' : $accent) ?>; letter-spacing: -0.3px; }
.eyebrow { font-size: <?= $isPdf ? '7pt' : '11px' ?>; letter-spacing: 1.6px; text-transform: uppercase; font-weight: 700; color: <?= e($primaryText) ?>; }
.masthead .right { text-align: right; }
.masthead .period { font-size: <?= $isPdf ? '9pt' : '14px' ?>; color: #334155; font-weight: 600; margin-top: 2px; }
.rule { height: 4px; background: <?= e($primary) ?>; border-radius: 2px; margin: <?= $isPdf ? '12px 0 18px' : '20px 0 28px' ?>; }
.title { font-size: <?= $isPdf ? '21pt' : '34px' ?>; line-height: 1.15; font-weight: 800; color: #0F172A; letter-spacing: -0.5px; }
.meta { margin-top: 8px; color: #475569; font-size: <?= $isPdf ? '8.5pt' : '14px' ?>; }
.meta b { color: #0F172A; font-weight: 600; }
.meta .sep { color: #CBD5E1; padding: 0 6px; }

/* Verdict */
.verdict { margin-top: <?= $isPdf ? '16px' : '28px' ?>; border: 1px solid <?= e($tintBorder) ?>; background: <?= e($tint) ?>; border-radius: 12px; }
.verdict td { padding: <?= $isPdf ? '11px 14px' : '16px 20px' ?>; vertical-align: middle; }
.verdict .badge { display: inline-block; padding: <?= $isPdf ? '5px 12px' : '7px 16px' ?>; border-radius: 999px; font-weight: 700; font-size: <?= $isPdf ? '10pt' : '15px' ?>; white-space: nowrap; }
.verdict .lead { font-size: <?= $isPdf ? '8pt' : '12px' ?>; text-transform: uppercase; letter-spacing: 1.2px; color: #64748B; font-weight: 700; }
.verdict .text { color: #1E293B; font-size: <?= $isPdf ? '9.5pt' : '15px' ?>; }

/* Key figures */
.kpis { margin-top: <?= $isPdf ? '14px' : '20px' ?>; border-collapse: separate; border-spacing: <?= $isPdf ? '6px 0' : '10px 0' ?>; margin-left: <?= $isPdf ? '-6px' : '-10px' ?>; width: <?= $isPdf ? 'calc(100% + 12px)' : 'calc(100% + 20px)' ?>; }
.kpi { width: 25%; border: 1px solid #E2E8F0; border-radius: 12px; padding: <?= $isPdf ? '11px 12px' : '18px' ?>; background: #FFFFFF; }
.kpi .label { font-size: <?= $isPdf ? '7.2pt' : '12px' ?>; text-transform: uppercase; letter-spacing: 1px; font-weight: 700; color: #64748B; }
.kpi .value { font-size: <?= $isPdf ? '17pt' : '28px' ?>; font-weight: 800; color: #0F172A; line-height: 1.2; margin-top: 4px; letter-spacing: -0.5px; }
.kpi .accent { height: 3px; width: 28px; border-radius: 2px; background: <?= e($primary) ?>; margin-bottom: 8px; }
.delta { display: block; margin-top: 4px; font-size: <?= $isPdf ? '7pt' : '12px' ?>; font-weight: 600; }
.delta.up { color: #15803D; }
.delta.down { color: #B91C1C; }
.delta.muted { font-weight: 500; color: #94A3B8; }

/* Sections */
.section { margin-top: <?= $isPdf ? '22px' : '40px' ?>; }
.section-head { page-break-after: avoid; margin-bottom: <?= $isPdf ? '9px' : '14px' ?>; }
.section-head h2 { font-size: <?= $isPdf ? '12.5pt' : '20px' ?>; font-weight: 800; color: #0F172A; letter-spacing: -0.2px; }
.section-head h2 .bar { display: inline-block; width: 4px; height: <?= $isPdf ? '12pt' : '18px' ?>; background: <?= e($primary) ?>; border-radius: 2px; margin-right: 8px; vertical-align: <?= $isPdf ? '-2pt' : '-3px' ?>; }
.section-head p { color: #64748B; margin-top: 2px; font-size: <?= $isPdf ? '8.5pt' : '14px' ?>; }
.card { border: 1px solid #E2E8F0; border-radius: 12px; padding: <?= $isPdf ? '12px 14px' : '20px 22px' ?>; background: #FFFFFF; }
.overview { font-size: <?= $isPdf ? '9.8pt' : '16px' ?>; color: #1E293B; line-height: 1.6; }
.message { margin-top: 12px; border-left: 4px solid <?= e($primary) ?>; background: #F8FAFC; border-radius: 0 10px 10px 0; padding: <?= $isPdf ? '10px 14px' : '16px 20px' ?>; color: #334155; }
.message .from { font-size: <?= $isPdf ? '7.5pt' : '12px' ?>; text-transform: uppercase; letter-spacing: 1px; font-weight: 700; color: <?= e($primaryText) ?>; margin-bottom: 4px; }
.chart img { width: 100%; height: <?= $isPdf ? '120px' : '200px' ?>; display: block; }
.axis td { font-size: <?= $isPdf ? '7pt' : '12px' ?>; color: #94A3B8; padding-top: 4px; }
.legend { margin-top: 10px; font-size: <?= $isPdf ? '7.5pt' : '12.5px' ?>; color: #64748B; }
.legend .sw { display: inline-block; width: 9px; height: 9px; border-radius: 2px; margin: 0 4px 0 10px; vertical-align: <?= $isPdf ? '0' : '-1px' ?>; }
.legend .sw:first-child { margin-left: 0; }
.stat-line { margin-top: 10px; }
.stat-line td { font-size: <?= $isPdf ? '8pt' : '13px' ?>; color: #64748B; }
.stat-line b { color: #0F172A; }

/* Tables */
.data th { text-align: left; font-size: <?= $isPdf ? '7pt' : '11.5px' ?>; text-transform: uppercase; letter-spacing: .8px; color: <?= e($onAccent) ?>; background: <?= e($accent) ?>; padding: <?= $isPdf ? '7px 8px' : '11px 12px' ?>; font-weight: 700; }
.data th:first-child { border-radius: 8px 0 0 0; }
.data th:last-child { border-radius: 0 8px 0 0; }
.data th.num { text-align: right; }
.data td { padding: <?= $isPdf ? '7px 8px' : '11px 12px' ?>; border-bottom: 1px solid #EEF2F6; font-size: <?= $isPdf ? '8.3pt' : '14px' ?>; }
.data tr:nth-child(even) td { background: #F8FAFC; }
.data tr { page-break-inside: avoid; }
.data .site { font-weight: 600; color: #0F172A; }
.data .sub { font-size: <?= $isPdf ? '7.3pt' : '12px' ?>; color: #94A3B8; }
.pill { display: inline-block; padding: <?= $isPdf ? '2px 7px' : '3px 10px' ?>; border-radius: 999px; font-size: <?= $isPdf ? '7.3pt' : '12px' ?>; font-weight: 600; white-space: nowrap; }
.score { display: inline-block; min-width: <?= $isPdf ? '28px' : '40px' ?>; text-align: center; padding: <?= $isPdf ? '2px 6px' : '4px 8px' ?>; border-radius: 999px; font-weight: 700; }
.empty { color: #64748B; font-style: italic; }
.more { margin-top: 8px; font-size: <?= $isPdf ? '8pt' : '13px' ?>; color: #64748B; }
.avoid-break { page-break-inside: avoid; }

/* Footer */
.foot { color: #64748B; font-size: <?= $isPdf ? '7.5pt' : '13px' ?>; }
.foot b { color: #0F172A; }
<?php if ($isPdf): ?>
.data th:first-child, .data th:last-child { border-radius: 0; }
<?php else: ?>
.toolbar { position: sticky; top: 0; z-index: 10; background: rgba(255,255,255,.92); backdrop-filter: saturate(1.4) blur(10px); -webkit-backdrop-filter: saturate(1.4) blur(10px); border-bottom: 1px solid #E2E8F0; }
.toolbar .inner { max-width: 1000px; margin: 0 auto; padding: 10px 24px; display: flex; align-items: center; gap: 12px; }
.toolbar .who { flex: 1; min-width: 0; font-weight: 700; color: #0F172A; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.toolbar .who img { max-height: 28px; max-width: 120px; vertical-align: middle; margin-right: 10px; }
.toolbar .who span { vertical-align: middle; }
.btn { display: inline-block; border: 1px solid #CBD5E1; background: #FFFFFF; color: #0F172A; font: inherit; font-size: 14px; font-weight: 600; padding: 8px 16px; border-radius: 10px; cursor: pointer; white-space: nowrap; line-height: 1.4; }
.btn:hover { background: #F8FAFC; }
.btn-primary { background: <?= e($primary) ?>; border-color: <?= e($primary) ?>; color: <?= e($onPrimary) ?>; }
.btn-primary:hover { background: <?= e(R::shade($primary, 0.1)) ?>; }
.btn svg { vertical-align: -3px; margin-right: 6px; }
.paper { max-width: 1000px; margin: 32px auto 48px; background: #FFFFFF; border-radius: 20px; box-shadow: 0 1px 2px rgba(15,23,42,.06), 0 12px 40px rgba(15,23,42,.08); padding: 56px 60px 40px; }
.foot-web { margin-top: 48px; padding-top: 20px; border-top: 1px solid #E2E8F0; }
.foot-web .right { text-align: right; }
.table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
@media (max-width: 760px) {
    .toolbar .inner { padding: 10px 16px; }
    .toolbar .who img { display: none; }
    .btn { padding: 8px 12px; }
    .btn .long { display: none; }
    .paper { margin: 0; border-radius: 0; padding: 28px 16px 32px; box-shadow: none; }
    .masthead td { display: block; width: 100% !important; }
    .masthead .right { text-align: left; margin-top: 14px; }
    .title { font-size: 27px; }
    .verdict td { display: block; width: 100% !important; }
    .verdict td + td { padding-top: 0; }
    .kpis { border-spacing: 0; margin-left: 0; width: 100%; }
    .kpis tbody, .kpis tr { display: block; }
    .kpis tr { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .kpis td.kpi { display: block; width: auto; padding: 14px; }
    .kpi .value { font-size: 23px; }
    .chart img { height: 150px; }
    .data { min-width: 640px; }
    .hide-sm { display: none; }
    .foot-web td { display: block; width: 100% !important; text-align: left !important; }
}
@media print {
    body { background: #FFFFFF; }
    .toolbar { display: none; }
    .paper { margin: 0; padding: 0; box-shadow: none; border-radius: 0; max-width: none; }
}
<?php endif; ?>
</style>
</head>
<body>
<?php if (!$isPdf): ?>
<div class="toolbar">
    <div class="inner">
        <div class="who"><?php if ($logo): ?><img src="<?= e($logo) ?>" alt=""><?php endif; ?><span><?= e($report['title']) ?></span></div>
        <button type="button" class="btn" id="printReport"><svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M4 1.75A.75.75 0 0 1 4.75 1h6.5a.75.75 0 0 1 .75.75V4h1.25A1.75 1.75 0 0 1 15 5.75v5.5A1.75 1.75 0 0 1 13.25 13H12v1.25a.75.75 0 0 1-.75.75h-6.5a.75.75 0 0 1-.75-.75V13H2.75A1.75 1.75 0 0 1 1 11.25v-5.5A1.75 1.75 0 0 1 2.75 4H4V1.75ZM5.5 4h5V2.5h-5V4Zm0 6.5v3h5v-3h-5Z"/></svg><span class="long">Print</span></button>
        <?php if (!empty($opts['pdf_url'])): ?>
        <a class="btn btn-primary" href="<?= e($opts['pdf_url']) ?>" download><svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 1a.75.75 0 0 1 .75.75v6.69l2.22-2.22a.75.75 0 1 1 1.06 1.06l-3.5 3.5a.75.75 0 0 1-1.06 0l-3.5-3.5a.75.75 0 0 1 1.06-1.06l2.22 2.22V1.75A.75.75 0 0 1 8 1ZM2.75 12a.75.75 0 0 1 .75.75v.75h9v-.75a.75.75 0 0 1 1.5 0v1.5a.75.75 0 0 1-.75.75H2.75a.75.75 0 0 1-.75-.75v-1.5a.75.75 0 0 1 .75-.75Z"/></svg>Download PDF</a>
        <?php endif; ?>
    </div>
</div>
<main class="paper">
<?php endif; ?>

<table class="masthead"><tr>
    <td style="width:55%">
        <?php if ($logo): ?>
            <img class="logo" src="<?= e($logo) ?>" alt="<?= e($brand['name']) ?>">
        <?php else: ?>
            <div class="brand-word"><?= e($brand['name']) ?></div>
        <?php endif; ?>
    </td>
    <td class="right" style="width:45%">
        <div class="eyebrow">Website performance report</div>
        <div class="period"><?= e($range['label']) ?></div>
    </td>
</tr></table>

<div class="rule"></div>

<h1 class="title"><?= e($report['title']) ?></h1>
<p class="meta">
    <span>Prepared for <b><?= e($brand['name']) ?></b></span>
    <?php if (!empty($brand['prepared_by'])): ?><span class="sep">|</span><span>by <b><?= e($brand['prepared_by']) ?></b></span><?php endif; ?>
    <span class="sep">|</span><span><?= (int) $summary['websites'] ?> website<?= (int) $summary['websites'] === 1 ? '' : 's' ?></span>
    <span class="sep">|</span><span>Generated <?= e($report['generated']) ?></span>
</p>

<?php if ($has('summary')): ?>
<table class="verdict avoid-break"><tr>
    <td style="width:<?= $isPdf ? '26%' : '24%' ?>">
        <div class="lead">Overall health</div>
        <span class="badge" style="background:<?= $verdictTone[0] ?>;color:<?= $verdictTone[1] ?>"><?= e($report['verdict']['label']) ?></span>
    </td>
    <td class="text"><?= e($report['verdict']['text']) ?></td>
</tr></table>

<table class="kpis avoid-break"><tr>
    <td class="kpi">
        <div class="accent"></div>
        <div class="label">Uptime</div>
        <div class="value"><?= e($summary['uptime_label']) ?></div>
        <?= $delta($summary['uptime'], $previous['uptime'], true, static fn (float $d): string => number_format($d, 2) . ' pts') ?>
    </td>
    <td class="kpi">
        <div class="accent"></div>
        <div class="label">Downtime</div>
        <div class="value"><?= e($summary['downtime_label']) ?></div>
        <?= $delta((float) $summary['downtime_seconds'], (float) $previous['downtime_seconds'], false, static fn (float $d): string => format_duration((int) $d)) ?>
    </td>
    <td class="kpi">
        <div class="accent"></div>
        <div class="label">Incidents</div>
        <div class="value"><?= (int) $summary['incidents'] ?></div>
        <?= $delta((float) $summary['incidents'], (float) $previous['incidents'], false, static fn (float $d): string => (string) (int) $d) ?>
    </td>
    <td class="kpi">
        <div class="accent"></div>
        <div class="label">Avg response</div>
        <div class="value"><?= e($summary['avg_response_label']) ?></div>
        <?= $delta($summary['avg_response'] !== null ? (float) $summary['avg_response'] : null, $previous['avg_response'] !== null ? (float) $previous['avg_response'] : null, false, static fn (float $d): string => format_ms((int) round($d))) ?>
    </td>
</tr></table>

<div class="section avoid-break">
    <div class="section-head"><h2><span class="bar"></span>Summary</h2></div>
    <p class="overview"><?= e($report['narrative']) ?></p>
    <?php if ($report['intro'] !== ''): ?>
    <div class="message">
        <div class="from">A note from <?= e($brand['prepared_by'] ?: 'your team') ?></div>
        <?= nl2br(e($report['intro'])) ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($has('availability')): $c = $report['charts']['availability']; ?>
<div class="section avoid-break">
    <div class="section-head">
        <h2><span class="bar"></span>Daily availability</h2>
        <p>Share of checks that succeeded each day. The scale runs from <?= (int) $c['floor'] ?>% to 100% so that small dips stay visible.</p>
    </div>
    <div class="card chart">
        <?php if ($c['has_data']): ?>
            <img src="<?= $c['src'] ?>" alt="Daily availability chart">
            <table class="axis"><tr>
                <td><?= e($dateOnly($range['from'])) ?></td>
                <td style="text-align:center"><?= $range['days'] > 2 ? e($dateOnly((new DateTimeImmutable($range['from']))->modify('+' . intdiv($range['days'] - 1, 2) . ' days')->format('Y-m-d'))) : '' ?></td>
                <td style="text-align:right"><?= e($dateOnly($range['to'])) ?></td>
            </tr></table>
            <div class="legend">
                <span class="sw" style="background:#16A34A"></span>99.9% or more
                <span class="sw" style="background:#65A30D"></span>99–99.9%
                <span class="sw" style="background:#F59E0B"></span>97–99%
                <span class="sw" style="background:#DC2626"></span>below 97%
                <span class="sw" style="background:#E2E8F0"></span>no data
            </div>
        <?php else: ?>
            <p class="empty">No checks were recorded in this period yet.</p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($has('response')): $c = $report['charts']['response']; ?>
<div class="section avoid-break">
    <div class="section-head">
        <h2><span class="bar"></span>Response time</h2>
        <p>How long the websites took to answer, averaged per day. Lower is better. Scale: 0 to <?= e(format_ms($c['max'])) ?>.</p>
    </div>
    <div class="card chart">
        <?php if ($c['has_data']): ?>
            <img src="<?= $c['src'] ?>" alt="Daily response time chart">
            <table class="axis"><tr>
                <td><?= e($dateOnly($range['from'])) ?></td>
                <td style="text-align:right"><?= e($dateOnly($range['to'])) ?></td>
            </tr></table>
            <table class="stat-line"><tr>
                <td>Average <b><?= e($summary['avg_response_label']) ?></b></td>
                <td>Fastest day <b><?= e(format_ms($c['fastest']['avg'])) ?></b> <span class="faint">(<?= e($dateOnly($c['fastest']['date'])) ?>)</span></td>
                <td style="text-align:right">Slowest day <b><?= e(format_ms($c['slowest']['avg'])) ?></b> <span class="faint">(<?= e($dateOnly($c['slowest']['date'])) ?>)</span></td>
            </tr></table>
        <?php else: ?>
            <p class="empty">No response times were recorded in this period yet.</p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($has('websites')): ?>
<div class="section<?= $keep(count($report['rows'])) ?>">
    <div class="section-head">
        <h2><span class="bar"></span>Website breakdown</h2>
        <p>Every website in this report, with its figures for the period.</p>
    </div>
    <?php if ($report['rows'] === []): ?>
        <p class="empty">No websites are included in this report.</p>
    <?php else: ?>
    <div class="table-wrap">
    <table class="data">
        <thead><tr>
            <th>Website</th>
            <th class="num">Uptime</th>
            <th class="num">Downtime</th>
            <th class="num">Incidents</th>
            <th class="num">Avg response</th>
            <th>SSL</th>
        </tr></thead>
        <tbody>
        <?php foreach ($report['rows'] as $row): ?>
            <tr>
                <td><div class="site"><?= e($row['name']) ?></div><div class="sub"><?= e($row['domain']) ?></div></td>
                <td class="num"><?= $row['uptime'] !== null ? $pill($row['uptime_label'], $uptimeTone($row['uptime'] !== null ? (float) $row['uptime'] : null)) : '<span class="faint">—</span>' ?></td>
                <td class="num"><?= e($row['downtime_label']) ?></td>
                <td class="num"><?= (int) $row['incidents'] ?></td>
                <td class="num"><?= e($row['avg_response_label']) ?></td>
                <td><?= $pill($row['ssl']['label'], $row['ssl']['tone']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($has('incidents')): ?>
<div class="section<?= $keep(count($report['incidents'])) ?>">
    <div class="section-head">
        <h2><span class="bar"></span>Incident log</h2>
        <p>Confirmed outages and errors in the period, newest first. Times in <?= e($report['timezone']) ?>.</p>
    </div>
    <?php if ($report['incidents'] === []): ?>
        <div class="card"><p class="empty" style="font-style:normal">✓ No incidents in this period. Every check that failed was retried and cleared without an outage.</p></div>
    <?php else: ?>
    <div class="table-wrap">
    <table class="data">
        <thead><tr>
            <th>Website</th>
            <th>What happened</th>
            <th>Started</th>
            <th class="num">Duration</th>
            <th>Status</th>
        </tr></thead>
        <tbody>
        <?php foreach ($report['incidents'] as $i): ?>
            <tr>
                <td><div class="site"><?= e($i['website_name']) ?></div><div class="sub"><?= e($i['domain']) ?></div></td>
                <td><?= e($i['type_label']) ?><?php if ($i['http_status']): ?> <span class="sub">(HTTP <?= (int) $i['http_status'] ?>)</span><?php endif; ?></td>
                <td class="nowrap"><?= e(format_datetime($i['started_at'], 'j M, g:i A')) ?></td>
                <td class="num"><?= e($i['duration_label']) ?></td>
                <td><?= $i['is_open'] ? $pill('Ongoing', 'danger') : $pill('Resolved', 'success') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if ($report['incident_total'] > count($report['incidents'])): ?>
        <p class="more">Showing the <?= count($report['incidents']) ?> most recent of <?= (int) $report['incident_total'] ?> incidents.</p>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($has('performance')): ?>
<div class="section<?= $keep(count($report['performance'])) ?>">
    <div class="section-head">
        <h2><span class="bar"></span>Page speed</h2>
        <p>Latest Google Lighthouse scores (0–100, higher is better) and Core Web Vitals on mobile.</p>
    </div>
    <?php if ($report['performance'] === []): ?>
        <div class="card"><p class="empty">No page speed tests have run for these websites yet.</p></div>
    <?php else: ?>
    <div class="table-wrap">
    <table class="data">
        <thead><tr>
            <th>Website</th>
            <th class="num">Mobile</th>
            <th class="num">Desktop</th>
            <th class="num">Largest paint</th>
            <th class="num">Layout shift</th>
            <th class="num hide-sm">Tested</th>
        </tr></thead>
        <tbody>
        <?php foreach ($report['performance'] as $p):
            $mt = $tones[$scoreTone($p['mobile'])];
            $dt = $tones[$scoreTone($p['desktop'])]; ?>
            <tr>
                <td><div class="site"><?= e($p['name']) ?></div><div class="sub"><?= e($p['domain']) ?></div></td>
                <td class="num"><span class="score" style="background:<?= $mt[0] ?>;color:<?= $mt[1] ?>"><?= $p['mobile'] ?? '—' ?></span></td>
                <td class="num"><span class="score" style="background:<?= $dt[0] ?>;color:<?= $dt[1] ?>"><?= $p['desktop'] ?? '—' ?></span></td>
                <td class="num"><?= $p['lcp'] !== null ? e(number_format((int) $p['lcp'] / 1000, 1) . ' s') : '—' ?></td>
                <td class="num"><?= $p['cls'] !== null ? e(number_format((float) $p['cls'], 2)) : '—' ?></td>
                <td class="num hide-sm"><?= e($p['tested']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($has('security') && $report['security'] !== []): ?>
<div class="section<?= $keep(count($report['security'])) ?>">
    <div class="section-head">
        <h2><span class="bar"></span>SSL certificates &amp; domain renewals</h2>
        <p>Upcoming expiry dates, so nothing lapses unnoticed.</p>
    </div>
    <div class="table-wrap">
    <table class="data">
        <thead><tr>
            <th>Website</th>
            <th>SSL certificate</th>
            <th>SSL expires</th>
            <th>Domain expires</th>
            <th class="hide-sm">Registrar</th>
        </tr></thead>
        <tbody>
        <?php foreach ($report['security'] as $s):
            $domainTone = $s['domain_days'] === null ? 'neutral' : ($s['domain_days'] < 0 ? 'danger' : ($s['domain_days'] <= 30 ? 'warning' : 'success')); ?>
            <tr>
                <td><div class="site"><?= e($s['name']) ?></div><div class="sub"><?= e($s['domain']) ?></div></td>
                <td><?= $pill($s['ssl']['label'], $s['ssl']['tone']) ?></td>
                <td class="nowrap"><?= e($s['ssl']['expires_label']) ?></td>
                <td class="nowrap"><?= $s['domain_expiry'] !== null ? $pill($s['domain_expiry'], $domainTone) : '<span class="faint">Not known</span>' ?></td>
                <td class="hide-sm"><?= e($s['registrar'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<?php if (!$isPdf): ?>
<div class="foot-web foot">
    <table><tr>
        <td>
            <b><?= e($brand['name']) ?></b><?= $brand['footer_text'] ? '<br>' . e($brand['footer_text']) : '' ?>
            <?php if ($contact !== []): ?><br><?= e(implode(' · ', $contact)) ?><?php endif; ?>
        </td>
        <td class="right">
            Report period <?= e($range['label']) ?><br>
            Times in <?= e($report['timezone']) ?>
            <?php if (!(int) $brand['white_label']): ?><br><span class="faint">Monitoring by SiteWatch</span><?php endif; ?>
        </td>
    </tr></table>
</div>
</main>
<?php if (!empty($opts['nonce'])): ?>
<script nonce="<?= e($opts['nonce']) ?>">document.getElementById('printReport').addEventListener('click', function () { window.print(); });</script>
<?php endif; ?>
<?php else: ?>
<div class="section foot" style="margin-top:26px">
    Figures are calculated from recorded checks only; periods without checks count as neither uptime nor downtime. Times in <?= e($report['timezone']) ?>.<?php if (!(int) $brand['white_label']): ?> Monitoring by SiteWatch.<?php endif; ?>
</div>
<?php endif; ?>
</body>
</html>
