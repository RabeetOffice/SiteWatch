/* SiteWatch — response times page */
(function () {
    'use strict';

    const COLUMNS = 9;
    const WINDOW_LABELS = { '24h': 'last 24 hours', '7d': 'last 7 days' };

    let chart = null;
    let data = null;
    let win = SW.storage.get('sw-rt-window', '24h');
    let filter = '';

    function windowLabel() { return WINDOW_LABELS[win] || 'selected window'; }

    function sparkline(values) {
        if (!values || values.length < 2) return '<span class="text-faint fs-12">—</span>';
        const w = 100, h = 26;
        const numbers = values.filter(function (v) { return v !== null; });
        if (!numbers.length) return '<span class="text-faint fs-12">—</span>';
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
            return !filter || (r.name + ' ' + r.domain + ' ' + r.client_name).toLowerCase().indexOf(filter) !== -1;
        });
        if (!rows.length) {
            body.innerHTML = '<tr><td colspan="' + COLUMNS + '">' + SW.emptyState(
                'bi-speedometer2',
                data.rows.length ? 'No websites match the filter.' : 'No response data recorded yet.',
                data.rows.length ? 'Clear the filter to see every website.' : 'Measurements appear after the first monitoring runs.'
            ) + '</td></tr>';
            return;
        }
        body.innerHTML = rows.map(function (r) {
            return '<tr><td><div class="site-cell">' + SW.favicon(r.favicon_url, r.domain) +
                '<div class="site-text"><a class="site-name" href="' + SW.escape(r.urls.details) + '" title="' + SW.escape(r.name) + '">' + SW.escape(r.name) + '</a>' +
                '<span class="site-domain" title="' + SW.escape(r.domain) + '">' + SW.escape(r.domain) + '</span></div></div></td>' +
                '<td class="hide-mobile">' + (r.client_name ? '<span class="client-name" title="' + SW.escape(r.client_name) + '">' + SW.escape(r.client_name) + '</span>' : '<span class="text-faint">—</span>') + '</td>' +
                '<td>' + SW.badge(r.status, r.status_label, r.severity) + '</td>' +
                '<td class="num">' + SW.responseTime(r.current) + '</td>' +
                '<td class="num">' + SW.responseTime(r.avg) + '</td>' +
                '<td class="num hide-mobile">' + SW.responseTime(r.min) + '</td>' +
                '<td class="num">' + SW.responseTime(r.max) + '</td>' +
                '<td class="num hide-mobile fs-13">' + SW.fmt.num(r.checks) + (r.down ? '<div class="fs-12 text-danger">' + r.down + ' failed</div>' : '') + '</td>' +
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
        if (sub) {
            sub.textContent = top.length
                ? 'Top ' + top.length + ' by average response time · ' + windowLabel()
                : 'No response data recorded in the ' + windowLabel();
        }
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
                    maxBarThickness: 20,
                }],
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        beginAtZero: true,
                        grid: { color: c.grid },
                        border: { display: false },
                        ticks: { callback: function (v) { return SW.fmt.ms(v); } },
                        title: { display: true, text: 'Average response time · ' + windowLabel(), color: c.text, font: { size: 11 } },
                    },
                    y: { grid: { display: false }, border: { display: false }, ticks: { autoSkip: false, font: { size: 11 } } },
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: function (items) { return top[items[0].dataIndex].name; },
                            label: function (i) {
                                const r = top[i.dataIndex];
                                return ['Average: ' + SW.fmt.ms(r.avg), 'Slowest: ' + SW.fmt.ms(r.max), SW.fmt.num(r.checks) + ' checks in the ' + windowLabel()];
                            },
                        },
                    },
                },
            },
        });
    }

    function renderSummary() {
        const s = data.summary;
        const set = function (k, v) { const el = document.querySelector('[data-sum="' + k + '"]'); if (el) el.textContent = v; };
        set('avg', SW.fmt.ms(s.avg));
        set('window_label', 'Across ' + SW.fmt.num(s.websites) + (s.websites === 1 ? ' website' : ' websites') + ' · ' + windowLabel());
        set('fastest', s.fastest ? SW.fmt.ms(s.fastest.avg) : '—');
        set('fastest_name', s.fastest ? s.fastest.name : 'No data');
        set('slowest', s.slowest ? SW.fmt.ms(s.slowest.avg) : '—');
        set('slowest_name', s.slowest ? s.slowest.name : 'No data');
        set('over', s.over_threshold + ' of ' + s.websites);
        set('threshold_label', 'Slow threshold ' + SW.fmt.ms(SW.thresholds.slow));
        const tableSub = document.getElementById('rtTableSub');
        if (tableSub) {
            tableSub.textContent = 'Current is the most recent recorded check · average, fastest and slowest cover the ' + windowLabel();
        }
    }

    async function load() {
        const res = await SW.api('api/reports/response-times.php', { query: { window: win } });
        data = res.data;
        renderSummary();
        renderTable();
        renderChart();
    }

    document.addEventListener('sw:ready', function () {
        const body = document.getElementById('rtBody');
        if (!body) return;
        body.innerHTML = SW.skeletonRows(COLUMNS, 6);

        SW.qsa('#rtWindow button').forEach(function (b) {
            const active = b.getAttribute('data-window') === win;
            b.classList.toggle('active', active);
            b.setAttribute('aria-pressed', String(active));
            b.addEventListener('click', function () {
                win = b.getAttribute('data-window');
                SW.storage.set('sw-rt-window', win);
                SW.qsa('#rtWindow button').forEach(function (x) {
                    x.classList.toggle('active', x === b);
                    x.setAttribute('aria-pressed', String(x === b));
                });
                load().catch(function (e) { SW.toast(e.message, 'danger'); });
            });
        });

        document.getElementById('rtSearch').addEventListener('input', SW.debounce(function () {
            filter = this.value.trim().toLowerCase();
            if (data) renderTable();
        }, 200));

        load().catch(function (e) { SW.toast(e.message, 'danger'); });
        SW.poll(function () {
            if (body.contains(document.activeElement)) return Promise.resolve();
            return load();
        }, Math.max(30000, (SW.config.refresh || 30) * 1000));
        document.addEventListener('sw:theme', renderChart);
    });
})();
