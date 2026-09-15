<?php

declare(strict_types=1);

/**
 * Shared markup for the Uptime and Performance reports. Uses $pageData['mode'].
 * Both reports share one structure — filter toolbar, summary strip, report
 * context, coverage note and results table — but keep a distinct metric emphasis.
 */
$mode = $pageData['mode'] ?? 'uptime';
$isPerformance = $mode === 'performance';
$reportTitle = $isPerformance ? 'Performance report' : 'Uptime report';
?>
<div class="report-print-heading">
    <?= sw_brand_logo(150) ?>
    <h1><?= e($reportTitle) ?></h1>
    <p>
        <span id="printRange">—</span> ·
        <span id="printScope">All websites</span> ·
        Times in <?= e(app_timezone()->getName()) ?>
    </p>
    <p id="printCoverage"></p>
</div>

<div class="sw-card mb-3 report-filter-card">
    <div class="sw-toolbar report-toolbar">
        <div class="toolbar-group">
            <select class="form-select form-select-sm" id="reportWebsite" aria-label="Filter by website"><option value="">All websites</option></select>
            <select class="form-select form-select-sm" id="reportClient" aria-label="Filter by client"><option value="">All clients</option></select>
        </div>
        <div class="date-range">
            <label class="inline-label" for="reportFrom">From</label>
            <input type="date" class="form-control form-control-sm" id="reportFrom" aria-label="From date">
            <label class="inline-label" for="reportTo">to</label>
            <input type="date" class="form-control form-control-sm" id="reportTo" aria-label="To date">
        </div>
        <div class="segmented" id="reportPresets" role="group" aria-label="Date range presets">
            <button type="button" data-days="7">7 days</button>
            <button type="button" data-days="30" class="active">30 days</button>
            <button type="button" data-days="90">90 days</button>
        </div>
        <div class="spacer"></div>
        <div class="toolbar-group">
            <a class="btn btn-sm btn-light" id="reportExport" href="#"><i class="bi bi-download" aria-hidden="true"></i> Export CSV</a>
            <button type="button" class="btn btn-sm btn-light" id="printReport"><i class="bi bi-printer" aria-hidden="true"></i> Print</button>
        </div>
    </div>
</div>

<h2 class="visually-hidden">Report summary</h2>
<div class="summary-strip mb-3" id="reportSummary">
<?php if ($isPerformance): ?>
    <div class="summary-item"><div class="l">Average response</div><div class="v" data-sum="avg_response_label">—</div><div class="s">across all checks</div></div>
    <div class="summary-item"><div class="l">Slowest response</div><div class="v" data-sum="slowest_label">—</div><div class="s" data-sum="slowest_site">&nbsp;</div></div>
    <div class="summary-item"><div class="l">Websites</div><div class="v" data-sum="websites">—</div><div class="s">in this report</div></div>
    <div class="summary-item"><div class="l">Checks recorded</div><div class="v" data-sum="checks">—</div><div class="s">in the date range</div></div>
    <div class="summary-item"><div class="l">Observed uptime</div><div class="v" data-sum="uptime_label">—</div><div class="s">of recorded checks</div></div>
    <div class="summary-item"><div class="l">Incidents</div><div class="v" data-sum="incidents">—</div><div class="s">confirmed</div></div>
<?php else: ?>
    <div class="summary-item"><div class="l">Observed uptime</div><div class="v" data-sum="uptime_label">—</div><div class="s">of recorded checks</div></div>
    <div class="summary-item"><div class="l">Total downtime</div><div class="v" data-sum="downtime_label">—</div><div class="s">from confirmed incidents</div></div>
    <div class="summary-item"><div class="l">Incidents</div><div class="v" data-sum="incidents">—</div><div class="s">confirmed</div></div>
    <div class="summary-item"><div class="l">Websites</div><div class="v" data-sum="websites">—</div><div class="s">in this report</div></div>
    <div class="summary-item"><div class="l">Checks recorded</div><div class="v" data-sum="checks">—</div><div class="s">in the date range</div></div>
    <div class="summary-item"><div class="l">Average response</div><div class="v" data-sum="avg_response_label">—</div><div class="s">across all checks</div></div>
<?php endif; ?>
</div>

<div class="report-context mb-3">
    <span class="item"><b>Range:</b> <span id="reportRange">—</span></span>
    <span class="item"><b>Websites:</b> <span id="reportScope">All websites</span></span>
    <span class="item"><b>Client:</b> <span id="reportClientLabel">All clients</span></span>
</div>

<div class="coverage-note mb-3" role="note">
    <i class="bi bi-info-circle" aria-hidden="true"></i>
    <div>
        <strong>How this uptime is measured</strong>
        <span id="reportCoverage">Availability is calculated from recorded checks only. Periods without recorded checks are not counted as uptime or as downtime.</span>
    </div>
</div>

<div class="sw-card">
    <div class="sw-card-header">
        <div><h3><?= $isPerformance ? 'Performance by website' : 'Uptime by website' ?></h3></div>
    </div>
    <div class="sw-table-wrap">
        <table class="sw-table" id="reportTable">
            <thead>
                <?php if ($isPerformance): ?>
                <tr>
                    <th scope="col">Website</th>
                    <th scope="col" class="hide-mobile">Client</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="num">Average</th>
                    <th scope="col" class="num">Fastest</th>
                    <th scope="col" class="num">Slowest</th>
                    <th scope="col" class="num hide-mobile">Checks</th>
                    <th scope="col" class="num hide-mobile">Uptime</th>
                    <th scope="col">SSL</th>
                </tr>
                <?php else: ?>
                <tr>
                    <th scope="col">Website</th>
                    <th scope="col" class="hide-mobile">Client</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="num">Uptime</th>
                    <th scope="col" class="num">Downtime</th>
                    <th scope="col" class="num">Incidents</th>
                    <th scope="col" class="num">Avg response</th>
                    <th scope="col" class="num hide-mobile">Slowest</th>
                    <th scope="col">SSL</th>
                </tr>
                <?php endif; ?>
            </thead>
            <tbody id="reportBody"></tbody>
        </table>
    </div>
</div>
