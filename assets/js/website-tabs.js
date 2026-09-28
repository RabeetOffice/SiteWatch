/* SiteWatch — website page: section tabs and the Countries card */
(function () {
    'use strict';

    const STORE = 'sw-site-tab';

    function show(key, focus) {
        const tabs = SW.qsa('.site-tabs [data-tab]');
        if (!tabs.some(function (t) { return t.getAttribute('data-tab') === key; })) key = 'overview';
        tabs.forEach(function (t) {
            const on = t.getAttribute('data-tab') === key;
            t.classList.toggle('active', on);
            t.setAttribute('aria-selected', String(on));
            t.tabIndex = on ? 0 : -1;
            if (on && focus) t.focus();
        });
        SW.qsa('.site-pane').forEach(function (p) { p.hidden = p.getAttribute('data-pane') !== key; });
        SW.storage.set(STORE, key);
        // Keep the tab in the address (Back, bookmarks and sharing) without adding a history entry per click.
        const url = new URL(window.location.href);
        if (key === 'overview') url.searchParams.delete('tab'); else url.searchParams.set('tab', key);
        try { history.replaceState(history.state, '', url.href); } catch (e) { /* ignore */ }
        // Charts drawn while their pane was hidden need the real size now.
        window.dispatchEvent(new Event('resize'));
    }

    // ------------------------------------------------------------------
    // Countries card (overview)
    // ------------------------------------------------------------------
    const TONES = { reachable: 'success', slow: 'warning', geo_blocked: 'warning', blocked: 'danger', unreachable: 'danger', down: 'danger', no_probe: 'neutral' };
    const LABELS = { reachable: 'Reachable', slow: 'Slow', geo_blocked: 'Blocked by the site', blocked: 'Possibly blocked', unreachable: 'Unreachable', down: 'Down everywhere', no_probe: 'No test server' };

    function renderCountries(list, enabled) {
        if (SW.countryMap) SW.countryMap(enabled ? list : []);
        const body = document.querySelector('[data-cc-body]');
        const sub = document.querySelector('[data-cc-sub]');
        if (!body) return;
        if (!enabled) {
            body.innerHTML = '<p class="fs-13 text-muted mb-0">Country checks are switched off. They can be turned on in Settings › Monitoring.</p>';
            return;
        }
        const checked = list.filter(function (c) { return c.result; });
        if (!checked.length) {
            body.innerHTML = '<p class="fs-13 text-muted mb-0">Not checked from other countries yet. The scheduled job checks every website each day' +
                (SW.page.countries.canRun ? ', or use Check now.' : '.') + '</p>';
            if (sub) sub.textContent = 'Does it open from other countries?';
            return;
        }
        const problems = checked.filter(function (c) { return ['geo_blocked', 'blocked', 'unreachable'].indexOf(c.result) !== -1; });
        const latest = checked.reduce(function (a, c) { return !a || c.checked_at > a ? c.checked_at : a; }, null);
        if (sub) sub.innerHTML = (problems.length
            ? '<span class="text-danger fw-600">Not opening from ' + problems.map(function (c) { return SW.escape(c.name); }).join(', ') + '</span>'
            : 'Opens from all ' + checked.length + ' countries tested') + ' · ' + SW.timeAgoEl(latest);
        body.innerHTML = '<div class="cc-strip">' + list.map(function (c) {
            const tone = c.result ? TONES[c.result] || 'neutral' : 'none';
            const label = c.result ? LABELS[c.result] || c.result : 'Not checked';
            const t = c.result === 'reachable' || c.result === 'slow' ? SW.fmt.ms(c.response_ms) : (c.status_code ? 'HTTP ' + c.status_code : '');
            return '<div class="cc-strip-item" title="' + SW.escape(c.name + ': ' + label + (c.network ? ' · via ' + c.network : '')) + '">' +
                '<span class="cc-dot tone-' + tone + '" aria-hidden="true"></span><span class="code">' + SW.escape(c.code) + '</span>' +
                '<span class="visually-hidden">' + SW.escape(c.name + ': ' + label) + '</span><span class="t">' + SW.escape(t) + '</span></div>';
        }).join('') + '</div>';
    }

    async function loadCountries() {
        const res = await SW.api('api/countries/list.php', { query: { website_id: SW.page.id } });
        renderCountries(res.data.countries, res.data.enabled);
    }

    document.addEventListener('sw:ready', function () {
        if (!document.querySelector('.site-tabs')) return;

        // 1.x links pointed at #wordpressSection / #domainSection.
        const legacy = { '#wordpressSection': 'wordpress', '#domainSection': 'domain', '#performanceSection': 'performance' }[window.location.hash];
        const requested = legacy || new URL(window.location.href).searchParams.get('tab');
        show(requested || 'overview', false);

        SW.qsa('.site-tabs [data-tab]').forEach(function (t) {
            t.addEventListener('click', function () { show(t.getAttribute('data-tab'), false); });
            t.addEventListener('keydown', function (ev) {
                if (ev.key !== 'ArrowRight' && ev.key !== 'ArrowLeft' && ev.key !== 'Home' && ev.key !== 'End') return;
                ev.preventDefault();
                const tabs = SW.qsa('.site-tabs [data-tab]');
                let i = tabs.indexOf(t);
                if (ev.key === 'ArrowRight') i = (i + 1) % tabs.length;
                else if (ev.key === 'ArrowLeft') i = (i - 1 + tabs.length) % tabs.length;
                else if (ev.key === 'Home') i = 0;
                else i = tabs.length - 1;
                show(tabs[i].getAttribute('data-tab'), true);
            });
        });
        document.addEventListener('click', function (ev) {
            const link = ev.target.closest ? ev.target.closest('[data-tab-link]') : null;
            if (!link) return;
            ev.preventDefault();
            show(link.getAttribute('data-tab-link'), false);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        if (SW.page.countries && SW.page.countries.canView && document.querySelector('[data-cc-body]')) {
            loadCountries().catch(function () {
                const body = document.querySelector('[data-cc-body]');
                if (body) body.innerHTML = '<p class="fs-13 text-muted mb-0">Country results could not be loaded.</p>';
            });
            const run = document.querySelector('[data-cc-run]');
            if (run) {
                if (!SW.page.countries.enabled) run.disabled = true;
                run.addEventListener('click', async function () {
                    SW.setLoading(run, true, 'Checking…');
                    try {
                        const res = await SW.api('api/countries/run.php', { method: 'POST', body: { website_id: SW.page.id } });
                        renderCountries(res.data.countries, true);
                        SW.toast(res.message, 'success');
                    } catch (e) {
                        SW.toast(e.message, e.status === 429 ? 'warning' : 'danger', { delay: 9000 });
                    } finally {
                        SW.setLoading(run, false);
                    }
                });
            }
        }
    });
})();
