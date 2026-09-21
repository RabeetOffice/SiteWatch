<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

require_permission('reports.view');

use App\Core\App;

$s = App::settings();

$pageTitle = 'Core Web Vitals';
$activeNav = 'web-vitals';
$pageScripts = ['web-vitals.js'];
$needsCharts = true;
$pageData = [
    'vitalsEnabled'  => $s->getBool('vitals_enabled'),
    'intervalHours'  => max(1, $s->getInt('vitals_interval_hours', 24)),
    'canRun'         => can('websites.check'),
];
$headerActions = '<div class="segmented" id="cwvStrategy" role="group" aria-label="Device">'
    . '<button type="button" data-strategy="mobile" class="active"><i class="bi bi-phone" aria-hidden="true"></i> Mobile</button>'
    . '<button type="button" data-strategy="desktop"><i class="bi bi-display" aria-hidden="true"></i> Desktop</button></div>';

require dirname(__DIR__) . '/includes/header.php';
?>

<?php if (!$s->getBool('vitals_enabled')): ?>
    <div class="sw-card mb-4">
        <div class="sw-card-body">
            <h3 class="mb-2">Core Web Vitals are switched off</h3>
            <p class="text-muted fs-14 mb-3">
                LCP, CLS and INP are measured by Google PageSpeed Insights, because they describe what a browser does
                while rendering and cannot be measured from the server. Turn it on, add a free Google API key, and
                SiteWatch will start collecting mobile and desktop results on a schedule.
            </p>
            <?php if (can('settings.manage')): ?>
                <a class="btn btn-primary" href="<?= e(base_url('admin/settings.php?section=monitoring')) ?>#vitals_enabled">
                    <i class="bi bi-sliders" aria-hidden="true"></i> Set up Core Web Vitals
                </a>
            <?php else: ?>
                <p class="fs-13 mb-0">Ask an administrator to enable it under Monitoring Settings.</p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="summary-strip mb-4" id="cwvSummary">
    <div class="summary-item">
        <div class="l">Average score</div>
        <div class="v" data-sum="average"><span class="skeleton skeleton-line" style="width:60px;display:inline-block">&nbsp;</span></div>
        <div class="s" data-sum="measured">&nbsp;</div>
    </div>
    <div class="summary-item">
        <div class="l">Good</div>
        <div class="v text-success" data-sum="good">—</div>
        <div class="s">score 90 or above</div>
    </div>
    <div class="summary-item">
        <div class="l">Needs improvement</div>
        <div class="v text-warning" data-sum="needs-improvement">—</div>
        <div class="s">score 50 to 89</div>
    </div>
    <div class="summary-item">
        <div class="l">Poor</div>
        <div class="v text-danger" data-sum="poor">—</div>
        <div class="s">score below 50</div>
    </div>
</div>

<div class="sw-card">
    <div class="sw-card-header">
        <div>
            <h3>All websites</h3>
            <p class="sub" id="cwvTableSub">
                LCP, CLS and TBT come from the Lighthouse run; INP is real-user data and only appears once a site has
                enough traffic. TTFB is measured by SiteWatch on every check.
            </p>
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
                    <th scope="col" class="hide-mobile">Client</th>
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

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
