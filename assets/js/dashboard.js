/* SiteWatch — dashboard page */
(function () {
    'use strict';

    const charts = {};
    let websiteTable = null;
    let chartData = null;

    function setText(key, value) {
        const el = document.querySelector('[data-kpi="' + key + '"]');
        if (el) el.textContent = value;
    }

    function setTone(key, tone) {
        const el = document.querySelector('[data-kpi-item="' + key + '"]');
        if (!el) return;
        el.classList.toggle('tone-danger', tone === 'danger');
        el.classList.toggle('tone-warning', tone === 'warning');
    }

    function renderKpi(stats) {
        const kpi = stats.kpi;
        setText('total', SW.fmt.num(kpi.total));
        setText('total_sub', kpi.paused + ' paused' + (kpi.pending ? ', ' + kpi.pending + ' pending' : ''));
        setText('online', SW.fmt.num(kpi.online));
        setText('down', SW.fmt.num(kpi.down));
        setText('warnings', SW.fmt.num(kpi.warnings));
        setText('avg_response', kpi.avg_response_label);
        setText('open_incidents', SW.fmt.num(kpi.open_incidents));
        setTone('down', kpi.down > 0 ? 'danger' : null);
        setTone('warnings', kpi.warnings > 0 ? 'warning' : null);
        setTone('open_incidents', kpi.open_incidents > 0 ? 'danger' : null);
        SW.updateEngine(stats.engine);
        SW.updateIncidentCount(kpi.open_incidents);
    }

    function renderHealth(stats) {
        const h = stats.health, o = stats.overview;
        const el = document.getElementById('healthOverview');
        if (!el) return;
        const total = h.healthy + h.warning + h.down + h.paused;
        if (total === 0) {
            el.innerHTML = SW.emptyState('bi-globe2', 'No websites are being monitored yet.', 'Add your first client website to start collecting health data.',
                SW.can('websites.manage') ? '<a class="btn btn-sm btn-primary" href="' + SW.url('admin/website-add.php') + '"><i class="bi bi-plus-lg"></i> Add your first website</a>' : '');
            return;
        }
        const pct = function (n) { return (n / total * 100).toFixed(1) + '%'; };
        el.innerHTML =
            '<div class="health-bar" role="img" aria-label="' + h.healthy + ' healthy, ' + h.warning + ' warning, ' + h.down + ' down, ' + h.paused + ' paused or pending">' +
            '<span class="h-ok" style="width:' + pct(h.healthy) + '"></span>' +
            '<span class="h-warn" style="width:' + pct(h.warning) + '"></span>' +
            '<span class="h-down" style="width:' + pct(h.down) + '"></span>' +
            '<span class="h-paused" style="width:' + pct(h.paused) + '"></span></div>' +
            '<div class="health-legend">' +
            '<div class="item"><span class="dot dot-ok"></span><b>' + h.healthy + '</b> Healthy</div>' +
            '<div class="item"><span class="dot dot-warn"></span><b>' + h.warning + '</b> Warning</div>' +
            '<div class="item"><span class="dot dot-down"></span><b>' + h.down + '</b> Down</div>' +
            (h.paused ? '<div class="item"><span class="dot dot-paused"></span><b>' + h.paused + '</b> Paused or pending</div>' : '') +
            '</div><div class="overview-list mt-3">' +
            '<div class="overview-item"><div class="l">Observed uptime · 30 days</div><div class="v">' + SW.escape(o.uptime_30d_label) + '</div></div>' +
            '<div class="overview-item"><div class="l">Average response · 24 hours</div><div class="v">' + SW.escape(o.avg_response_24h_label) + '</div></div>' +
            '<div class="overview-item"><div class="l">Incidents this month</div><div class="v">' + SW.escape(o.incidents_month) + '</div></div>' +
            '</div>';
    }

    function renderIncidents(list) {
        const el = document.getElementById('recentIncidents');
        if (!el) return;
        const open = list.filter(function (i) { return i.is_open; });
        if (!open.length) {
            el.innerHTML = SW.emptyState('bi-shield-check', 'No open incidents.', 'Warnings and last check times are shown in the website list below.');
            return;
        }
        el.innerHTML = open.map(function (i) {
            return '<div class="incident-row open"><span class="marker" aria-hidden="true"></span><div class="body">' +
                '<div class="title">' +
                '<a href="' + SW.escape(i.urls.website) + '" class="text-reset">' + SW.escape(i.website_name) + '</a>' +
                SW.badge(i.type, i.title, 'down', 'no-dot') +
                (i.client_name ? '<span class="text-muted fw-normal fs-13">' + SW.escape(i.client_name) + '</span>' : '') + '</div>' +
                '<div class="meta">Down for <b>' + SW.escape(i.duration_label) + '</b> · started ' + SW.escape(i.started_label) +
                (i.http_status ? ' · HTTP ' + SW.escape(i.http_status) : '') +
                ' · ' + (i.notified_at ? 'alert sent' : 'no alert recorded') + '</div>' +
                (i.error_message ? '<div class="msg">' + SW.escape(i.error_message) + '</div>' : '') +
                '</div><a class="btn btn-sm btn-light" href="' + SW.escape(i.urls.website) + '">Investigate</a></div>';
        }).join('');
    }

    const ACTIVITY_ICONS = {
        'incident.opened': 'bi-x-octagon', 'incident.resolved': 'bi-check-circle', 'website.added': 'bi-plus-circle',
        'website.edited': 'bi-pencil', 'website.deleted': 'bi-trash', 'monitoring.paused': 'bi-pause-circle',
        'monitoring.resumed': 'bi-play-circle', 'ssl.warning': 'bi-shield-exclamation', 'ssl.renewed': 'bi-shield-check',
        'settings.changed': 'bi-gear', 'auth.login': 'bi-box-arrow-in-right', 'auth.logout': 'bi-box-arrow-right',
        'website.imported': 'bi-upload', 'website.warning': 'bi-exclamation-triangle',
    };

    function renderActivity(list) {
        const el = document.getElementById('recentActivity');
        if (!el) return;
        if (!list.length) {
            el.innerHTML = SW.emptyState('bi-clock-history', 'No activity yet.', 'Administrative and monitoring events will appear here.');
            return;
        }
        el.innerHTML = list.map(function (a) {
            return '<div class="activity-item"><span class="activity-icon tone-' + SW.escape(a.tone) + '" aria-hidden="true"><i class="bi ' + (ACTIVITY_ICONS[a.action] || 'bi-dot') + '"></i></span>' +
                '<div class="b"><div class="t">' + SW.escape(a.description) + '</div>' +
                '<div class="m"><span>' + SW.escape(a.action_label) + '</span>' + (a.user_name ? '<span>' + SW.escape(a.user_name) + '</span>' : '') +
                '<span>' + SW.timeAgoEl(a.created_at) + '</span></div></div></div>';
        }).join('');
    }

    function renderCharts(data) {
        if (typeof Chart === 'undefined') {
            const note = document.getElementById('responseSampleNote');
            if (note) note.textContent = 'Charts could not load. Check your connection and reload this page.';
            return;
        }
        const c = SW.chartDefaults();
        const grid = { color: c.grid, drawBorder: false };

        const note = document.getElementById('responseSampleNote');
        if (note) {
            const n = data.response_trend.values.filter(function (v) { return v !== null; }).length;
            note.textContent = n === 0
                ? 'No checks recorded in the last 24 hours.'
                : (n === 1
                    ? '1 recorded interval — more checks are needed before a trend is meaningful.'
                    : n + ' recorded 30-minute intervals. Gaps mean no checks were recorded, not that websites were available.');
        }

        Object.keys(charts).forEach(function (k) { charts[k].destroy(); delete charts[k]; });

        const responseEl = document.getElementById('chartResponse');
        if (responseEl) {
            charts.response = new Chart(responseEl, {
                type: 'line',
                data: {
                    labels: data.response_trend.labels,
                    datasets: [{
                        label: 'Average response time',
                        data: data.response_trend.values,
                        borderColor: c.primary,
                        backgroundColor: c.primarySoft,
                        fill: true,
                        tension: 0.3,
                        pointRadius: data.response_trend.values.length === 1 ? 4 : 0,
                        pointHoverRadius: 4,
                        pointHitRadius: 12,
                        borderWidth: 2,
                        spanGaps: false,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    scales: {
                        x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } },
                        y: {
                            beginAtZero: true,
                            grid: grid,
                            border: { display: false },
                            ticks: { callback: function (v) { return SW.fmt.ms(v); }, maxTicksLimit: 5 },
                            title: { display: true, text: 'Response time', color: c.text, font: { size: 11 } },
                        },
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                title: function (items) { return data.response_trend.timestamps[items[0].dataIndex]; },
                                label: function (item) { return 'Average response: ' + SW.fmt.ms(item.raw); },
                            },
                        },
                    },
                },
            });
            SW.qsa('[data-empty]', responseEl.parentElement).forEach(function (e) { e.remove(); });
            if (!data.response_trend.values.length) {
                responseEl.parentElement.insertAdjacentHTML('beforeend', '<div class="empty-state py-4" data-empty><p class="mb-0">No checks recorded in the last 24 hours.</p></div>');
                responseEl.style.display = 'none';
            } else {
                responseEl.style.display = '';
            }
        }

        const incEl = document.getElementById('chartIncidents');
        if (incEl) {
            charts.incidents = new Chart(incEl, {
                type: 'bar',
                data: {
                    labels: data.incidents_30d.labels,
                    datasets: [{ label: 'New incidents', data: data.incidents_30d.values, backgroundColor: c.danger, borderRadius: 3, maxBarThickness: 14 }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: { grid: { display: false }, ticks: { maxTicksLimit: 6 } },
                        y: { beginAtZero: true, grid: grid, border: { display: false }, ticks: { precision: 0, maxTicksLimit: 5 }, title: { display: true, text: 'Incidents', color: c.text, font: { size: 11 } } },
                    },
                    plugins: { tooltip: { callbacks: { label: function (item) { return item.raw + ' incident' + (item.raw === 1 ? '' : 's'); } } } },
                },
            });
        }

        const upEl = document.getElementById('chartUptime');
        if (upEl) {
            const values = data.uptime_trend.values;
            const min = values.filter(function (v) { return v !== null; }).reduce(function (m, v) { return Math.min(m, v); }, 100);
            charts.uptime = new Chart(upEl, {
                type: 'line',
                data: {
                    labels: data.uptime_trend.labels,
                    datasets: [{
                        label: 'Observed uptime',
                        data: values,
                        borderColor: c.success,
                        backgroundColor: c.successSoft,
                        fill: true,
                        tension: 0.25,
                        pointRadius: 0,
                        pointHoverRadius: 4,
                        pointHitRadius: 10,
                        borderWidth: 2,
                        spanGaps: false,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    scales: {
                        x: { grid: { display: false }, ticks: { maxTicksLimit: 6 } },
                        y: {
                            min: Math.max(0, Math.floor(min - 1)),
                            max: 100,
                            grid: grid,
                            border: { display: false },
                            ticks: { callback: function (v) { return v + '%'; }, maxTicksLimit: 5 },
                            title: { display: true, text: 'Uptime', color: c.text, font: { size: 11 } },
                        },
                    },
                    plugins: { tooltip: { callbacks: { label: function (item) { return item.raw === null ? 'No checks recorded' : 'Observed uptime: ' + SW.fmt.uptime(item.raw); } } } },
                },
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
                perPage: 8,
                storageKey: 'sw-dashboard-table',
                title: 'Websites',
                subtitle: 'Most severe first',
                bulk: false,
                compact: true,
                footerLink: { href: SW.url('admin/websites.php'), label: 'Open the full website list' },
            });
        }

        SW.qsa('[data-overview-filter]').forEach(function (button) {
            button.addEventListener('click', function () {
                if (!websiteTable) return;
                const filter = button.dataset.overviewFilter;
                websiteTable.state.filter = filter;
                websiteTable.state.q = '';
                websiteTable.state.client = '';
                websiteTable.state.page = 1;
                websiteTable.state.sort = 'status';
                websiteTable.state.dir = '';
                websiteTable.persist();
                websiteTable.syncControls();
                websiteTable.load();
                SW.qsa('[data-overview-filter]').forEach(function (b) { b.setAttribute('aria-pressed', String(b === button)); });
                const target = document.getElementById('websiteTable');
                target.scrollIntoView({ block: 'start', behavior: 'smooth' });
                const search = target.querySelector('[data-search]');
                if (search) search.focus({ preventScroll: true });
            });
        });

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
