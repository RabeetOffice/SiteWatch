<?php

declare(strict_types=1);

/**
 * Performance → Response time. Data: $pageData['initial'] (rendered by performance-response.js).
 */
$criteria = $pageData['criteria'];
$isCustom = $criteria['from'] !== '' || $criteria['to'] !== '';
?>
<div class="report-print-heading">
    <?= sw_brand_logo(150) ?>
    <h1>Response times</h1>
    <p><span id="rtPrintRange">—</span> · Times in <?= e(app_timezone()->getName()) ?></p>
</div>

<div class="sw-card mb-3">
    <div class="sw-filterbar" style="border-bottom:0">
        <select class="form-select form-select-sm" id="rtClient" aria-label="Filter by client">
            <option value="">All clients</option>
            <?php foreach ($pageData['clients'] as $client): ?>
                <option value="<?= e($client) ?>"<?= $criteria['client'] === $client ? ' selected' : '' ?>><?= e($client) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="segmented" id="rtWindow" role="group" aria-label="Time window">
            <?php foreach (['24h' => '24 hours', '7d' => '7 days', '30d' => '30 days', '90d' => '90 days', 'custom' => 'Custom'] as $key => $label): ?>
                <?php $on = $isCustom ? $key === 'custom' : $criteria['window'] === $key; ?>
                <button type="button" data-window="<?= e($key) ?>"<?= $on ? ' class="active" aria-pressed="true"' : ' aria-pressed="false"' ?>><?= e($label) ?></button>
            <?php endforeach; ?>
        </div>
        <div class="date-range" id="rtDates"<?= $isCustom ? '' : ' hidden' ?>>
            <label class="inline-label" for="rtFrom">From</label>
            <input type="date" class="form-control form-control-sm" id="rtFrom" value="<?= e($criteria['from']) ?>">
            <label class="inline-label" for="rtTo">to</label>
            <input type="date" class="form-control form-control-sm" id="rtTo" value="<?= e($criteria['to']) ?>">
        </div>
        <div class="spacer"></div>
        <span class="fs-13 text-muted" id="rtRangeLabel"></span>
    </div>
</div>

<h2 class="visually-hidden">Summary</h2>
<div class="summary-strip cols-4 mb-3" id="rtSummary">
    <div class="summary-item"><div class="l">Average response</div><div class="v" data-sum="avg">—</div><div class="s" data-sum="window_label">&nbsp;</div></div>
    <div class="summary-item"><div class="l">Fastest website</div><div class="v" data-sum="fastest">—</div><div class="s" data-sum="fastest_name">&nbsp;</div></div>
    <div class="summary-item"><div class="l">Slowest website</div><div class="v" data-sum="slowest">—</div><div class="s" data-sum="slowest_name">&nbsp;</div></div>
    <div class="summary-item"><div class="l">Over slow threshold</div><div class="v" data-sum="over">—</div><div class="s" data-sum="threshold_label">&nbsp;</div></div>
</div>

<div class="sw-card mb-3">
    <div class="sw-card-header">
        <div><h3>Slowest websites</h3><p class="sub" id="rtChartSub">Average response time</p></div>
        <div class="chart-legend m-0">
            <span class="item"><span class="swatch"></span>Within threshold</span>
            <span class="item"><span class="swatch" style="background:var(--sw-warning-solid)"></span>Slow</span>
            <span class="item"><span class="swatch" style="background:var(--sw-danger-solid)"></span>Critically slow</span>
        </div>
    </div>
    <div class="sw-card-body">
        <div class="chart-box" style="height:300px">
            <canvas id="chartSlowest" role="img" aria-label="Slowest websites by average response time"></canvas>
            <div class="chart-empty" id="rtChartEmpty" hidden></div>
        </div>
    </div>
</div>

<div class="sw-card">
    <div class="sw-card-header">
        <div>
            <h3>All websites</h3>
            <p class="sub" id="rtTableSub">Average, fastest and slowest cover the selected window · “Latest” is the most recent check</p>
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
                    <th scope="col">Status</th>
                    <th scope="col" class="num">Latest</th>
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
