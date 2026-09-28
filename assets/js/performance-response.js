/* SiteWatch — Performance › Response time */
(function () {
    'use strict';

    const COLUMNS = 8;
    const RANK_SHORT = 12;
    const BANDS = [
        ['fast', 'Healthy', 'success'],
        ['moderate', 'Moderate', 'info'],
        ['slow', 'Slow', 'warning'],
        ['critical', 'Critically slow', 'danger'],
    ];

    let data = null;
    let search = '';
    let showAll = false;
    const state = Object.assign({ window: '24h', client: '', from: '', to: '' }, SW.page.criteria || {});
    let sequence = 0;

    function query() {
        const custom = state.from || state.to;
        return { window: custom ? '' : state.window, client: state.client, from: custom ? state.from : '', to: custom ? state.to : '' };
    }

    /** Keeps the address bar in step with the filters, so the view can be bookmarked or shared. */
    function syncUrl() {
        const params = query();
        if (params.window === '24h') params.window = '';
        try { history.replaceState(history.state, '', SW.url('admin/performance.php', params)); } catch (e) { /* ignore */ }
        const exportLink = document.getElementById('rtExport');
        if (exportLink) exportLink.href = SW.url('api/reports/response-times.php', Object.assign({ export: 'csv' }, query()));
    }

    /** Speed band of an average, using the thresholds from Settings › Monitoring. */
    function band(ms) {
        const t = data.thresholds;
        if (ms === null || ms === undefined) return null;
        return ms >= t.critical ? BANDS[3] : ms >= t.slow ? BANDS[2] : ms >= t.moderate ? BANDS[1] : BANDS[0];
    }

    function sparkline(values, cls) {
        const numbers = (values || []).filter(function (v) { return v !== null; });
        if (!values || values.length < 2 || numbers.length < 2) return '<span class="text-faint fs-12">—</span>';
        const w = 100, h = 26;
        const max = Math.max.apply(null, numbers) * 1.1 || 1;
        const pts = [];
        values.forEach(function (v, i) { if (v !== null) pts.push([(i / (values.length - 1)) * w, h - (v / max) * (h - 2)]); });
        const line = pts.map(function (p) { return p[0].toFixed(1) + ',' + p[1].toFixed(1); }).join(' ');
        const area = 'M' + pts[0][0].toFixed(1) + ',' + h + ' L' + line.replace(/ /g, ' L') + ' L' + pts[pts.length - 1][0].toFixed(1) + ',' + h + ' Z';
        return '<svg class="' + (cls || '') + '" viewBox="0 0 100 26" preserveAspectRatio="none" role="img" aria-label="Trend, peak ' + SW.escape(SW.fmt.ms(Math.max.apply(null, numbers))) + '">' +
            '<path class="ov-spark-area" d="' + area + '"/><polyline class="ov-spark-line" points="' + line + '"/></svg>';
    }

    // ------------------------------------------------------------------
    // Summary tiles
    // ------------------------------------------------------------------
    function siteLink(site) {
        const row = data.rows.find(function (r) { return r.name === site.name; });
        if (!row) return SW.escape(site.name);
        return '<a href="' + SW.escape(row.urls.details) + '">' + SW.favicon(row.favicon_url, row.domain) + '<span>' + SW.escape(row.name) + '</span></a>';
    }

    function renderSummary() {
        const s = data.summary;
        const set = function (k, v) { const el = document.querySelector('[data-sum="' + k + '"]'); if (el) el.innerHTML = v; };
        const el = function (k) { return document.querySelector('[data-sum="' + k + '"]'); };

        set('avg', SW.escape(SW.fmt.ms(s.avg)));
        const avgBand = band(s.avg);
        el('avg').className = 'v' + (avgBand && avgBand[2] !== 'success' && avgBand[2] !== 'info' ? ' tone-' + avgBand[2] : '');
        set('fleet', sparkline(data.fleet));
        set('window_label', SW.fmt.num(s.websites) + (s.websites === 1 ? ' website' : ' websites') + ' · ' + SW.escape(data.window_label));

        set('fastest', s.fastest ? SW.escape(SW.fmt.ms(s.fastest.avg)) : '—');
        set('fastest_site', s.fastest ? siteLink(s.fastest) : '<span class="text-faint">No data yet</span>');
        set('fastest_note', s.fastest && s.avg ? Math.max(1, Math.round((1 - s.fastest.avg / s.avg) * 100)) + '% faster than average' : '&nbsp;');

        set('slowest', s.slowest ? SW.escape(SW.fmt.ms(s.slowest.avg)) : '—');
        const slowBand = s.slowest ? band(s.slowest.avg) : null;
        el('slowest').className = 'v' + (slowBand && (slowBand[2] === 'warning' || slowBand[2] === 'danger') ? ' tone-' + slowBand[2] : '');
        set('slowest_site', s.slowest ? siteLink(s.slowest) : '<span class="text-faint">No data yet</span>');
        set('slowest_note', s.slowest && s.avg ? (s.slowest.avg / s.avg).toFixed(1) + '× the average' : '&nbsp;');

        const sp = data.spread;
        const measured = sp.fast + sp.moderate + sp.slow + sp.critical;
        const problems = sp.slow + sp.critical;
        set('over', problems ? problems + ' <small>slow</small>' : 'All fine');
        el('over').className = 'v' + (sp.critical ? ' tone-danger' : sp.slow ? ' tone-warning' : ' tone-success');
        set('spread', measured ? BANDS.map(function (b) {
            const n = sp[b[0]];
            return n ? '<span class="tone-' + b[2] + '" style="flex:' + n + '" title="' + SW.escape(b[1] + ': ' + n) + '"></span>' : '';
        }).join('') : '<span class="none"></span>');
        set('spread_note', BANDS.filter(function (b) { return sp[b[0]]; }).map(function (b) {
            return '<span class="rt-dot tone-' + b[2] + '"></span>' + sp[b[0]] + ' ' + b[1].toLowerCase();
        }).join(' ') + (sp.none ? ' · ' + sp.none + ' no data' : '') || 'No data yet');

        const label = document.getElementById('rtRangeLabel');
        if (label) label.textContent = data.range ? SW.fmt.date(data.range.from + ' 12:00:00') + ' – ' + SW.fmt.date(data.range.to + ' 12:00:00') : data.window_label;
        const print = document.getElementById('rtPrintRange');
        if (print) print.textContent = data.window_label + (state.client ? ' · ' + state.client : ' · All clients');
    }

    // ------------------------------------------------------------------
    // Ranked chart: average (dot) inside the fastest–slowest range (bar), threshold lines behind
    // ------------------------------------------------------------------
    function renderRank() {
        const box = document.getElementById('rtRank');
        const t = data.thresholds;
        const ranked = data.rows.filter(function (r) { return r.avg !== null && r.avg > 0 && r.status !== 'PAUSED'; });
        const list = showAll ? ranked : ranked.slice(0, RANK_SHORT);
        const sub = document.getElementById('rtChartSub');
        if (sub) sub.textContent = (ranked.length ? (showAll ? 'All ' + ranked.length : 'Top ' + list.length) + ' by average · ' : '') + data.window_label;

        document.getElementById('rtLegend').innerHTML =
            '<span><i class="rt-dot tone-neutral"></i>Average</span><span><i class="rt-range-key"></i>Fastest to slowest</span>' +
            '<span><i class="rt-line-key tone-warning"></i>Slow from ' + SW.escape(SW.fmt.ms(t.slow)) + '</span>';

        if (!list.length) {
            box.innerHTML = SW.emptyState('bi-bar-chart', 'No checks recorded in the ' + data.window_label, 'Pick a longer window, or check that the monitoring cron job is running.');
            document.getElementById('rtRankFoot').hidden = true;
            return;
        }

        // Scale: room for the slowest check, and always the slow threshold.
        const top = Math.max.apply(null, list.map(function (r) { return r.max || r.avg; }).concat([t.slow * 1.08]));
        const pct = function (ms) { return Math.min(100, Math.max(0, ms / top * 100)); };
        const ticks = [];
        const step = top > 20000 ? 5000 : top > 8000 ? 2000 : 1000;
        for (let v = 0; v <= top; v += step) ticks.push(v);

        let html = '<div class="rt-rank-grid" role="list">' +
            '<div class="rt-axis" aria-hidden="true"><span></span><div class="rt-scale">' +
            ticks.map(function (v) { return '<span style="left:' + pct(v) + '%">' + SW.escape(SW.fmt.ms(v)) + '</span>'; }).join('') + '</div><span></span></div>';
        list.forEach(function (r, i) {
            const b = band(r.avg);
            const lo = r.min !== null ? r.min : r.avg, hi = r.max !== null ? r.max : r.avg;
            const tip = r.name + ' · average ' + SW.fmt.ms(r.avg) + ' · fastest ' + SW.fmt.ms(r.min) + ' · slowest ' + SW.fmt.ms(r.max) + ' · ' + SW.fmt.num(r.checks) + ' checks';
            html += '<a class="rt-row" role="listitem" href="' + SW.escape(r.urls.details) + '" title="' + SW.escape(tip) + '">' +
                '<span class="rt-name"><span class="rt-n">' + (i + 1) + '</span>' + SW.favicon(r.favicon_url, r.domain) + '<span class="truncate">' + SW.escape(r.name) + '</span></span>' +
                '<span class="rt-track">' +
                '<i class="rt-th tone-info" style="left:' + pct(t.moderate) + '%"></i><i class="rt-th tone-warning" style="left:' + pct(t.slow) + '%"></i>' +
                (t.critical <= top ? '<i class="rt-th tone-danger" style="left:' + pct(t.critical) + '%"></i>' : '') +
                '<span class="rt-span tone-' + b[2] + '" style="left:' + pct(lo) + '%;width:' + Math.max(0.6, pct(hi) - pct(lo)) + '%"></span>' +
                '<span class="rt-avg tone-' + b[2] + '" style="left:' + pct(r.avg) + '%"></span></span>' +
                '<span class="rt-val"><b class="tone-' + b[2] + '">' + SW.escape(SW.fmt.ms(r.avg)) + '</b><small>' + SW.escape(b[1]) + '</small></span></a>';
        });
        box.innerHTML = html + '</div>';

        const foot = document.getElementById('rtRankFoot');
        foot.hidden = ranked.length <= RANK_SHORT;
        document.getElementById('rtRankMore').textContent = showAll ? 'Show the slowest ' + RANK_SHORT : 'Show all ' + ranked.length + ' websites';
    }

    // ------------------------------------------------------------------
    // Table
    // ------------------------------------------------------------------
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
                '<td class="hide-mobile rt-trend">' + sparkline(r.trend) + '</td></tr>';
        }).join('');
    }

    function render() {
        renderSummary();
        renderRank();
        renderTable();
    }

    async function load(silent) {
        const seq = ++sequence;
        const main = document.getElementById('rtRank');
        if (!silent && main) main.classList.add('is-refreshing');
        try {
            const res = await SW.api('api/reports/response-times.php', { query: query() });
            if (seq !== sequence) return;
            data = res.data;
            render();
        } finally {
            if (main && seq === sequence) main.classList.remove('is-refreshing');
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
        document.getElementById('rtRankMore').addEventListener('click', function () {
            showAll = !showAll;
            if (data) renderRank();
        });

        SW.poll(function () {
            if (state.from || state.to || body.contains(document.activeElement)) return Promise.resolve();
            return load(true);
        }, Math.max(60000, (SW.config.refresh || 30) * 2000));
    });
})();
