/* SiteWatch — Performance › Response time */
(function () {
    'use strict';

    const COLUMNS = 8;

    let chart = null;
    let data = null;
    let search = '';
    const state = Object.assign({ window: '24h', client: '', from: '', to: '' }, SW.page.criteria || {});
    let sequence = 0;

    function query() {
        const custom = state.from || state.to;
        return { window: custom ? '' : state.window, client: state.client, from: custom ? state.from : '', to: custom ? state.to : '' };
    }

    /** Keeps the address bar in step with the filters, so the view can be bookmarked or shared. */
    function syncUrl() {
        const params = Object.assign({ tab: undefined }, query());
        if (params.window === '24h') params.window = '';
        try { history.replaceState(history.state, '', SW.url('admin/performance.php', params)); } catch (e) { /* ignore */ }
        const exportLink = document.getElementById('rtExport');
        if (exportLink) exportLink.href = SW.url('api/reports/response-times.php', Object.assign({ export: 'csv' }, query()));
    }

    function sparkline(values) {
        const numbers = (values || []).filter(function (v) { return v !== null; });
        if (!values || values.length < 2 || !numbers.length) return '<span class="text-faint fs-12">—</span>';
        const w = 100, h = 26;
        const max = Math.max.apply(null, numbers.concat([1]));
        const pts = values.map(function (v, i) {
            const x = (i / (values.length - 1)) * w;
            const y = v === null ? h : h - (v / max) * (h - 4) - 2;
            return x.toFixed(1) + ',' + y.toFixed(1);
        }).join(' ');
        return '<svg class="sparkline" viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" role="img" aria-label="Response time trend, peak ' +
            SW.escape(SW.fmt.ms(max)) + '"><polyline fill="none" stroke="currentColor" stroke-width="1.5" points="' + pts + '"/></svg>';
    }

    function renderTable() {
        const body = document.getElementById('rtBody');
        const rows = data.rows.filter(function (r) {
            return !search || (r.name + ' ' + r.domain + ' ' + (r.client_name || '')).toLowerCase().indexOf(search) !== -1;
        });
        if (!rows.length) {
            body.innerHTML = '<tr><td colspan="' + COLUMNS + '">' + SW.emptyState(
                'bi-speedometer2',
                data.rows.length ? 'No websites match “' + search + '”.' : 'No websites to show.',
                data.rows.length ? 'Clear the filter to see every website.' : 'Add a website to start measuring response times.'
            ) + '</td></tr>';
            return;
        }
        body.innerHTML = rows.map(function (r) {
            return '<tr><td>' + SW.siteCell(r) + '</td>' +
                '<td>' + SW.badge(r.status, r.status_label, r.severity) + '</td>' +
                '<td class="num">' + SW.responseTime(r.current) + '</td>' +
                '<td class="num">' + SW.responseTime(r.avg) + '</td>' +
                '<td class="num hide-mobile">' + SW.responseTime(r.min) + '</td>' +
                '<td class="num">' + SW.responseTime(r.max) + '</td>' +
                '<td class="num hide-mobile fs-13">' + (r.checks ? SW.fmt.num(r.checks) : '<span class="text-faint">none</span>') +
                (r.down ? '<div class="fs-12 text-danger">' + r.down + ' failed</div>' : '') + '</td>' +
                '<td class="hide-mobile text-primary">' + sparkline(r.trend) + '</td></tr>';
        }).join('');
    }

    function renderChart() {
        if (!data || typeof Chart === 'undefined') return;
        const c = SW.chartDefaults();
        if (chart) { chart.destroy(); chart = null; }
        const top = data.rows.filter(function (r) { return r.avg !== null; }).slice(0, 12);
        const el = document.getElementById('chartSlowest');
        const sub = document.getElementById('rtChartSub');
        if (sub) sub.textContent = top.length ? 'Top ' + top.length + ' by average response time · ' + data.window_label : 'Average response time · ' + data.window_label;
        if (!top.length) {
            SW.chartEmpty(el, 'rtChartEmpty', 'bi-bar-chart', 'No checks recorded in the ' + data.window_label, 'Pick a longer window, or check that the monitoring cron job is running.');
            return;
        }
        SW.chartEmpty(el, 'rtChartEmpty');
        chart = new Chart(el, {
            type: 'bar',
            data: {
                labels: top.map(function (r) { return r.name; }),
                datasets: [{
                    label: 'Average response time',
                    data: top.map(function (r) { return r.avg; }),
                    backgroundColor: top.map(function (r) {
                        const cls = SW.fmt.rtClass(r.avg);
                        return cls === 'critical' ? c.danger : cls === 'slow' ? c.warning : c.primary;
                    }),
                    borderRadius: 4,
                    maxBarThickness: 18,
                }],
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { beginAtZero: true, grid: { color: c.grid }, border: { display: false }, ticks: { callback: function (v) { return SW.fmt.ms(v); } } },
                    y: { grid: { display: false }, border: { display: false }, ticks: { autoSkip: false, font: { size: 11 } } },
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: function (items) { return top[items[0].dataIndex].name; },
                            label: function (i) {
                                const r = top[i.dataIndex];
                                return ['Average: ' + SW.fmt.ms(r.avg), 'Slowest: ' + SW.fmt.ms(r.max), SW.fmt.num(r.checks) + ' checks'];
                            },
                        },
                    },
                },
                onClick: function (ev, items) { if (items.length) SW.navigate(top[items[0].index].urls.details); },
            },
        });
    }

    function renderSummary() {
        const s = data.summary;
        const set = function (k, v) { const el = document.querySelector('[data-sum="' + k + '"]'); if (el) el.textContent = v; };
        set('avg', SW.fmt.ms(s.avg));
        set('window_label', SW.fmt.num(s.websites) + (s.websites === 1 ? ' website' : ' websites') + ' · ' + data.window_label);
        set('fastest', s.fastest ? SW.fmt.ms(s.fastest.avg) : '—');
        set('fastest_name', s.fastest ? s.fastest.name : 'No data yet');
        set('slowest', s.slowest ? SW.fmt.ms(s.slowest.avg) : '—');
        set('slowest_name', s.slowest ? s.slowest.name : 'No data yet');
        set('over', s.over_threshold + ' of ' + s.websites);
        set('threshold_label', 'average ' + SW.fmt.ms(s.slow_threshold) + ' or slower');
        const over = document.querySelector('[data-sum="over"]');
        if (over) over.classList.toggle('tone-warning', s.over_threshold > 0);
        const label = document.getElementById('rtRangeLabel');
        if (label) label.textContent = data.range ? SW.fmt.date(data.range.from + ' 12:00:00') + ' – ' + SW.fmt.date(data.range.to + ' 12:00:00') : '';
        const print = document.getElementById('rtPrintRange');
        if (print) print.textContent = data.window_label + (state.client ? ' · ' + state.client : ' · All clients');
    }

    function render() {
        renderSummary();
        renderTable();
        renderChart();
    }

    async function load(silent) {
        const seq = ++sequence;
        const body = document.getElementById('rtBody');
        if (!silent && body) body.classList.add('is-refreshing');
        try {
            const res = await SW.api('api/reports/response-times.php', { query: query() });
            if (seq !== sequence) return;
            data = res.data;
            render();
        } finally {
            if (body && seq === sequence) body.classList.remove('is-refreshing');
        }
    }

    function setWindow(win) {
        SW.qsa('#rtWindow button').forEach(function (b) {
            const on = b.getAttribute('data-window') === win;
            b.classList.toggle('active', on);
            b.setAttribute('aria-pressed', String(on));
        });
        const dates = document.getElementById('rtDates');
        if (dates) dates.hidden = win !== 'custom';
    }

    document.addEventListener('sw:ready', function () {
        const body = document.getElementById('rtBody');
        if (!body) return;

        data = SW.page.initial || null;
        if (data) { render(); } else { body.innerHTML = SW.skeletonRows(COLUMNS, 6); }
        syncUrl();

        SW.qsa('#rtWindow button').forEach(function (b) {
            b.addEventListener('click', function () {
                const win = b.getAttribute('data-window');
                setWindow(win);
                if (win === 'custom') {
                    const from = document.getElementById('rtFrom');
                    if (from && !from.value) {
                        const range = data && data.range ? data.range : null;
                        from.value = range ? range.from : '';
                        document.getElementById('rtTo').value = range ? range.to : '';
                    }
                    if (from) from.focus();
                    return;
                }
                state.window = win;
                state.from = '';
                state.to = '';
                syncUrl();
                load().catch(function (e) { SW.toast(e.message, 'danger'); });
            });
        });
        ['rtFrom', 'rtTo'].forEach(function (id) {
            const input = document.getElementById(id);
            if (!input) return;
            input.addEventListener('change', function () {
                state.from = document.getElementById('rtFrom').value;
                state.to = document.getElementById('rtTo').value;
                if (!state.from && !state.to) return;
                syncUrl();
                load().catch(function (e) { SW.toast(e.message, 'danger'); });
            });
        });
        document.getElementById('rtClient').addEventListener('change', function () {
            state.client = this.value;
            syncUrl();
            load().catch(function (e) { SW.toast(e.message, 'danger'); });
        });
        document.getElementById('rtSearch').addEventListener('input', SW.debounce(function () {
            search = this.value.trim().toLowerCase();
            if (data) renderTable();
        }, 150));

        SW.poll(function () {
            if (state.from || state.to || body.contains(document.activeElement)) return Promise.resolve();
            return load(true);
        }, Math.max(60000, (SW.config.refresh || 30) * 2000));
        document.addEventListener('sw:theme', renderChart);
    });
})();
