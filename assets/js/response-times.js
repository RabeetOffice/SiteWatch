/* SiteWatch — response times page */
(function () {
    'use strict';

    let chart = null;
    let data = null;
    let win = SW.storage.get('sw-rt-window', '24h');
    let filter = '';

    function sparkline(values) {
        if (!values || values.length < 2) return '';
        const w = 110, h = 30, max = Math.max.apply(null, values.filter(function (v) { return v !== null; }).concat([1]));
        const pts = values.map(function (v, i) { const x = (i / (values.length - 1)) * w; const y = v === null ? h : h - (v / max) * (h - 4) - 2; return x.toFixed(1) + ',' + y.toFixed(1); }).join(' ');
        return '<svg class="sparkline" viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none"><polyline fill="none" stroke="currentColor" stroke-width="1.5" points="' + pts + '"/></svg>';
    }

    function renderTable() {
        const body = document.getElementById('rtBody');
        const rows = data.rows.filter(function (r) { return !filter || (r.name + ' ' + r.domain + ' ' + r.client_name).toLowerCase().indexOf(filter) !== -1; });
        if (!rows.length) { body.innerHTML = '<tr><td colspan="9">' + SW.emptyState('bi-speedometer2', data.rows.length ? 'No websites match the filter.' : 'No response data yet.', data.rows.length ? '' : 'Data appears after the first monitoring runs.') + '</td></tr>'; return; }
        body.innerHTML = rows.map(function (r) {
            return '<tr><td><div class="site-cell">' + SW.favicon(r.favicon_url, r.domain) + '<div class="min-w-0"><a class="site-name" href="' + SW.escape(r.urls.details) + '">' + SW.escape(r.name) + '</a><span class="site-domain">' + SW.escape(r.domain) + '</span></div></div></td>' +
                '<td class="hide-mobile fs-13">' + (r.client_name ? SW.escape(r.client_name) : '<span class="text-faint">—</span>') + '</td>' +
                '<td>' + SW.badge(r.status, r.status_label, r.severity) + '</td>' +
                '<td>' + SW.responseTime(r.current) + '</td><td>' + SW.responseTime(r.avg) + '</td>' +
                '<td class="hide-mobile">' + SW.responseTime(r.min) + '</td><td>' + SW.responseTime(r.max) + '</td>' +
                '<td class="hide-mobile fs-13">' + r.checks + (r.down ? ' <span class="text-danger">(' + r.down + ' down)</span>' : '') + '</td>' +
                '<td class="hide-mobile text-primary">' + sparkline(r.trend) + '</td></tr>';
        }).join('');
    }

    function renderChart() {
        if (!data || typeof Chart === 'undefined') return;
        const c = SW.chartColors();
        if (chart) { chart.destroy(); chart = null; }
        const top = data.rows.filter(function (r) { return r.avg !== null; }).slice(0, 12);
        const el = document.getElementById('chartSlowest');
        document.getElementById('rtChartSub').textContent = top.length ? 'Top ' + top.length + ' slowest by average response · ' + (win === '24h' ? 'last 24 hours' : 'last 7 days') : 'No response data for this window';
        chart = new Chart(el, {
            type: 'bar',
            data: { labels: top.map(function (r) { return r.name; }), datasets: [{ data: top.map(function (r) { return r.avg; }), backgroundColor: top.map(function (r) { const cls = SW.fmt.rtClass(r.avg); return cls === 'critical' ? c.danger : cls === 'slow' ? c.warning : c.primary; }), borderRadius: 6, maxBarThickness: 22 }] },
            options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, scales: { x: { beginAtZero: true, grid: { color: c.grid }, ticks: { callback: function (v) { return SW.fmt.ms(v); } } }, y: { grid: { display: false }, ticks: { autoSkip: false, font: { size: 11 } } } }, plugins: { tooltip: { callbacks: { label: function (i) { const r = top[i.dataIndex]; return ' avg ' + SW.fmt.ms(r.avg) + ' · max ' + SW.fmt.ms(r.max) + ' · ' + r.checks + ' checks'; } } } } },
        });
    }

    function renderSummary() {
        const s = data.summary;
        const set = function (k, v) { const el = document.querySelector('[data-sum="' + k + '"]'); if (el) el.textContent = v; };
        set('avg', SW.fmt.ms(s.avg));
        set('fastest', s.fastest ? SW.fmt.ms(s.fastest.avg) : '—'); set('fastest_name', s.fastest ? s.fastest.name : '');
        set('slowest', s.slowest ? SW.fmt.ms(s.slowest.avg) : '—'); set('slowest_name', s.slowest ? s.slowest.name : '');
        set('over', s.over_threshold + ' of ' + s.websites);
    }

    async function load() {
        const res = await SW.api('api/reports/response-times.php', { query: { window: win } });
        data = res.data;
        renderTable(); renderChart(); renderSummary();
    }

    document.addEventListener('sw:ready', function () {
        if (!document.getElementById('rtBody')) return;
        document.getElementById('rtBody').innerHTML = SW.skeletonRows(9, 6);
        SW.qsa('#rtWindow button').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-window') === win); b.addEventListener('click', function () { win = b.getAttribute('data-window'); SW.storage.set('sw-rt-window', win); SW.qsa('#rtWindow button').forEach(function (x) { x.classList.toggle('active', x === b); }); load().catch(function (e) { SW.toast(e.message, 'danger'); }); }); });
        document.getElementById('rtSearch').addEventListener('input', SW.debounce(function () { filter = this.value.trim().toLowerCase(); if (data) renderTable(); }, 200));
        load().catch(function (e) { SW.toast(e.message, 'danger'); });
        SW.poll(load, Math.max(30000, (SW.config.refresh || 30) * 1000));
        document.addEventListener('sw:theme', renderChart);
    });
})();
