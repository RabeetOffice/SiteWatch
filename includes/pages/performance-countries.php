<?php

declare(strict_types=1);

/**
 * Performance → Countries (rendered by countries.js from $pageData).
 */
?>
<?php if (!$pageData['enabled']): ?>
    <div class="sw-card mb-3">
        <div class="sw-card-body d-flex gap-3 align-items-start flex-wrap">
            <div class="activity-icon tone-primary flex-shrink-0" aria-hidden="true"><i class="bi bi-globe-europe-africa"></i></div>
            <div class="min-w-0 flex-grow-1" style="flex-basis:320px">
                <h3 class="fs-6 mb-1">Country checks are switched off</h3>
                <p class="text-muted fs-13 mb-0">Turn them on to see whether each website opens from other countries. The tests run on free Globalping servers, not on this server.</p>
            </div>
            <?php if ($pageData['canSettings']): ?>
                <a class="btn btn-primary" href="<?= e(base_url('admin/settings.php?tab=monitoring')) ?>#country_checks_enabled"><i class="bi bi-sliders" aria-hidden="true"></i>Turn on</a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<h2 class="visually-hidden">Summary</h2>
<div class="summary-strip cols-4 mb-3" id="ccSummary" role="group" aria-label="Filter by result">
    <button type="button" class="summary-item" data-filter="ok" aria-pressed="false"><div class="l">Open everywhere</div><div class="v tone-success" data-sum="everywhere">—</div><div class="s">from every country tested</div></button>
    <button type="button" class="summary-item" data-filter="slow" aria-pressed="false"><div class="l">Slow somewhere</div><div class="v" data-sum="slow">—</div><div class="s">opens, but slowly in a country</div></button>
    <button type="button" class="summary-item" data-filter="problem" aria-pressed="false"><div class="l">Problem somewhere</div><div class="v" data-sum="problem">—</div><div class="s">blocked or unreachable in a country</div></button>
    <button type="button" class="summary-item" data-filter="unchecked" aria-pressed="false"><div class="l">Not checked yet</div><div class="v" data-sum="unchecked">—</div><div class="s" data-sum="interval">&nbsp;</div></button>
</div>

<div class="sw-card">
    <div class="sw-card-header">
        <div>
            <h3>Availability by country</h3>
            <p class="sub">One test server per country, a second one on another network before anything is reported. Results are an indication, not proof.</p>
        </div>
        <div class="search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" class="form-control form-control-sm" id="ccSearch" aria-label="Filter websites" placeholder="Filter websites…">
        </div>
    </div>
    <div class="sw-table-wrap">
        <table class="sw-table cc-table">
            <thead id="ccHead"></thead>
            <tbody id="ccBody"></tbody>
        </table>
    </div>
    <div class="sw-card-footer cc-legend" id="ccLegend"></div>
</div>

<div class="sw-panel-backdrop" id="ccPanelBackdrop" hidden></div>
<aside class="sw-panel" id="ccPanel" aria-labelledby="ccPanelTitle" aria-hidden="true" tabindex="-1">
    <div class="sw-panel-head">
        <div class="min-w-0 flex-grow-1">
            <div class="fs-12 text-muted" id="ccPanelSite"></div>
            <h2 id="ccPanelTitle">Country</h2>
        </div>
        <button type="button" class="btn-close" data-panel-close aria-label="Close"></button>
    </div>
    <div class="sw-panel-body" id="ccPanelBody"></div>
</aside>
