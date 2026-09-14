/* SiteWatch — uptime & performance reports */
(function () {
    'use strict';

    const COLUMNS = 9;
    const mode = SW.page.mode || 'uptime';
    const state = { website_id: SW.page.preset ? SW.page.preset.website_id || '' : '', client: '', from: '', to: '' };
    let requestSequence = 0;

    function localDate(offsetDays) {
        const parts = new Intl.DateTimeFormat('en-US', { timeZone: SW.config.timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date());
        const part = function (type) { return parts.find(function (p) { return p.type === type; }).value; };
        const d = new Date(Date.UTC(Number(part('year')), Number(part('month')) - 1, Number(part('day')) + offsetDays));
        return d.toISOString().slice(0, 10);
    }

    /** Routine, valid certificates stay quiet; only exceptions are highlighted. */
    function sslCell(ssl) {
        if (!ssl.applicable) return '<span class="text-faint fs-13">' + SW.escape(ssl.label) + '</span>';
        if (ssl.tone === 'success') return '<span class="fs-13 text-muted"><i class="bi bi-shield-check" aria-hidden="true"></i> ' + SW.escape(ssl.label) + '</span>';
        return '<span class="sw-pill tone-' + SW.escape(ssl.tone) + '"><i class="bi bi-shield-exclamation" aria-hidden="true"></i>' + SW.escape(ssl.label) + '</span>';
    }

    function row(r) {
        const site = '<td><div class="site-cell">' + SW.favicon(r.favicon_url, r.domain) +
            '<div class="site-text"><a class="site-name" href="' + SW.url('admin/website-details.php', { id: r.id }) + '" title="' + SW.escape(r.name) + '">' + SW.escape(r.name) + '</a>' +
            '<span class="site-domain" title="' + SW.escape(r.domain) + '">' + SW.escape(r.domain) + '</span></div></div></td>' +
            '<td class="hide-mobile">' + (r.client_name ? '<span class="client-name" title="' + SW.escape(r.client_name) + '">' + SW.escape(r.client_name) + '</span>' : '<span class="text-faint">—</span>') + '</td>' +
            '<td>' + SW.badge(r.status, r.status_label, r.severity) + '</td>';

        if (mode === 'performance') {
            return '<tr>' + site +
                '<td class="num">' + SW.responseTime(r.avg_response) + '</td>' +
                '<td class="num">' + SW.responseTime(r.min_response) + '</td>' +
                '<td class="num">' + SW.responseTime(r.max_response) + '</td>' +
                '<td class="num hide-mobile fs-13">' + SW.fmt.num(r.checks) + '</td>' +
                '<td class="num hide-mobile fw-600">' + SW.escape(r.uptime_label) + '</td>' +
                '<td>' + sslCell(r.ssl) + '</td></tr>';
        }

        const uptimeClass = r.uptime === null ? 'text-faint' : (r.uptime >= 99.9 ? '' : (r.uptime >= 99 ? '' : 'text-danger'));
        return '<tr>' + site +
            '<td class="num"><span class="fw-600 ' + uptimeClass + '">' + SW.escape(r.uptime_label) + '</span>' +
            (r.checks ? '<div class="fs-12 text-muted">' + SW.fmt.num(r.checks) + ' checks</div>' : '<div class="fs-12 text-muted">no checks recorded</div>') + '</td>' +
            '<td class="num fs-13">' + (r.downtime_seconds ? '<span class="text-danger fw-600">' + SW.escape(r.downtime_label) + '</span>' : '<span class="text-faint">none</span>') + '</td>' +
            '<td class="num">' + (r.incidents ? '<span class="sw-pill tone-danger">' + r.incidents + '</span>' : '<span class="text-faint">0</span>') + '</td>' +
            '<td class="num">' + SW.responseTime(r.avg_response) + '</td>' +
            '<td class="num hide-mobile">' + SW.responseTime(r.max_response) + '</td>' +
            '<td>' + sslCell(r.ssl) + '</td></tr>';
    }

    function setBusy(busy) {
        const exportLink = document.getElementById('reportExport');
        const print = document.getElementById('printReport');
        if (exportLink) {
            exportLink.classList.toggle('disabled', busy);
            if (busy) { exportLink.setAttribute('aria-disabled', 'true'); } else { exportLink.removeAttribute('aria-disabled'); }
        }
        if (print) print.disabled = busy;
    }

    async function load() {
        const requestId = ++requestSequence;
        const body = document.getElementById('reportBody');
        setBusy(true);
        body.innerHTML = SW.skeletonRows(COLUMNS, 6);
        try {
            const res = await SW.api('api/reports/uptime.php', { query: state });
            const d = res.data;
            if (requestId !== requestSequence) return;
            state.from = d.range.from;
            state.to = d.range.to;

            const coverage = SW.fmt.num(d.summary.checks || 0) + ' checks recorded across ' + SW.fmt.num(d.summary.websites || 0) +
                (d.summary.websites === 1 ? ' website' : ' websites') + ' in this ' + d.range.days + '-day range. ' +
                'A check count does not establish continuous coverage: periods without recorded checks are neither uptime nor downtime. ' +
                'Downtime is calculated separately, from confirmed incidents.';
            const coverageEl = document.getElementById('reportCoverage');
            if (coverageEl) coverageEl.textContent = coverage;
            const printCoverage = document.getElementById('printCoverage');
            if (printCoverage) printCoverage.textContent = coverage;

            const scope = document.getElementById('reportWebsite');
            const scopeLabel = scope.value ? scope.options[scope.selectedIndex].text : 'All websites';
            const clientLabel = state.client || 'All clients';
            const rangeLabel = d.range.from + ' → ' + d.range.to + ' (' + d.range.days + ' day' + (d.range.days === 1 ? '' : 's') + ')';

            document.getElementById('reportRange').textContent = rangeLabel;
            document.getElementById('reportScope').textContent = scopeLabel;
            document.getElementById('reportClientLabel').textContent = clientLabel;
            document.getElementById('printRange').textContent = rangeLabel;
            document.getElementById('printScope').textContent = scopeLabel + (state.client ? ' · ' + clientLabel : '');

            if (!d.rows.length) {
                body.innerHTML = '<tr><td colspan="' + COLUMNS + '">' + SW.emptyState('bi-bar-chart-line', 'No websites match these filters.', 'Widen the date range or clear a filter.') + '</td></tr>';
            } else {
                body.innerHTML = d.rows.map(row).join('');
            }

            Object.keys(d.summary).forEach(function (k) {
                SW.qsa('[data-sum="' + k + '"]').forEach(function (el) {
                    const value = d.summary[k];
                    el.textContent = value === null || value === undefined || value === ''
                        ? (el.classList.contains('s') ? '—' : '—')
                        : (typeof value === 'number' ? SW.fmt.num(value) : value);
                });
            });

            document.getElementById('reportExport').href = SW.url('api/reports/export.php', Object.assign({ mode: mode }, state));
            document.getElementById('reportFrom').value = d.range.from;
            document.getElementById('reportTo').value = d.range.to;
            setBusy(false);
        } catch (e) {
            if (requestId !== requestSequence) return;
            body.innerHTML = '<tr><td colspan="' + COLUMNS + '">' + SW.emptyState('bi-wifi-off', 'Unable to load this report', e.message) + '</td></tr>';
            SW.qsa('[data-sum]').forEach(function (el) { el.textContent = '—'; });
            const coverageEl = document.getElementById('reportCoverage');
            if (coverageEl) coverageEl.textContent = 'The report could not be loaded. Change a filter to try again.';
            setBusy(false);
        }
    }

    document.addEventListener('sw:ready', function () {
        if (!document.getElementById('reportBody')) return;

        const ws = document.getElementById('reportWebsite');
        (SW.page.websites || []).forEach(function (w) {
            const o = document.createElement('option');
            o.value = w.id;
            o.textContent = w.name + ' (' + w.domain + ')';
            ws.appendChild(o);
        });
        ws.value = state.website_id || '';

        const cs = document.getElementById('reportClient');
        (SW.page.clients || []).forEach(function (c) {
            const o = document.createElement('option');
            o.value = c;
            o.textContent = c;
            cs.appendChild(o);
        });

        state.from = localDate(-29);
        state.to = localDate(0);

        ws.addEventListener('change', function () { state.website_id = ws.value; load(); });
        cs.addEventListener('change', function () { state.client = cs.value; load(); });
        document.getElementById('reportFrom').addEventListener('change', function () {
            state.from = this.value;
            SW.qsa('#reportPresets button').forEach(function (b) { b.classList.remove('active'); b.setAttribute('aria-pressed', 'false'); });
            load();
        });
        document.getElementById('reportTo').addEventListener('change', function () {
            state.to = this.value;
            SW.qsa('#reportPresets button').forEach(function (b) { b.classList.remove('active'); b.setAttribute('aria-pressed', 'false'); });
            load();
        });
        SW.qsa('#reportPresets button').forEach(function (b) {
            b.setAttribute('aria-pressed', String(b.classList.contains('active')));
            b.addEventListener('click', function () {
                const days = parseInt(b.getAttribute('data-days'), 10);
                state.from = localDate(-(days - 1));
                state.to = localDate(0);
                SW.qsa('#reportPresets button').forEach(function (x) {
                    x.classList.toggle('active', x === b);
                    x.setAttribute('aria-pressed', String(x === b));
                });
                load();
            });
        });

        load();
    });
})();
