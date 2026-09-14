/* SiteWatch — incidents page */
(function () {
    'use strict';

    const state = { q: '', website_id: SW.page.preset ? SW.page.preset.website_id || '' : '', client: '', type: '', status: SW.page.preset ? SW.page.preset.status || '' : '', from: '', to: '', page: 1, perPage: 20 };
    let abort = null;

    function fillSelects() {
        const ws = document.getElementById('incidentWebsite');
        (SW.page.websites || []).forEach(function (w) { const o = document.createElement('option'); o.value = w.id; o.textContent = w.name + ' (' + w.domain + ')'; ws.appendChild(o); });
        ws.value = state.website_id || '';
        const cs = document.getElementById('incidentClient');
        (SW.page.clients || []).forEach(function (c) { const o = document.createElement('option'); o.value = c; o.textContent = c; cs.appendChild(o); });
        const ts = document.getElementById('incidentType');
        (SW.page.types || []).forEach(function (t) { const o = document.createElement('option'); o.value = t.key; o.textContent = t.label; ts.appendChild(o); });
        SW.qsa('#incidentStatus button').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-status') === state.status); });
    }

    function row(i) {
        return '<div class="incident-row ' + (i.is_open ? 'open' : '') + '"><span class="marker"></span><div class="body">' +
            '<div class="title">' + SW.badge(i.type, i.type_label, i.is_open ? 'down' : 'ok', 'no-dot') +
            '<a href="' + SW.escape(i.urls.website) + '" class="text-reset">' + SW.escape(i.website_name) + '</a>' +
            (i.client_name ? '<span class="text-muted fw-normal">· ' + SW.escape(i.client_name) + '</span>' : '') +
            (i.is_open ? '<span class="sw-pill tone-danger">Open</span>' : '<span class="sw-pill tone-success">Resolved</span>') + '</div>' +
            '<div class="meta">' + SW.escape(i.title) + (i.error_message ? ' — ' + SW.escape(i.error_message) : '') + '</div>' +
            '<div class="incident-facts">' +
            '<div class="f"><div class="l">Started</div><div class="v">' + SW.escape(i.started_label) + '</div></div>' +
            '<div class="f"><div class="l">Resolved</div><div class="v">' + SW.escape(i.resolved_label) + '</div></div>' +
            '<div class="f"><div class="l">Duration</div><div class="v">' + SW.escape(i.duration_label) + (i.is_open ? ' <span class="text-muted fw-normal">(ongoing)</span>' : '') + '</div></div>' +
            '<div class="f"><div class="l">HTTP</div><div class="v">' + (i.http_status ? SW.httpCode(i.http_status) : '<span class="text-faint">—</span>') + '</div></div>' +
            '<div class="f"><div class="l">Reason</div><div class="v">' + SW.escape(i.title) + '</div></div>' +
            (i.notified_at ? '<div class="f"><div class="l">Alert</div><div class="v"><i class="bi bi-bell text-success"></i> sent</div></div>' : '') +
            '</div></div></div>';
    }

    async function load() {
        const list = document.getElementById('incidentsList');
        list.classList.add('is-refreshing');
        if (abort) abort.abort();
        abort = new AbortController();
        try {
            const res = await SW.api('api/incidents/list.php', { query: { q: state.q, website_id: state.website_id, client: state.client, type: state.type, status: state.status, from: state.from, to: state.to, page: state.page, per_page: state.perPage }, signal: abort.signal });
            const d = res.data;
            document.getElementById('incidentsSummary').textContent = d.open_count + ' open · ' + SW.fmt.num(d.total) + ' matching incident' + (d.total === 1 ? '' : 's');
            if (!d.rows.length) {
                const filtered = state.q || state.website_id || state.client || state.type || state.status || state.from || state.to;
                list.innerHTML = filtered ? SW.emptyState('bi-funnel', 'No incidents match these filters.', 'Try widening the date range or clearing filters.') : SW.emptyState('bi-shield-check', 'Everything looks healthy.', 'No incidents detected.');
            } else {
                list.innerHTML = d.rows.map(row).join('');
            }
            SW.pagination(document.getElementById('incidentsPagination'), d.page, d.per_page, d.total, function (p) { state.page = p; load(); });
            const exp = document.getElementById('incidentsExport');
            exp.href = SW.url('api/incidents/export.php', { q: state.q, website_id: state.website_id, client: state.client, type: state.type, status: state.status, from: state.from, to: state.to });
            SW.updateIncidentCount(d.open_count);
        } catch (e) {
            if (e && e.name === 'AbortError') return;
            list.innerHTML = SW.emptyState('bi-wifi-off', 'Unable to load incidents', e.message);
        } finally { list.classList.remove('is-refreshing'); }
    }

    document.addEventListener('sw:ready', function () {
        if (!document.getElementById('incidentsPage')) return;
        fillSelects();
        const bind = function (id, key, event) { const el = document.getElementById(id); el.addEventListener(event || 'change', function () { state[key] = el.value; state.page = 1; load(); }); };
        document.getElementById('incidentSearch').addEventListener('input', SW.debounce(function () { state.q = this.value.trim(); state.page = 1; load(); }, 300));
        bind('incidentWebsite', 'website_id'); bind('incidentClient', 'client'); bind('incidentType', 'type'); bind('incidentFrom', 'from'); bind('incidentTo', 'to');
        SW.qsa('#incidentStatus button').forEach(function (b) { b.addEventListener('click', function () { state.status = b.getAttribute('data-status'); state.page = 1; SW.qsa('#incidentStatus button').forEach(function (x) { x.classList.toggle('active', x === b); }); load(); }); });
        document.getElementById('incidentClear').addEventListener('click', function () {
            state.q = ''; state.website_id = ''; state.client = ''; state.type = ''; state.status = ''; state.from = ''; state.to = ''; state.page = 1;
            ['incidentSearch', 'incidentWebsite', 'incidentClient', 'incidentType', 'incidentFrom', 'incidentTo'].forEach(function (id) { document.getElementById(id).value = ''; });
            SW.qsa('#incidentStatus button').forEach(function (x) { x.classList.toggle('active', x.getAttribute('data-status') === ''); });
            load();
        });
        load();
        SW.poll(function () { return load(); }, Math.max(20000, (SW.config.refresh || 30) * 1000));
    });
})();
