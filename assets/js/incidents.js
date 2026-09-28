/* SiteWatch — incidents page */
(function () {
    'use strict';

    const COLUMNS = 7;
    const state = {
        q: '',
        website_id: SW.page.preset ? SW.page.preset.website_id || '' : '',
        client: '',
        type: '',
        status: SW.page.preset ? SW.page.preset.status || '' : '',
        from: '',
        to: '',
        page: 1,
        perPage: 20,
    };
    let abort = null;

    function fillSelects() {
        const ws = document.getElementById('incidentWebsite');
        (SW.page.websites || []).forEach(function (w) {
            const o = document.createElement('option');
            o.value = w.id;
            o.textContent = w.name + ' (' + w.domain + ')';
            ws.appendChild(o);
        });
        ws.value = state.website_id || '';
        const cs = document.getElementById('incidentClient');
        (SW.page.clients || []).forEach(function (c) {
            const o = document.createElement('option');
            o.value = c;
            o.textContent = c;
            cs.appendChild(o);
        });
        const ts = document.getElementById('incidentType');
        (SW.page.types || []).forEach(function (t) {
            const o = document.createElement('option');
            o.value = t.key;
            o.textContent = t.label;
            ts.appendChild(o);
        });
        SW.qsa('#incidentStatus button').forEach(function (b) {
            b.classList.toggle('active', b.getAttribute('data-status') === state.status);
            b.setAttribute('aria-pressed', String(b.getAttribute('data-status') === state.status));
        });
    }

    let rows = [];

    /** Everything about one incident, in the side panel so the list keeps its place. */
    function openPanel(i) {
        const facts = [];
        facts.push(['State', i.is_open ? '<span class="sw-pill tone-danger"><i class="bi bi-exclamation-circle" aria-hidden="true"></i>Open</span>'
            : '<span class="sw-pill tone-success"><i class="bi bi-check-circle" aria-hidden="true"></i>Resolved</span>']);
        facts.push(['Type', SW.escape(i.type_label)]);
        facts.push(['Started', SW.escape(i.started_label) + ' <span class="text-muted fs-12">(' + SW.escape(SW.fmt.timeAgo(i.started_at)) + ')</span>']);
        if (i.confirmed_at) facts.push(['Confirmed', SW.escape(SW.fmt.date(i.confirmed_at, true))]);
        facts.push([i.is_open ? 'Ongoing for' : 'Duration', SW.escape(i.duration_label)]);
        if (!i.is_open) facts.push(['Resolved', SW.escape(i.resolved_label)]);
        if (i.http_status) facts.push(['HTTP status', '<span class="http-code ' + SW.fmt.httpClass(i.http_status) + '">' + SW.escape(i.http_status) + '</span>']);
        if (i.response_time !== null && i.response_time !== undefined) facts.push(['Response time', SW.responseTime(i.response_time)]);
        if (!i.is_open && i.resolved_http_status) facts.push(['Recovery HTTP', SW.escape(i.resolved_http_status)]);
        facts.push(['Alert sent', i.notified_at ? SW.escape(SW.fmt.date(i.notified_at, true)) : '<span class="text-faint">No send recorded</span>']);
        if (!i.is_open) facts.push(['Recovery alert', i.recovery_notified_at ? SW.escape(SW.fmt.date(i.recovery_notified_at, true)) : '<span class="text-faint">No send recorded</span>']);

        document.getElementById('incidentPanelSite').textContent = i.website_name + (i.client_name ? ' · ' + i.client_name : '');
        document.getElementById('incidentPanelTitle').textContent = i.title;
        document.getElementById('incidentPanelBody').innerHTML =
            (i.error_message ? '<div class="form-label">What SiteWatch saw</div><pre class="copy-box mb-3">' + SW.escape(i.error_message) + '</pre>' : '') +
            '<dl class="kv-list mb-3">' + facts.map(function (f) { return '<dt>' + f[0] + '</dt><dd>' + f[1] + '</dd>'; }).join('') + '</dl>' +
            '<div class="divider-top d-flex gap-2 flex-wrap">' +
            '<a class="btn btn-sm btn-primary" href="' + SW.escape(i.urls.website) + '"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Open website</a>' +
            '<a class="btn btn-sm btn-light" href="' + SW.url('admin/incidents.php', { website_id: i.website_id }) + '">All incidents of this website</a></div>';
        SW.panel.open('incidentPanel');
    }

    function row(i, index) {
        return '<tr class="is-clickable" data-index="' + index + '">' +
            '<td>' + SW.siteCell({ id: i.website_id, name: i.website_name, domain: i.domain, client_name: i.client_name, favicon_url: i.favicon_url }, { href: i.urls.website }) + '</td>' +
            '<td><div class="min-w-0">' + SW.badge(i.type, i.type_label, i.is_open ? 'down' : 'neutral', 'no-dot') +
            '<div class="fs-13 mt-1 break-anywhere">' + SW.escape(i.title) + '</div></div></td>' +
            '<td>' + (i.is_open ? '<span class="sw-pill tone-danger"><i class="bi bi-exclamation-circle" aria-hidden="true"></i>Open</span>'
                : '<span class="sw-pill tone-success"><i class="bi bi-check-circle" aria-hidden="true"></i>Resolved</span>') + '</td>' +
            '<td class="fs-13 nowrap">' + SW.escape(i.started_label) + '<div class="fs-12 text-muted">' + SW.escape(SW.fmt.timeAgo(i.started_at)) + '</div></td>' +
            '<td class="num fs-13 nowrap">' + SW.escape(i.duration_label) + (i.is_open ? '<div class="fs-12 text-muted">ongoing</div>' : '') + '</td>' +
            '<td class="hide-mobile fs-13">' + (i.notified_at
                ? '<span class="text-muted"><i class="bi bi-bell" aria-hidden="true"></i> Sent</span>'
                : '<span class="text-faint">Not recorded</span>') + '</td>' +
            '<td class="actions"><button type="button" class="btn btn-sm btn-light" data-open="' + index + '">Details</button></td>' +
            '</tr>';
    }

    async function load(silent) {
        const list = document.getElementById('incidentsList');
        // A background refresh must never move the list while someone is using it.
        if (silent && (list.contains(document.activeElement) || SW.panel.current === 'incidentPanel')) return;
        if (!silent) list.classList.add('is-refreshing');
        if (abort) abort.abort();
        abort = new AbortController();
        try {
            const res = await SW.api('api/incidents/list.php', {
                query: {
                    q: state.q, website_id: state.website_id, client: state.client, type: state.type,
                    status: state.status, from: state.from, to: state.to, page: state.page, per_page: state.perPage,
                },
                signal: abort.signal,
            });
            const d = res.data;
            document.getElementById('incidentsSummary').textContent =
                d.open_count + ' open · ' + SW.fmt.num(d.total) + ' matching incident' + (d.total === 1 ? '' : 's');
            if (!d.rows.length) {
                const filtered = state.q || state.website_id || state.client || state.type || state.status || state.from || state.to;
                list.innerHTML = '<tr><td colspan="' + COLUMNS + '">' + (filtered
                    ? SW.emptyState('bi-funnel', 'No incidents match these filters.', 'Try widening the date range or clearing filters.')
                    : SW.emptyState('bi-shield-check', 'No incidents recorded.', 'Confirmed outages and errors will be listed here.')) + '</td></tr>';
            } else {
                rows = d.rows;
                list.innerHTML = d.rows.map(row).join('');
            }
            SW.pagination(document.getElementById('incidentsPagination'), d.page, d.per_page, d.total, function (p) { state.page = p; load(); });
            const exp = document.getElementById('incidentsExport');
            exp.href = SW.url('api/incidents/export.php', {
                q: state.q, website_id: state.website_id, client: state.client, type: state.type,
                status: state.status, from: state.from, to: state.to,
            });
            SW.updateIncidentCount(d.open_count);
        } catch (e) {
            if (e && e.name === 'AbortError') return;
            list.innerHTML = '<tr><td colspan="' + COLUMNS + '">' + SW.emptyState('bi-wifi-off', 'Unable to load incidents', e.message) + '</td></tr>';
        } finally {
            list.classList.remove('is-refreshing');
        }
    }

    document.addEventListener('sw:ready', function () {
        if (!document.getElementById('incidentsPage')) return;
        document.getElementById('incidentsList').innerHTML = SW.skeletonRows(COLUMNS, 6);
        fillSelects();

        const bind = function (id, key, event) {
            const el = document.getElementById(id);
            el.addEventListener(event || 'change', function () { state[key] = el.value; state.page = 1; load(); });
        };
        document.getElementById('incidentSearch').addEventListener('input', SW.debounce(function () {
            state.q = this.value.trim();
            state.page = 1;
            load();
        }, 300));
        bind('incidentWebsite', 'website_id');
        bind('incidentClient', 'client');
        bind('incidentType', 'type');
        bind('incidentFrom', 'from');
        bind('incidentTo', 'to');

        SW.qsa('#incidentStatus button').forEach(function (b) {
            b.addEventListener('click', function () {
                state.status = b.getAttribute('data-status');
                state.page = 1;
                SW.qsa('#incidentStatus button').forEach(function (x) {
                    x.classList.toggle('active', x === b);
                    x.setAttribute('aria-pressed', String(x === b));
                });
                load();
            });
        });

        document.getElementById('incidentClear').addEventListener('click', function () {
            state.q = ''; state.website_id = ''; state.client = ''; state.type = '';
            state.status = ''; state.from = ''; state.to = ''; state.page = 1;
            ['incidentSearch', 'incidentWebsite', 'incidentClient', 'incidentType', 'incidentFrom', 'incidentTo'].forEach(function (id) {
                document.getElementById(id).value = '';
            });
            SW.qsa('#incidentStatus button').forEach(function (x) {
                x.classList.toggle('active', x.getAttribute('data-status') === '');
                x.setAttribute('aria-pressed', String(x.getAttribute('data-status') === ''));
            });
            load();
        });

        // A click anywhere on a row (except its links) opens the details.
        document.getElementById('incidentsList').addEventListener('click', function (ev) {
            if (ev.target.closest('a')) return;
            const tr = ev.target.closest('tr[data-index]');
            if (tr && rows[+tr.getAttribute('data-index')]) openPanel(rows[+tr.getAttribute('data-index')]);
        });

        load();
        SW.poll(function () { return load(true); }, Math.max(20000, (SW.config.refresh || 30) * 1000));
    });
})();
