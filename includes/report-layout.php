<?php

declare(strict_types=1);

/**
 * Uptime report markup (reports.js). The performance variant of 1.x is now Performance → Response time.
 */
?>
<div class="report-print-heading">
    <?= sw_brand_logo(150) ?>
    <h1>Uptime report</h1>
    <p>
        <span id="printRange">—</span> ·
        <span id="printScope">All websites</span> ·
        Times in <?= e(app_timezone()->getName()) ?>
    </p>
    <p id="printCoverage"></p>
</div>

<div class="sw-card mb-3 report-filter-card">
    <div class="sw-filterbar" style="border-bottom:0">
        <select class="form-select form-select-sm" id="reportWebsite" aria-label="Filter by website"><option value="">All websites</option></select>
        <select class="form-select form-select-sm" id="reportClient" aria-label="Filter by client"><option value="">All clients</option></select>
        <div class="segmented" id="reportPresets" role="group" aria-label="Date range">
            <button type="button" data-days="7">7 days</button>
            <button type="button" data-days="30" class="active">30 days</button>
            <button type="button" data-days="90">90 days</button>
        </div>
        <div class="date-range">
            <label class="inline-label" for="reportFrom">From</label>
            <input type="date" class="form-control form-control-sm" id="reportFrom">
            <label class="inline-label" for="reportTo">to</label>
            <input type="date" class="form-control form-control-sm" id="reportTo">
        </div>
    </div>
</div>

<h2 class="visually-hidden">Report summary</h2>
<div class="summary-strip cols-4 mb-3" id="reportSummary">
    <div class="summary-item"><div class="l">Observed uptime</div><div class="v" data-sum="uptime_label">—</div><div class="s"><span data-sum="checks">—</span> checks recorded</div></div>
    <div class="summary-item"><div class="l">Total downtime</div><div class="v" data-sum="downtime_label">—</div><div class="s">from confirmed incidents</div></div>
    <div class="summary-item"><div class="l">Incidents</div><div class="v" data-sum="incidents">—</div><div class="s">confirmed in the range</div></div>
    <div class="summary-item"><div class="l">Average response</div><div class="v" data-sum="avg_response_label">—</div><div class="s"><span data-sum="websites">—</span> websites</div></div>
</div>

<div class="sw-card">
    <div class="sw-card-header">
        <div>
            <h3>Uptime by website</h3>
            <p class="sub report-context mt-1">
                <span class="item"><b>Range:</b> <span id="reportRange">—</span></span>
                <span class="item"><b>Websites:</b> <span id="reportScope">All websites</span></span>
                <span class="item"><b>Client:</b> <span id="reportClientLabel">All clients</span></span>
            </p>
        </div>
        <button type="button" class="btn btn-sm btn-ghost" data-bs-toggle="collapse" data-bs-target="#reportMethod" aria-expanded="false" aria-controls="reportMethod">
            <i class="bi bi-info-circle" aria-hidden="true"></i> How uptime is measured
        </button>
    </div>
    <div class="collapse" id="reportMethod">
        <div class="coverage-note m-3 mt-3" role="note">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <div><span id="reportCoverage">Availability is calculated from recorded checks only. Periods without recorded checks are not counted as uptime or as downtime.</span></div>
        </div>
    </div>
    <div class="sw-table-wrap">
        <table class="sw-table" id="reportTable">
            <thead>
                <tr>
                    <th scope="col">Website</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="num">Uptime</th>
                    <th scope="col" class="num">Downtime</th>
                    <th scope="col" class="num">Incidents</th>
                    <th scope="col" class="num">Avg response</th>
                    <th scope="col" class="num hide-mobile">Slowest</th>
                    <th scope="col">SSL</th>
                </tr>
            </thead>
            <tbody id="reportBody"></tbody>
        </table>
    </div>
</div>
