<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$pageTitle = 'Response Times';
$pageSubtitle = 'How fast every client website responds, ranked slowest first.';
$activeNav = 'response-times';
$pageScripts = ['response-times.js'];
$needsCharts = true;

require dirname(__DIR__) . '/includes/header.php';
?>

<div class="row g-3 mb-4">
    <div class="col-xl-8">
        <div class="sw-card h-100">
            <div class="sw-card-header">
                <div><h3>Slowest Websites</h3><p class="sub" id="rtChartSub">Average response over the selected window</p></div>
                <div class="segmented" id="rtWindow">
                    <button type="button" data-window="24h" class="active">24 Hours</button>
                    <button type="button" data-window="7d">7 Days</button>
                </div>
            </div>
            <div class="sw-card-body"><div class="chart-box" style="height:300px"><canvas id="chartSlowest"></canvas></div></div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="sw-card h-100">
            <div class="sw-card-header"><div><h3>Performance Summary</h3><p class="sub">Across all monitored websites</p></div></div>
            <div class="sw-card-body">
                <div class="d-flex flex-column gap-2" id="rtSummary">
                    <div class="overview-item"><div class="l">Fleet average</div><div class="v" data-sum="avg"><div class="skeleton skeleton-line w-50">&nbsp;</div></div></div>
                    <div class="overview-item"><div class="l">Fastest website</div><div class="v" data-sum="fastest"><div class="skeleton skeleton-line w-50">&nbsp;</div></div><div class="fs-12 text-muted" data-sum="fastest_name"></div></div>
                    <div class="overview-item"><div class="l">Slowest website</div><div class="v" data-sum="slowest"><div class="skeleton skeleton-line w-50">&nbsp;</div></div><div class="fs-12 text-muted" data-sum="slowest_name"></div></div>
                    <div class="overview-item"><div class="l">Websites over slow threshold</div><div class="v" data-sum="over"><div class="skeleton skeleton-line w-25">&nbsp;</div></div></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="sw-card">
    <div class="sw-card-header">
        <div><h3>All Websites</h3><p class="sub">Current, average, minimum and maximum response times</p></div>
        <div class="search" style="position:relative;max-width:280px"><i class="bi bi-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--sw-faint)"></i><input type="search" class="form-control form-control-sm" id="rtSearch" placeholder="Filter websites…" style="padding-left:34px"></div>
    </div>
    <div class="sw-table-wrap">
        <table class="sw-table">
            <thead><tr><th>Website</th><th class="hide-mobile">Client</th><th>Status</th><th>Current</th><th>Average</th><th class="hide-mobile">Min</th><th>Max</th><th class="hide-mobile">Checks</th><th class="hide-mobile">Trend</th></tr></thead>
            <tbody id="rtBody"><?= '' ?></tbody>
        </table>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
