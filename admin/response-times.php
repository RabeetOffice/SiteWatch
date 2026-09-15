<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

require_permission('reports.view');

$pageTitle = 'Response Times';
$activeNav = 'response-times';
$pageScripts = ['response-times.js'];
$needsCharts = true;
$headerActions = '<div class="segmented" id="rtWindow" role="group" aria-label="Measurement window">'
    . '<button type="button" data-window="24h" class="active">24 hours</button>'
    . '<button type="button" data-window="7d">7 days</button></div>';

require dirname(__DIR__) . '/includes/header.php';
?>

<div class="summary-strip mb-4" id="rtSummary">
    <div class="summary-item">
        <div class="l">Fleet average</div>
        <div class="v" data-sum="avg"><span class="skeleton skeleton-line" style="width:70px;display:inline-block">&nbsp;</span></div>
        <div class="s" data-sum="window_label">over the selected window</div>
    </div>
    <div class="summary-item">
        <div class="l">Fastest website</div>
        <div class="v" data-sum="fastest"><span class="skeleton skeleton-line" style="width:70px;display:inline-block">&nbsp;</span></div>
        <div class="s" data-sum="fastest_name">&nbsp;</div>
    </div>
    <div class="summary-item">
        <div class="l">Slowest website</div>
        <div class="v" data-sum="slowest"><span class="skeleton skeleton-line" style="width:70px;display:inline-block">&nbsp;</span></div>
        <div class="s" data-sum="slowest_name">&nbsp;</div>
    </div>
    <div class="summary-item">
        <div class="l">Over slow threshold</div>
        <div class="v" data-sum="over"><span class="skeleton skeleton-line" style="width:50px;display:inline-block">&nbsp;</span></div>
        <div class="s" data-sum="threshold_label">&nbsp;</div>
    </div>
</div>

<div class="sw-card mb-4">
    <div class="sw-card-header">
        <div><h3>Slowest websites</h3><p class="sub" id="rtChartSub">Average response over the selected window</p></div>
    </div>
    <div class="sw-card-body">
        <div class="chart-box" style="height:300px"><canvas id="chartSlowest" role="img" aria-label="Slowest websites by average response time"></canvas></div>
        <div class="chart-legend">
            <span class="item"><span class="swatch" style="background:var(--sw-primary)"></span>Within threshold</span>
            <span class="item"><span class="swatch" style="background:var(--sw-warning-solid)"></span>Slow</span>
            <span class="item"><span class="swatch" style="background:var(--sw-danger-solid)"></span>Critically slow</span>
        </div>
    </div>
</div>

<div class="sw-card">
    <div class="sw-card-header">
        <div>
            <h3>All websites</h3>
            <p class="sub" id="rtTableSub">Current is the most recent recorded check · average, fastest and slowest cover the selected window</p>
        </div>
        <div class="search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" class="form-control form-control-sm" id="rtSearch" aria-label="Filter websites" placeholder="Filter websites…">
        </div>
    </div>
    <div class="sw-table-wrap">
        <table class="sw-table">
            <thead>
                <tr>
                    <th scope="col">Website</th>
                    <th scope="col" class="hide-mobile">Client</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="num">Current</th>
                    <th scope="col" class="num">Average</th>
                    <th scope="col" class="num hide-mobile">Fastest</th>
                    <th scope="col" class="num">Slowest</th>
                    <th scope="col" class="num hide-mobile">Checks</th>
                    <th scope="col" class="hide-mobile">Trend</th>
                </tr>
            </thead>
            <tbody id="rtBody"></tbody>
        </table>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
