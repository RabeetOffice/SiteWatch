/* SiteWatch — incidents page */
(function () {
    'use strict';

    const COLUMNS = 6;
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

    /**
     * Secondary information (verbose errors, HTTP details, resolution) lives in
     * an expandable panel so every row keeps the same shape.
     */
    function details(i) {
        const facts = [];
        if (i.http_status) facts.push(['HTTP status', SW.escape(i.http_status)]);
        if (i.response_time !== null && i.response_time !== undefined) facts.push(['Response time', SW.escape(SW.fmt.ms(i.response_time))]);
        if (!i.is_open) facts.push(['Resolved', SW.escape(i.resolved_label)]);
        if (!i.is_open && i.resolved_http_status) facts.push(['Recovery HTTP', SW.escape(i.resolved_http_status)]);
        facts.push(['Alert sent', i.notified_at ? SW.escape(SW.fmt.date(i.notified_at, true)) : 'No send recorded']);
        if (!i.is_open) facts.push(['Recovery alert', i.recovery_notified_at ? SW.escape(SW.fmt.date(i.recovery_notified_at, true)) : 'No send recorded']);

        const hasError = !!i.error_message;
        if (!hasError && facts.length === 0) return '';
        return '<details class="detail-toggle"><summary>Details</summary><div class="detail-body">' +
            (hasError ? '<p class="mb-2 break-anywhere"><b>Error:</b> ' + SW.escape(i.error_message) + '</p>' : '') +
            '<dl class="kv-list fs-12 mb-0">' + facts.map(function (f) {
                return '<dt>' + f[0] + '</dt><dd>' + f[1] + '</dd>';
            }).join('') + '</dl></div></details>';
    }

    function row(i) {
        return '<tr>' +
            '<td><div class="site-text"><a class="site-name" href="' + SW.escape(i.urls.website) + '" title="' + SW.escape(i.website_name) + '">' + SW.escape(i.website_name) + '</a>' +
            '<span class="site-domain" title="' + SW.escape(i.client_name || i.domain) + '">' + SW.escape(i.client_name || i.domain) + '</span></div></td>' +
            '<td><div class="min-w-0">' + SW.badge(i.type, i.type_label, i.is_open ? 'down' : 'neutral', 'no-dot') +
            '<div class="fs-13 mt-1 break-anywhere">' + SW.escape(i.title) + '</div>' + details(i) + '</div></td>' +
            '<td>' + (i.is_open ? '<span class="sw-pill tone-danger"><i class="bi bi-exclamation-circle" aria-hidden="true"></i>Open</span>'
                : '<span class="sw-pill tone-success"><i class="bi bi-check-circle" aria-hidden="true"></i>Resolved</span>') + '</td>' +
            '<td class="fs-13 nowrap">' + SW.escape(i.started_label) + '<div class="fs-12 text-muted">' + SW.escape(SW.fmt.timeAgo(i.started_at)) + '</div></td>' +
            '<td class="num fs-13 nowrap">' + SW.escape(i.duration_label) + (i.is_open ? '<div class="fs-12 text-muted">ongoing</div>' : '') + '</td>' +
            '<td class="hide-mobile fs-13">' + (i.notified_at
                ? '<span class="text-muted"><i class="bi bi-bell" aria-hidden="true"></i> Sent</span>'
                : '<span class="text-faint">Not recorded</span>') + '</td>' +
            '</tr>';
    }

    async function load(silent) {
        const list = document.getElementById('incidentsList');
        // A background refresh must never close an expanded row or steal focus.
        if (silent && (list.contains(document.activeElement) || list.querySelector('details[open]'))) return;
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

        load();
        SW.poll(function () { return load(true); }, Math.max(20000, (SW.config.refresh || 30) * 1000));
    });
})();
