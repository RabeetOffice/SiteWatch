/* SiteWatch — dashboard page */
(function () {
    'use strict';

    const charts = {};
    let websiteTable = null;

    function renderKpi(stats) {
        const kpi = stats.kpi;
        const set = function (key, value) { const el = document.querySelector('[data-kpi="' + key + '"]'); if (el) el.textContent = value; };
        set('total', SW.fmt.num(kpi.total));
        set('total_sub', kpi.paused + ' paused' + (kpi.pending ? ', ' + kpi.pending + ' pending' : ''));
        set('online', SW.fmt.num(kpi.online));
        set('down', SW.fmt.num(kpi.down));
        set('warnings', SW.fmt.num(kpi.warnings));
        set('avg_response', kpi.avg_response_label);
        set('open_incidents', SW.fmt.num(kpi.open_incidents));
        const downCard = document.querySelector('[data-kpi-card="down"]');
        if (downCard) downCard.classList.toggle('attention', kpi.down > 0);
        const warnCard = document.querySelector('[data-kpi-card="warnings"]');
        if (warnCard) warnCard.classList.toggle('attention-warning', kpi.warnings > 0);
        const incCard = document.querySelector('[data-kpi-card="open_incidents"]');
        if (incCard) incCard.classList.toggle('attention', kpi.open_incidents > 0);
        SW.updateEngine(stats.engine);
        SW.updateIncidentCount(kpi.open_incidents);
    }

    function renderHealth(stats) {
        const h = stats.health, o = stats.overview;
        const total = Math.max(1, h.healthy + h.warning + h.down + h.paused);
        const pct = function (n) { return (n / total * 100).toFixed(1) + '%'; };
        const el = document.getElementById('healthOverview');
        if (!el) return;
        if (h.healthy + h.warning + h.down + h.paused === 0) {
            el.innerHTML = SW.emptyState('bi-globe2', 'No websites are being monitored yet.', 'Add your first client website to start collecting health data.',
                '<a class="btn btn-primary btn-sm" href="' + SW.url('admin/website-add.php') + '"><i class="bi bi-plus-lg"></i> Add Your First Website</a>');
            return;
        }
        el.innerHTML =
            '<div class="health-bar" role="img" aria-label="Health distribution">' +
            '<span class="h-ok" style="width:' + pct(h.healthy) + '"></span>' +
            '<span class="h-warn" style="width:' + pct(h.warning) + '"></span>' +
            '<span class="h-down" style="width:' + pct(h.down) + '"></span>' +
            '<span class="h-paused" style="width:' + pct(h.paused) + '"></span></div>' +
            '<div class="health-legend">' +
            '<div class="item"><span class="dot dot-ok"></span><b>' + h.healthy + '</b> Healthy</div>' +
            '<div class="item"><span class="dot dot-warn"></span><b>' + h.warning + '</b> Warning</div>' +
            '<div class="item"><span class="dot dot-down"></span><b>' + h.down + '</b> Down</div>' +
            (h.paused ? '<div class="item"><span class="dot dot-paused"></span><b>' + h.paused + '</b> Paused / pending</div>' : '') +
            '</div><div class="overview-list mt-4">' +
            '<div class="overview-item"><div class="l">Overall Uptime · 30d</div><div class="v">' + SW.escape(o.uptime_30d_label) + '</div></div>' +
            '<div class="overview-item"><div class="l">Average Response · today</div><div class="v">' + SW.escape(o.avg_response_24h_label) + '</div></div>' +
            '<div class="overview-item"><div class="l">Incidents This Month</div><div class="v">' + SW.escape(o.incidents_month) + '</div></div>' +
            '</div>';
    }

    function renderIncidents(list) {
        const el = document.getElementById('recentIncidents');
        if (!el) return;
        if (!list.length) { el.innerHTML = SW.emptyState('bi-shield-check', 'Everything looks healthy.', 'No incidents detected.'); return; }
        el.innerHTML = list.map(function (i) {
            return '<div class="incident-row ' + (i.is_open ? 'open' : '') + '"><span class="marker"></span><div class="body">' +
                '<div class="title">' + SW.badge(i.type, i.title, i.is_open ? 'down' : 'ok', 'no-dot') +
                '<a href="' + SW.escape(i.urls.website) + '" class="text-reset">' + SW.escape(i.website_name) + '</a>' +
                (i.client_name ? '<span class="text-muted fw-normal">· ' + SW.escape(i.client_name) + '</span>' : '') + '</div>' +
                '<div class="meta">Started ' + SW.escape(i.started_label) + ' · ' + (i.is_open ? 'ongoing for ' + SW.escape(i.duration_label) : 'resolved after ' + SW.escape(i.duration_label)) +
                (i.http_status ? ' · HTTP ' + SW.escape(i.http_status) : '') + '</div>' +
                (i.error_message ? '<div class="msg">' + SW.escape(i.error_message) + '</div>' : '') +
                '</div></div>';
        }).join('');
    }

    function renderActivity(list) {
        const el = document.getElementById('recentActivity');
        if (!el) return;
        if (!list.length) { el.innerHTML = SW.emptyState('bi-clock-history', 'No activity yet.', 'Administrative and monitoring events will appear here.'); return; }
        const icons = { 'incident.opened': 'bi-x-octagon', 'incident.resolved': 'bi-check-circle', 'website.added': 'bi-plus-circle', 'website.edited': 'bi-pencil', 'website.deleted': 'bi-trash', 'monitoring.paused': 'bi-pause-circle', 'monitoring.resumed': 'bi-play-circle', 'ssl.warning': 'bi-shield-exclamation', 'ssl.renewed': 'bi-shield-check', 'settings.changed': 'bi-gear', 'auth.login': 'bi-box-arrow-in-right', 'auth.logout': 'bi-box-arrow-right', 'website.imported': 'bi-upload', 'website.warning': 'bi-exclamation-triangle' };
        el.innerHTML = list.map(function (a) {
            return '<div class="activity-item"><span class="activity-icon tone-' + SW.escape(a.tone) + '"><i class="bi ' + (icons[a.action] || 'bi-dot') + '"></i></span>' +
                '<div class="b"><div class="t">' + SW.escape(a.description) + '</div><div class="m">' + SW.escape(a.action_label) + (a.user_name ? ' · ' + SW.escape(a.user_name) : '') + ' · ' + SW.timeAgoEl(a.created_at) + '</div></div></div>';
        }).join('');
    }

    function chartDefaults() {
        const c = SW.chartColors();
        Chart.defaults.color = c.text;
        Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
        Chart.defaults.font.size = 11.5;
        Chart.defaults.plugins.legend.display = false;
        Chart.defaults.plugins.tooltip.backgroundColor = c.tooltipBg;
        Chart.defaults.plugins.tooltip.titleColor = c.tooltipText;
        Chart.defaults.plugins.tooltip.bodyColor = c.tooltipText;
        Chart.defaults.plugins.tooltip.cornerRadius = 8;
        Chart.defaults.plugins.tooltip.padding = 10;
        return c;
    }

    function renderCharts(data) {
        if (typeof Chart === 'undefined') return;
        const c = chartDefaults();
        Object.keys(charts).forEach(function (k) { charts[k].destroy(); delete charts[k]; });

        const grid = { color: c.grid, drawBorder: false };
        const responseEl = document.getElementById('chartResponse');
        if (responseEl) {
            charts.response = new Chart(responseEl, {
                type: 'line',
                data: { labels: data.response_trend.labels, datasets: [{ data: data.response_trend.values, borderColor: c.primary, backgroundColor: c.primarySoft, fill: true, tension: 0.35, pointRadius: 0, pointHitRadius: 12, borderWidth: 2, spanGaps: true }] },
                options: {
                    responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
                    scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } }, y: { beginAtZero: true, grid: grid, ticks: { callback: function (v) { return SW.fmt.ms(v); }, maxTicksLimit: 5 } } },
                    plugins: { tooltip: { callbacks: { title: function (items) { return data.response_trend.timestamps[items[0].dataIndex]; }, label: function (item) { return ' ' + SW.fmt.ms(item.raw); } } } },
                },
            });
            if (!data.response_trend.values.length) { responseEl.parentElement.insertAdjacentHTML('beforeend', '<div class="empty-state py-4" data-empty><p class="mb-0">No checks recorded in the last 24 hours.</p></div>'); responseEl.style.display = 'none'; } else { responseEl.style.display = ''; SW.qsa('[data-empty]', responseEl.parentElement).forEach(function (e) { e.remove(); }); }
        }

        const statusEl = document.getElementById('chartStatus');
        if (statusEl) {
            const total = data.status_distribution.values.reduce(function (a, b) { return a + b; }, 0);
            charts.status = new Chart(statusEl, {
                type: 'doughnut',
                data: { labels: data.status_distribution.labels, datasets: [{ data: data.status_distribution.values, backgroundColor: [c.success, c.warning, c.danger, c.neutral], borderWidth: 0, hoverOffset: 4 }] },
                options: { responsive: true, maintainAspectRatio: false, cutout: '68%', plugins: { legend: { display: true, position: 'right', labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, pointStyle: 'circle', padding: 12 } }, tooltip: { callbacks: { label: function (item) { return ' ' + item.label + ': ' + item.raw + (total ? ' (' + Math.round(item.raw / total * 100) + '%)' : ''); } } } } },
            });
        }

        const incEl = document.getElementById('chartIncidents');
        if (incEl) {
            charts.incidents = new Chart(incEl, {
                type: 'bar',
                data: { labels: data.incidents_30d.labels, datasets: [{ data: data.incidents_30d.values, backgroundColor: c.danger, borderRadius: 4, maxBarThickness: 18 }] },
                options: { responsive: true, maintainAspectRatio: false, scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 6 } }, y: { beginAtZero: true, grid: grid, ticks: { precision: 0, maxTicksLimit: 5 } } }, plugins: { tooltip: { callbacks: { label: function (item) { return ' ' + item.raw + ' incident' + (item.raw === 1 ? '' : 's'); } } } } },
            });
        }

        const upEl = document.getElementById('chartUptime');
        if (upEl) {
            const values = data.uptime_trend.values;
            const min = values.filter(function (v) { return v !== null; }).reduce(function (m, v) { return Math.min(m, v); }, 100);
            charts.uptime = new Chart(upEl, {
                type: 'line',
                data: { labels: data.uptime_trend.labels, datasets: [{ data: values, borderColor: c.success, backgroundColor: 'rgba(22,163,74,0.12)', fill: true, tension: 0.3, pointRadius: 2, pointHitRadius: 10, borderWidth: 2, spanGaps: false }] },
                options: { responsive: true, maintainAspectRatio: false, scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 6 } }, y: { min: Math.max(0, Math.floor(min - 1)), max: 100, grid: grid, ticks: { callback: function (v) { return v + '%'; }, maxTicksLimit: 5 } } }, plugins: { tooltip: { callbacks: { label: function (item) { return ' ' + (item.raw === null ? 'no data' : SW.fmt.uptime(item.raw)); } } } } },
            });
        }
    }

    async function refreshStats() {
        const res = await SW.api('api/dashboard/stats.php');
        renderKpi(res.data.stats);
        renderHealth(res.data.stats);
        renderIncidents(res.data.incidents);
        renderActivity(res.data.activity);
    }

    let chartData = null;
    async function refreshCharts() {
        const res = await SW.api('api/dashboard/charts.php');
        chartData = res.data;
        renderCharts(chartData);
    }

    document.addEventListener('sw:ready', function () {
        const initial = SW.page.initial;
        if (initial) {
            renderKpi(initial.stats);
            renderHealth(initial.stats);
            renderIncidents(initial.incidents);
            renderActivity(initial.activity);
        }
        refreshCharts().catch(function (e) { SW.toast(e.message, 'danger'); });

        if (window.SW.WebsiteTable) {
            websiteTable = new SW.WebsiteTable(document.getElementById('websiteTable'), {
                perPage: 10, storageKey: 'sw-dashboard-table', title: 'Websites',
                subtitle: 'Most severe first — open the Websites page for bulk actions and import',
                bulk: false, compact: false, footerLink: { href: SW.url('admin/websites.php'), label: 'Open full website list' },
            });
        }

        let tickCount = 0;
        SW.poll(async function () {
            await refreshStats();
            if (websiteTable) await websiteTable.refresh(true);
            tickCount++;
            if (tickCount % 4 === 0) await refreshCharts();
        }, Math.max(15000, (SW.config.refresh || 30) * 1000));

        document.addEventListener('sw:theme', function () { if (chartData) renderCharts(chartData); });
        document.addEventListener('sw:website-changed', function () { refreshStats().catch(function () {}); });
    });
})();
