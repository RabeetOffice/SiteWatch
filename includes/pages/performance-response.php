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

<div class="sw-filterbar rt-filters">
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
    <span class="rt-range" id="rtRangeLabel"></span>
</div>

<h2 class="visually-hidden">Summary</h2>
<div class="rt-tiles mb-3" id="rtSummary">
    <div class="ov-tile">
        <div class="l">Average response</div>
        <div class="v" data-sum="avg">—</div>
        <div class="viz" data-sum="fleet"></div>
        <div class="s" data-sum="window_label">&nbsp;</div>
    </div>
    <div class="ov-tile">
        <div class="l">Fastest website</div>
        <div class="v tone-success" data-sum="fastest">—</div>
        <div class="rt-site" data-sum="fastest_site">&nbsp;</div>
        <div class="s" data-sum="fastest_note">&nbsp;</div>
    </div>
    <div class="ov-tile">
        <div class="l">Slowest website</div>
        <div class="v" data-sum="slowest">—</div>
        <div class="rt-site" data-sum="slowest_site">&nbsp;</div>
        <div class="s" data-sum="slowest_note">&nbsp;</div>
    </div>
    <div class="ov-tile">
        <div class="l">Speed of all websites</div>
        <div class="v" data-sum="over">—</div>
        <div class="viz"><div class="rt-spread" data-sum="spread"></div></div>
        <div class="s" data-sum="spread_note">&nbsp;</div>
    </div>
</div>

<section class="sw-card mb-3" aria-labelledby="rtRankTitle">
    <div class="sw-card-header">
        <div><h3 id="rtRankTitle">Slowest websites</h3><p class="sub" id="rtChartSub">Average response time</p></div>
        <div class="rt-legend" id="rtLegend"></div>
    </div>
    <div class="rt-rank" id="rtRank"></div>
    <div class="rt-rank-foot" id="rtRankFoot" hidden><button type="button" class="btn btn-sm btn-ghost" id="rtRankMore"></button></div>
</section>

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
