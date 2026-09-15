/* SiteWatch — Domains & Hosting page */
(function () {
    'use strict';

    const esc = SW.escape;
    const FILTER_LABELS = { expiring: 'Expiring soon', expired: 'Expired', failed: 'Lookup problems', unchecked: 'Not checked yet' };
    const state = { q: '', filter: 'all', sort: 'expiry', provider: '', country: '', countryName: '', page: 1, perPage: 25 };
    let rows = [];
    let total = 0;
    let modal = null;
    let modalWebsiteId = null;
    let refreshingAll = false;

    // ------------------------------------------------------------------
    // Summary
    // ------------------------------------------------------------------
    function setMetric(key, value) {
        const el = document.querySelector('[data-metric="' + key + '"]');
        if (el) el.textContent = value;
    }
    function setTone(key, tone) {
        const el = document.querySelector('[data-metric-item="' + key + '"]');
        if (!el) return;
        el.classList.toggle('tone-warning', tone === 'warning');
        el.classList.toggle('tone-danger', tone === 'danger');
    }

    function breakdownList(items, key) {
        const top = Math.max.apply(null, items.map(function (i) { return i.count; }).concat([1]));
        return '<ul class="breakdown-list">' + items.map(function (i) {
            const val = key === 'provider' ? i.name : i.code;
            const name = key === 'provider' ? i.name : i.name + ' (' + i.code + ')';
            return '<li><button type="button" class="name" data-breakdown="' + key + '" data-value="' + esc(val) + '" data-label="' + esc(i.name) + '" title="Show these websites">' + esc(name) + '</button>' +
                '<span class="bar" aria-hidden="true"><span style="width:' + Math.round(i.count / top * 100) + '%"></span></span>' +
                '<span class="count">' + i.count + '</span></li>';
        }).join('') + '</ul>';
    }

    function renderSummary(s) {
        setMetric('total', SW.fmt.num(s.total));
        setMetric('checked_sub', s.total === 0 ? 'no websites yet' : (s.checked === s.total ? 'all checked' : s.checked + ' of ' + s.total + ' checked'));
        setMetric('expiring', SW.fmt.num(s.expiring));
        setMetric('expired', SW.fmt.num(s.expired));
        setMetric('failed', SW.fmt.num(s.failed));
        setMetric('avg_age', s.avg_age_label || '—');
        setTone('expiring', s.expiring > 0 ? 'warning' : null);
        setTone('expired', s.expired > 0 ? 'danger' : null);
        setTone('failed', s.failed > 0 ? 'warning' : null);
        SW.qsa('[data-domain-filter]').forEach(function (b) {
            b.setAttribute('aria-pressed', String(b.getAttribute('data-domain-filter') === state.filter && !state.provider && !state.country && !state.q));
        });

        const box = document.getElementById('hostingBreakdown');
        if (!s.checked) {
            box.innerHTML = SW.emptyState('bi-hdd-network', 'No hosting details yet',
                SW.page.canLookup ? 'Use “Refresh outdated” to look up every website.' : 'Details appear once websites have been checked.');
            return;
        }
        let html = '<p class="breakdown-title">Hosting providers</p>' +
            (s.providers.length ? breakdownList(s.providers, 'provider') : '<p class="text-muted fs-13">None identified yet.</p>') +
            '<p class="breakdown-title">Server countries</p>' +
            (s.countries.length ? breakdownList(s.countries, 'country') : '<p class="text-muted fs-13">None identified yet.</p>');
        if (s.behind_cdn) {
            html += '<p class="fs-12 text-muted mb-0"><i class="bi bi-info-circle" aria-hidden="true"></i> ' + s.behind_cdn +
                (s.behind_cdn === 1 ? ' website is' : ' websites are') + ' behind a CDN that hides the hosting company and server location.</p>';
        }
        box.innerHTML = html;
    }

    // ------------------------------------------------------------------
    // Table
    // ------------------------------------------------------------------
    function refreshButton(r) {
        if (!SW.page.canLookup) return '';
        return '<button type="button" class="btn-icon btn-sm bordered" data-domain-action="refresh" data-id="' + r.website_id + '" data-bs-toggle="tooltip" title="Refresh now" aria-label="Refresh details for ' + esc(r.name) + '">' +
            '<i class="bi bi-arrow-repeat" aria-hidden="true"></i></button>';
    }

    function problem(text) {
        return '<span class="text-faint fs-13" data-bs-toggle="tooltip" title="' + esc(text) + '"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Unavailable</span>';
    }

    function rowHtml(r) {
        const website = '<div class="site-cell">' + SW.favicon(r.favicon_url, r.host) +
            '<div class="site-text"><a class="site-name" href="' + esc(r.urls.details) + '" title="' + esc(r.name) + '">' + esc(r.name) + '</a>' +
            '<span class="site-domain" title="' + esc(r.domain) + '">' + esc(r.domain) + '</span></div></div>';
        const details = '<button type="button" class="btn btn-sm btn-light" data-domain-action="view" data-id="' + r.website_id + '">Details</button>';

        if (!r.checked) {
            return '<tr data-row="' + r.website_id + '"><td>' + website + '</td>' +
                '<td colspan="5"><span class="text-faint fs-13">Not checked yet</span></td>' +
                '<td class="actions"><div class="row-actions">' + details + refreshButton(r) + '</div></td></tr>';
        }

        const age = r.age_days !== null
            ? '<span class="fw-600">' + esc(r.age_label) + '</span><div class="fs-12 text-muted">since ' + esc(r.registered_label) + '</div>'
            : (r.whois_error ? problem(r.whois_error) : '<span class="text-faint">—</span>');
        const expires = r.expires_at
            ? (r.expiry_tone === 'success'
                ? '<span class="fs-13">' + esc(r.expires_label) + '</span><div class="fs-12 text-muted">' + esc(r.expiry_label) + '</div>'
                : SW.pill(r.expiry_label, r.expiry_tone, 'bi-calendar-x') + '<div class="fs-12 text-muted">' + esc(r.expires_label) + '</div>')
            : (r.whois_error ? problem(r.whois_error) : '<span class="text-faint">—</span>');
        const hosting = r.provider
            ? '<span class="fw-500">' + esc(r.provider) + '</span>'
            : (r.cdn ? '<span class="text-muted">Behind ' + esc(r.cdn) + '</span>' : (r.hosting_error ? problem(r.hosting_error) : '<span class="text-faint">Unknown</span>'));
        const hostingSub = [r.provider && r.cdn ? 'via ' + r.cdn : null, !r.cdn && r.country ? r.country : null].filter(Boolean).join(' · ');

        return '<tr data-row="' + r.website_id + '"><td>' + website + '</td>' +
            '<td class="hide-mobile">' + (r.registrar ? '<span class="client-name" title="' + esc(r.registrar) + '">' + esc(r.registrar) + '</span>' : '<span class="text-faint">—</span>') + '</td>' +
            '<td>' + age + '</td>' +
            '<td>' + expires + '</td>' +
            '<td>' + hosting + (hostingSub ? '<div class="fs-12 text-muted">' + esc(hostingSub) + '</div>' : '') + '</td>' +
            '<td class="hide-mobile nowrap fs-13">' + SW.timeAgoEl(r.checked_at) + (r.stale ? '<div class="fs-12 text-muted">due for refresh</div>' : '') + '</td>' +
            '<td class="actions"><div class="row-actions">' + details + refreshButton(r) + '</div></td></tr>';
    }

    function renderFilterSummary() {
        const wrap = document.getElementById('domainFilterSummary');
        const tags = [];
        if (state.filter !== 'all') tags.push({ key: 'filter', label: 'Status', value: FILTER_LABELS[state.filter] || state.filter });
        if (state.provider) tags.push({ key: 'provider', label: 'Hosting', value: state.provider });
        if (state.country) tags.push({ key: 'country', label: 'Country', value: state.countryName || state.country });
        if (state.q) tags.push({ key: 'q', label: 'Search', value: state.q });
        if (!tags.length) { wrap.hidden = true; wrap.innerHTML = ''; return; }
        wrap.hidden = false;
        wrap.innerHTML = '<span>Filtered by</span>' + tags.map(function (t) {
            return '<span class="filter-tag">' + esc(t.label) + ': <b>' + esc(t.value) + '</b>' +
                '<button type="button" data-remove-filter="' + t.key + '" aria-label="Remove ' + esc(t.label) + ' filter"><i class="bi bi-x-lg" aria-hidden="true"></i></button></span>';
        }).join('') + '<button type="button" class="btn btn-sm btn-ghost" data-remove-filter="all">Clear all</button>' +
            '<span class="ms-auto">' + SW.fmt.num(total) + ' matching</span>';
    }

    function syncControls() {
        document.getElementById('domainSearch').value = state.q;
        document.getElementById('domainFilter').value = state.filter;
        document.getElementById('domainSort').value = state.sort;
    }

    async function load(silent) {
        const body = document.getElementById('domainsBody');
        if (silent && body.contains(document.activeElement)) return;
        if (!silent) body.classList.add('is-refreshing');
        try {
            const res = await SW.api('api/domains/list.php', {
                query: { q: state.q, filter: state.filter, sort: state.sort, provider: state.provider, country: state.country, page: state.page, per_page: state.perPage },
            });
            const d = res.data;
            rows = d.rows;
            total = d.total;
            renderSummary(d.summary);
            const filtered = state.q || state.filter !== 'all' || state.provider || state.country;
            body.innerHTML = rows.length
                ? rows.map(rowHtml).join('')
                : '<tr><td colspan="7">' + (filtered
                    ? SW.emptyState('bi-funnel', 'No domains match these filters.', 'Try a different search or clear the filters.', '<button type="button" class="btn btn-sm btn-light" data-remove-filter="all">Clear filters</button>')
                    : SW.emptyState('bi-globe2', 'No websites are being monitored yet.', 'Domain details appear here for every monitored website.')) + '</td></tr>';
            SW.tooltips(body);
            renderFilterSummary();
            SW.pagination(document.getElementById('domainsPagination'), d.page, d.per_page, d.total, function (p) { state.page = p; load(); });
        } catch (e) {
            body.innerHTML = '<tr><td colspan="7">' + SW.emptyState('bi-wifi-off', 'Unable to load domains', e.message) + '</td></tr>';
        } finally {
            body.classList.remove('is-refreshing');
        }
    }

    function setFilter(changes) {
        Object.assign(state, changes, { page: 1 });
        syncControls();
        load();
    }

    // ------------------------------------------------------------------
    // Details modal & refresh
    // ------------------------------------------------------------------
    function renderModal(d, websiteId) {
        SW.DomainInfo.render(document.getElementById('domainModalBody'), d, {
            loadRaw: function () {
                return SW.api('api/domains/show.php', { query: { website_id: websiteId, raw: 1 } }).then(function (res) { return res.data.domain; });
            },
        });
    }

    async function openDetails(websiteId) {
        const row = rows.find(function (r) { return r.website_id === websiteId; });
        modalWebsiteId = websiteId;
        document.getElementById('domainModalTitle').textContent = row ? row.name : 'Domain details';
        document.getElementById('domainModalWebsite').href = row ? row.urls.details : '#';
        const body = document.getElementById('domainModalBody');
        body.innerHTML = SW.DomainInfo.loading('Loading…');
        modal.show();
        try {
            const res = await SW.api('api/domains/show.php', { query: { website_id: websiteId } });
            let d = res.data.domain;
            if (!d || d.stale) {
                body.innerHTML = SW.DomainInfo.loading('Asking the domain registry and DNS… this can take up to 30 seconds.');
                const fresh = await SW.api('api/domains/refresh.php', { method: 'POST', body: { website_id: websiteId } });
                d = fresh.data.domain;
                load(true);
            }
            if (modalWebsiteId === websiteId) renderModal(d, websiteId);
        } catch (e) {
            if (modalWebsiteId === websiteId) body.innerHTML = SW.emptyState('bi-exclamation-triangle', 'Unable to load domain details', e.message);
        }
    }

    async function refreshOne(websiteId, btn) {
        SW.setLoading(btn, true);
        try {
            const res = await SW.api('api/domains/refresh.php', { method: 'POST', body: { website_id: websiteId, force: 1 } });
            SW.toast(res.message, 'success');
            if (modalWebsiteId === websiteId && document.getElementById('domainModal').classList.contains('show')) renderModal(res.data.domain, websiteId);
            await load(true);
        } catch (e) {
            SW.toast(e.message, 'danger');
        } finally {
            SW.setLoading(btn, false);
        }
    }

    async function refreshOutdated(btn) {
        if (refreshingAll) return;
        let ids;
        try {
            const res = await SW.api('api/domains/list.php', { query: { per_page: 5, with_stale: 1 } });
            ids = res.data.stale_ids || [];
        } catch (e) { SW.toast(e.message, 'danger'); return; }
        if (!ids.length) { SW.toast('Every website’s domain details are up to date.', 'success'); return; }

        const ok = await SW.confirm({
            title: 'Refresh ' + ids.length + ' website' + (ids.length === 1 ? '' : 's') + '?',
            message: 'Each lookup asks the domain registry and DNS and takes a few seconds. Keep this page open until it finishes.',
            confirmText: 'Refresh now',
            danger: false,
            icon: 'bi-arrow-repeat',
        });
        if (!ok) return;

        refreshingAll = true;
        const original = btn.innerHTML;
        btn.disabled = true;
        let done = 0;
        let failed = 0;
        for (const id of ids) {
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> ' + done + ' of ' + ids.length;
            try {
                await SW.api('api/domains/refresh.php', { method: 'POST', body: { website_id: id } });
            } catch (e) {
                failed++;
            }
            done++;
            if (done % 5 === 0) load(true);
        }
        btn.innerHTML = original;
        btn.disabled = false;
        refreshingAll = false;
        SW.toast('Refreshed ' + (done - failed) + ' of ' + ids.length + ' website' + (ids.length === 1 ? '' : 's') + (failed ? ' · ' + failed + ' failed' : '') + '.', failed ? 'warning' : 'success');
        load();
    }

    // ------------------------------------------------------------------
    // Lookup
    // ------------------------------------------------------------------
    async function lookup(ev) {
        ev.preventDefault();
        const form = ev.currentTarget;
        const input = form.querySelector('[name="query"]');
        const btn = form.querySelector('[type="submit"]');
        const card = document.getElementById('lookupCard');
        const out = document.getElementById('lookupResult');
        const query = input.value.trim();
        SW.showErrors(form, {});
        if (!query) { SW.showErrors(form, { query: 'Enter a domain name, for example example.com.' }); return; }

        SW.setLoading(btn, true, 'Looking up…');
        card.hidden = false;
        out.innerHTML = SW.DomainInfo.loading('Querying the registry, DNS and hosting network for ' + query + '… this can take up to 30 seconds.');
        try {
            const res = await SW.api('api/domains/lookup.php', { method: 'POST', body: { query: query } });
            SW.DomainInfo.render(out, res.data.domain);
            card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        } catch (e) {
            card.hidden = true;
            out.innerHTML = '';
            SW.showErrors(form, e.errors || {});
            SW.toast(e.message, 'danger');
        } finally {
            SW.setLoading(btn, false);
        }
    }

    document.addEventListener('sw:ready', function () {
        if (!document.getElementById('domainsCard')) return;
        modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('domainModal'));
        document.getElementById('domainModal').addEventListener('hidden.bs.modal', function () { modalWebsiteId = null; });

        document.getElementById('domainSearch').addEventListener('input', SW.debounce(function () { setFilter({ q: this.value.trim() }); }, 300));
        document.getElementById('domainFilter').addEventListener('change', function () { setFilter({ filter: this.value }); });
        document.getElementById('domainSort').addEventListener('change', function () { setFilter({ sort: this.value }); });

        SW.qsa('[data-domain-filter]').forEach(function (b) {
            b.addEventListener('click', function () {
                setFilter({ filter: b.getAttribute('data-domain-filter'), provider: '', country: '', countryName: '', q: '' });
                document.getElementById('domainsCard').scrollIntoView({ block: 'start', behavior: 'smooth' });
            });
        });

        document.addEventListener('click', function (ev) {
            const breakdown = ev.target.closest('[data-breakdown]');
            if (breakdown) {
                const key = breakdown.getAttribute('data-breakdown');
                setFilter(key === 'provider'
                    ? { provider: breakdown.getAttribute('data-value'), country: '', countryName: '' }
                    : { country: breakdown.getAttribute('data-value'), countryName: breakdown.getAttribute('data-label'), provider: '' });
                document.getElementById('domainsCard').scrollIntoView({ block: 'start', behavior: 'smooth' });
                return;
            }
            const remove = ev.target.closest('[data-remove-filter]');
            if (remove) {
                const key = remove.getAttribute('data-remove-filter');
                if (key === 'all') setFilter({ q: '', filter: 'all', provider: '', country: '', countryName: '' });
                else if (key === 'filter') setFilter({ filter: 'all' });
                else if (key === 'country') setFilter({ country: '', countryName: '' });
                else setFilter({ [key]: '' });
                return;
            }
            const action = ev.target.closest('[data-domain-action]');
            if (action) {
                ev.preventDefault();
                const id = parseInt(action.getAttribute('data-id'), 10);
                if (action.getAttribute('data-domain-action') === 'view') openDetails(id);
                else refreshOne(id, action);
            }
        });

        const modalRefresh = document.getElementById('domainModalRefresh');
        if (modalRefresh) modalRefresh.addEventListener('click', function () { if (modalWebsiteId) refreshOne(modalWebsiteId, modalRefresh); });
        const refreshAll = document.getElementById('btnRefreshStale');
        if (refreshAll) refreshAll.addEventListener('click', function () { refreshOutdated(refreshAll); });
        const lookupForm = document.getElementById('lookupForm');
        if (lookupForm) lookupForm.addEventListener('submit', lookup);
        const lookupClear = document.getElementById('lookupClear');
        if (lookupClear) lookupClear.addEventListener('click', function () {
            document.getElementById('lookupCard').hidden = true;
            document.getElementById('lookupResult').innerHTML = '';
            document.getElementById('lookupQuery').value = '';
            document.getElementById('lookupQuery').focus();
        });

        document.getElementById('domainsBody').innerHTML = SW.skeletonRows(7, 6);
        load();
        SW.poll(function () { if (!refreshingAll) return load(true); }, Math.max(60000, (SW.config.refresh || 30) * 2000));
    });
})();
