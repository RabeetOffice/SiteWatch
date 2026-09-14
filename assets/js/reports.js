/* SiteWatch — uptime & performance reports */
(function () {
    'use strict';

    const mode = SW.page.mode || 'uptime';
    const state = { website_id: SW.page.preset ? SW.page.preset.website_id || '' : '', client: '', from: '', to: '' };

    function localDate(offsetDays) {
        const d = new Date();
        d.setDate(d.getDate() + offsetDays);
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    function sslPill(ssl) {
        if (!ssl.applicable) return '<span class="text-faint fs-13">' + SW.escape(ssl.label) + '</span>';
        return '<span class="sw-pill tone-' + ssl.tone + '"><i class="bi ' + (ssl.tone === 'success' ? 'bi-shield-check' : 'bi-shield-exclamation') + '"></i>' + SW.escape(ssl.label) + '</span>';
    }

    function row(r) {
        const site = '<td><div class="site-cell">' + SW.favicon(r.favicon_url, r.domain) + '<div class="min-w-0"><a class="site-name" href="' + SW.url('admin/website-details.php', { id: r.id }) + '">' + SW.escape(r.name) + '</a><span class="site-domain">' + SW.escape(r.domain) + '</span></div></div></td>' +
            '<td class="hide-mobile fs-13">' + (r.client_name ? SW.escape(r.client_name) : '<span class="text-faint">—</span>') + '</td>' +
            '<td>' + SW.badge(r.status, r.status_label, r.severity) + '</td>';
        if (mode === 'performance') {
            return '<tr>' + site + '<td>' + SW.responseTime(r.avg_response) + '</td><td>' + SW.responseTime(r.min_response) + '</td><td>' + SW.responseTime(r.max_response) + '</td>' +
                '<td class="hide-mobile fs-13">' + SW.fmt.num(r.checks) + '</td><td class="hide-mobile fw-500">' + SW.escape(r.uptime_label) + '</td><td>' + sslPill(r.ssl) + '</td></tr>';
        }
        const uptimeClass = r.uptime === null ? 'text-faint' : (r.uptime >= 99.9 ? 'text-success' : (r.uptime >= 99 ? '' : 'text-danger'));
        return '<tr>' + site + '<td><span class="fw-600 ' + uptimeClass + '">' + SW.escape(r.uptime_label) + '</span>' + (r.checks ? '<div class="fs-12 text-muted">' + SW.fmt.num(r.checks) + ' checks</div>' : '<div class="fs-12 text-muted">no data</div>') + '</td>' +
            '<td class="fs-13">' + (r.downtime_seconds ? '<span class="text-danger fw-500">' + SW.escape(r.downtime_label) + '</span>' : '<span class="text-faint">none</span>') + '</td>' +
            '<td>' + (r.incidents ? '<span class="sw-pill tone-danger">' + r.incidents + '</span>' : '<span class="text-faint">0</span>') + '</td>' +
            '<td>' + SW.responseTime(r.avg_response) + '</td><td class="hide-mobile">' + SW.responseTime(r.max_response) + '</td><td>' + sslPill(r.ssl) + '</td></tr>';
    }

    async function load() {
        const body = document.getElementById('reportBody');
        body.innerHTML = SW.skeletonRows(9, 6);
        try {
            const res = await SW.api('api/reports/uptime.php', { query: state });
            const d = res.data;
            document.getElementById('reportRange').textContent = d.range.from + ' → ' + d.range.to + ' · ' + d.range.days + ' day' + (d.range.days === 1 ? '' : 's') + ' (application timezone)';
            if (!d.rows.length) { body.innerHTML = '<tr><td colspan="9">' + SW.emptyState('bi-bar-chart-line', 'No websites match these filters.') + '</td></tr>'; }
            else { body.innerHTML = d.rows.map(row).join(''); }
            Object.keys(d.summary).forEach(function (k) { SW.qsa('[data-sum="' + k + '"]').forEach(function (el) { el.textContent = d.summary[k] === null || d.summary[k] === undefined ? '—' : (typeof d.summary[k] === 'number' ? SW.fmt.num(d.summary[k]) : d.summary[k]); }); });
            document.getElementById('reportExport').href = SW.url('api/reports/export.php', Object.assign({ mode: mode }, state));
            document.getElementById('reportFrom').value = d.range.from;
            document.getElementById('reportTo').value = d.range.to;
        } catch (e) { body.innerHTML = '<tr><td colspan="9">' + SW.emptyState('bi-wifi-off', 'Unable to load report', e.message) + '</td></tr>'; }
    }

    document.addEventListener('sw:ready', function () {
        if (!document.getElementById('reportBody')) return;
        const ws = document.getElementById('reportWebsite');
        (SW.page.websites || []).forEach(function (w) { const o = document.createElement('option'); o.value = w.id; o.textContent = w.name + ' (' + w.domain + ')'; ws.appendChild(o); });
        ws.value = state.website_id || '';
        const cs = document.getElementById('reportClient');
        (SW.page.clients || []).forEach(function (c) { const o = document.createElement('option'); o.value = c; o.textContent = c; cs.appendChild(o); });
        state.from = localDate(-29); state.to = localDate(0);
        ws.addEventListener('change', function () { state.website_id = ws.value; load(); });
        cs.addEventListener('change', function () { state.client = cs.value; load(); });
        document.getElementById('reportFrom').addEventListener('change', function () { state.from = this.value; SW.qsa('#reportPresets button').forEach(function (b) { b.classList.remove('active'); }); load(); });
        document.getElementById('reportTo').addEventListener('change', function () { state.to = this.value; SW.qsa('#reportPresets button').forEach(function (b) { b.classList.remove('active'); }); load(); });
        SW.qsa('#reportPresets button').forEach(function (b) { b.addEventListener('click', function () { const days = parseInt(b.getAttribute('data-days'), 10); state.from = localDate(-(days - 1)); state.to = localDate(0); SW.qsa('#reportPresets button').forEach(function (x) { x.classList.toggle('active', x === b); }); load(); }); });
        load();
    });
})();
