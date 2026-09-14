<?php

declare(strict_types=1);

/**
 * Shared markup for the Uptime and Performance reports. Uses $pageData['mode'].
 */
$mode = $pageData['mode'] ?? 'uptime';
$isPerformance = $mode === 'performance';
?>
<div class="sw-card mb-4">
    <div class="sw-toolbar">
        <select class="form-select form-select-sm" id="reportWebsite" style="width:auto;max-width:240px"><option value="">All websites</option></select>
        <select class="form-select form-select-sm" id="reportClient" style="width:auto;max-width:200px"><option value="">All clients</option></select>
        <div class="d-flex align-items-center gap-1">
            <input type="date" class="form-control form-control-sm" id="reportFrom" style="width:auto" aria-label="From date">
            <span class="text-muted fs-13">to</span>
            <input type="date" class="form-control form-control-sm" id="reportTo" style="width:auto" aria-label="To date">
        </div>
        <div class="segmented" id="reportPresets">
            <button type="button" data-days="7">7d</button>
            <button type="button" data-days="30" class="active">30d</button>
            <button type="button" data-days="90">90d</button>
        </div>
        <div class="spacer"></div>
        <a class="btn btn-sm btn-light" id="reportExport" href="#"><i class="bi bi-download"></i> Export CSV</a>
    </div>
    <div class="sw-card-body">
        <div class="row g-2" id="reportSummary">
            <div class="col-6 col-md-4 col-xl-2"><div class="overview-item"><div class="l">Websites</div><div class="v" data-sum="websites">—</div></div></div>
            <div class="col-6 col-md-4 col-xl-2"><div class="overview-item"><div class="l"><?= $isPerformance ? 'Average Response' : 'Overall Uptime' ?></div><div class="v" data-sum="<?= $isPerformance ? 'avg_response_label' : 'uptime_label' ?>">—</div></div></div>
            <div class="col-6 col-md-4 col-xl-2"><div class="overview-item"><div class="l"><?= $isPerformance ? 'Slowest Response' : 'Total Downtime' ?></div><div class="v" data-sum="<?= $isPerformance ? 'slowest_label' : 'downtime_label' ?>">—</div><div class="fs-12 text-muted" data-sum="<?= $isPerformance ? 'slowest_site' : '' ?>"></div></div></div>
            <div class="col-6 col-md-4 col-xl-2"><div class="overview-item"><div class="l">Incidents</div><div class="v" data-sum="incidents">—</div></div></div>
            <div class="col-6 col-md-4 col-xl-2"><div class="overview-item"><div class="l">Checks</div><div class="v" data-sum="checks">—</div></div></div>
            <div class="col-6 col-md-4 col-xl-2"><div class="overview-item"><div class="l"><?= $isPerformance ? 'Overall Uptime' : 'Average Response' ?></div><div class="v" data-sum="<?= $isPerformance ? 'uptime_label' : 'avg_response_label' ?>">—</div></div></div>
        </div>
    </div>
</div>

<div class="sw-card">
    <div class="sw-card-header"><div><h3><?= $isPerformance ? 'Performance by Website' : 'Uptime by Website' ?></h3><p class="sub" id="reportRange">—</p></div></div>
    <div class="sw-table-wrap">
        <table class="sw-table" id="reportTable">
            <thead>
                <?php if ($isPerformance): ?>
                <tr><th>Website</th><th class="hide-mobile">Client</th><th>Status</th><th>Average</th><th>Fastest</th><th>Slowest</th><th class="hide-mobile">Checks</th><th class="hide-mobile">Uptime</th><th>SSL</th></tr>
                <?php else: ?>
                <tr><th>Website</th><th class="hide-mobile">Client</th><th>Status</th><th>Uptime</th><th>Downtime</th><th>Incidents</th><th>Avg Response</th><th class="hide-mobile">Slowest</th><th>SSL</th></tr>
                <?php endif; ?>
            </thead>
            <tbody id="reportBody"></tbody>
        </table>
    </div>
</div>
