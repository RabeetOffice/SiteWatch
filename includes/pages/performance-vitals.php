<?php

declare(strict_types=1);

/**
 * Performance → Core Web Vitals (rendered by web-vitals.js).
 */
?>
<?php if (!$pageData['vitalsEnabled']): ?>
    <div class="sw-card mb-3">
        <div class="sw-card-body d-flex gap-3 align-items-start flex-wrap">
            <div class="activity-icon tone-primary flex-shrink-0" aria-hidden="true"><i class="bi bi-lightning-charge"></i></div>
            <div class="min-w-0 flex-grow-1" style="flex-basis:320px">
                <h3 class="fs-6 mb-1">Core Web Vitals are switched off</h3>
                <p class="text-muted fs-13 mb-0">
                    LCP, CLS and INP describe what a browser does while it renders a page, so they come from Google PageSpeed
                    Insights rather than from SiteWatch's own checks. Turn it on and add a free Google API key to collect mobile and
                    desktop results on a schedule.
                </p>
            </div>
            <?php if (can('settings.manage')): ?>
                <a class="btn btn-primary" href="<?= e(base_url('admin/settings.php?tab=monitoring')) ?>#vitals_enabled"><i class="bi bi-sliders" aria-hidden="true"></i>Set up</a>
            <?php else: ?>
                <p class="fs-13 text-muted mb-0">Ask an administrator to turn it on in Settings.</p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<h2 class="visually-hidden">Summary</h2>
<div class="summary-strip cols-4 mb-3" id="cwvSummary">
    <div class="summary-item"><div class="l">Average score</div><div class="v" data-sum="average">—</div><div class="s" data-sum="measured">&nbsp;</div></div>
    <div class="summary-item"><div class="l">Good</div><div class="v tone-success" data-sum="good">—</div><div class="s">score 90 or above</div></div>
    <div class="summary-item"><div class="l">Needs improvement</div><div class="v tone-warning" data-sum="needs-improvement">—</div><div class="s">score 50 to 89</div></div>
    <div class="summary-item"><div class="l">Poor</div><div class="v tone-danger" data-sum="poor">—</div><div class="s">score below 50</div></div>
</div>

<div class="sw-card">
    <div class="sw-card-header">
        <div>
            <h3>All websites</h3>
            <p class="sub" id="cwvTableSub">LCP, CLS and TBT come from the Lighthouse run. INP is real-user data and appears once a site has enough traffic. TTFB is measured by SiteWatch on every check.</p>
        </div>
        <div class="search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" class="form-control form-control-sm" id="cwvSearch" aria-label="Filter websites" placeholder="Filter websites…">
        </div>
    </div>
    <div class="sw-table-wrap">
        <table class="sw-table">
            <thead>
                <tr>
                    <th scope="col">Website</th>
                    <th scope="col" class="num">Score</th>
                    <th scope="col" class="num">LCP</th>
                    <th scope="col" class="num">CLS</th>
                    <th scope="col" class="num">INP</th>
                    <th scope="col" class="num hide-mobile">TBT</th>
                    <th scope="col" class="num">TTFB</th>
                    <th scope="col" class="hide-mobile">Measured</th>
                </tr>
            </thead>
            <tbody id="cwvBody"></tbody>
        </table>
    </div>
</div>
