from pathlib import Path
p=Path('admin/dashboard.php')
t=p.read_text();t=t.replace("$pageTitle = 'Website Monitoring';","$pageTitle = 'Overview';").replace("Monitor the uptime, performance and health of all your client websites.","Your client websites, at a glance.")
start=t.index('<div class="kpi-grid');end=t.index('<div class="row g-3 mb-4">',start)
t=t[:start]+'''<section class="overview-heading"><div><div class="eyebrow">WORKSPACE OVERVIEW</div><h2>A clear view of every website.</h2><p>Spot issues, investigate changes, and keep your clients informed.</p></div><a class="btn btn-light" href="<?= e(base_url('admin/reports.php')) ?>"><i class="bi bi-bar-chart-line"></i> View reports</a></section>
<div class="overview-metrics" id="kpiGrid">
<?php foreach ([['total','Websites','bi-globe2','primary','all','All client websites'],['online','Online','bi-check-circle','success','online','At the last check'],['down','Down','bi-exclamation-octagon','danger','down','Confirmed failures'],['warnings','Warnings','bi-exclamation-triangle','warning','warning','Slow, SSL or suspected']] as [$key,$label,$icon,$tone,$filter,$sub]): ?>
<button type="button" class="metric-card tone-<?= e($tone) ?>" data-overview-filter="<?= e($filter) ?>" aria-label="Show <?= e(strtolower($label)) ?> websites"><span class="metric-top"><span><?= e($label) ?></span><i class="bi <?= e($icon) ?>"></i></span><span class="metric-value" data-kpi="<?= e($key) ?>"><?= (int)$kpi[$key] ?></span><span class="metric-bottom"><?= e($sub) ?><i class="bi bi-arrow-up-right"></i></span></button>
<?php endforeach; ?>
</div>
<section class="sw-card attention-panel mb-4"><div class="sw-card-header"><div><h3><i class="bi bi-lightning-charge text-warning"></i>Needs attention <span class="count-chip" data-kpi="open_incidents"><?= (int)$kpi['open_incidents'] ?></span></h3><p class="sub">Confirmed incidents · last known status</p></div><a class="btn btn-sm btn-light" href="<?= e(base_url('admin/incidents.php?status=OPEN')) ?>">All incidents <i class="bi bi-arrow-right"></i></a></div><div id="recentIncidents"></div></section>
<div class="sw-card mb-4" id="websiteTable"></div>
<div class="section-heading"><div><div class="eyebrow">THE BIGGER PICTURE</div><h2>Performance & reliability</h2></div><span class="text-muted fs-12">Based on recorded checks</span></div>
'''+t[end:]
# Remove the duplicated bottom table and incident/activity columns, retain activity.
idx=t.index('<div class="sw-card mb-4" id="websiteTable"></div>',t.index('THE BIGGER PICTURE'))
t=t[:idx]+'''<section class="sw-card"><div class="sw-card-header"><div><h3>Recent activity</h3><p class="sub">Monitoring and workspace updates</p></div><a href="<?= e(base_url('admin/activity.php')) ?>" class="btn btn-sm btn-light">View activity <i class="bi bi-arrow-right"></i></a></div><div id="recentActivity"></div></section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
'''
# Replace redundant distribution chart with a wider two-column history row.
a=t.index('    <div class="col-xl-4 col-md-6">');b=t.index('    <div class="col-xl-4 col-md-6">',a+1)
t=t[:a]+t[b:];t=t.replace('col-xl-4 col-md-6','col-md-6').replace('col-xl-4 col-md-12','col-md-6')
t=t.replace('Health Overview','Portfolio health').replace('Current state of every monitored website','Last known website states').replace('Uptime Trend','Observed uptime').replace('Fleet-wide daily uptime · last 30 days','Daily check-based availability · last 30 days')
t=t.replace('<canvas id="chartResponse"></canvas>','<canvas id="chartResponse" role="img" aria-label="Fleet average response time over the last 24 hours"></canvas>').replace('id="chartResponse" role=', 'id="chartResponse" role=')
t=t.replace('style="height:220px"><canvas','style="height:200px"><canvas').replace('</canvas></div></div></div>','</canvas></div></div></div>')
t=t.replace('<div class="sw-card-body"><div class="chart-box" style="height:200px"><canvas id="chartResponse"', '<div class="sw-card-body"><p class="chart-note mb-3" id="responseSampleNote"></p><div class="chart-box" style="height:200px"><canvas id="chartResponse"')
p.write_text(t)

p=Path('assets/js/dashboard.js');t=p.read_text();t=t.replace("        const el = document.getElementById('recentIncidents');", "        list = list.filter(function (i) { return i.is_open; });\n        const el = document.getElementById('recentIncidents');")
t=t.replace("'Everything looks healthy.', 'No incidents detected.'", "'No confirmed incidents.', 'Review warnings and the last check time in your website list.'")
t=t.replace('Overall Uptime · 30d','Observed uptime · 30d').replace('pointRadius: 0, pointHitRadius: 12','pointRadius: data.response_trend.values.length === 1 ? 5 : 2, pointHitRadius: 12').replace('spanGaps: true','spanGaps: false')
t=t.replace("        const c = chartDefaults();", "        const c = chartDefaults();\n        const note = document.getElementById('responseSampleNote');\n        if (note) note.textContent = data.response_trend.values.length === 1 ? 'One recorded interval. More checks are needed to show a trend.' : data.response_trend.values.length + ' recorded intervals · gaps are not availability evidence';")
t=t.replace("perPage: 10, storageKey:","perPage: 8, storageKey:").replace("title: 'Websites'","title: 'Website portfolio'")
t=t.replace("        let tickCount = 0;", """        document.querySelectorAll('[data-overview-filter]').forEach(function (button) {
            button.addEventListener('click', function () {
                if (!websiteTable) return;
                websiteTable.state.filter = button.dataset.overviewFilter;
                websiteTable.state.q = ''; websiteTable.state.client = ''; websiteTable.state.page = 1;
                websiteTable.state.sort = 'status'; websiteTable.state.dir = '';
                websiteTable.persist(); websiteTable.render(); websiteTable.load();
                const target = document.getElementById('websiteTable');
                target.scrollIntoView({ block: 'start' });
                const search = target.querySelector('[data-search]');
                if (search) search.focus({ preventScroll: true });
            });
        });
        let tickCount = 0;""")
p.write_text(t)
