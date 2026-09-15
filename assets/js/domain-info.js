/* SiteWatch — domain registration & hosting details (website details page and Domains page) */
(function () {
    'use strict';

    const esc = SW.escape;

    function none(text) { return '<span class="text-faint">' + esc(text || '—') + '</span>'; }
    function value(v, fallback) { return v !== null && v !== undefined && v !== '' ? esc(v) : none(fallback); }
    function codes(items) {
        if (!items || !items.length) return none();
        return '<span class="chip-list">' + items.map(function (i) { return '<span class="code-inline">' + esc(i) + '</span>'; }).join('') + '</span>';
    }
    function kv(rows) {
        return '<dl class="kv-list mb-0">' + rows.filter(Boolean).map(function (r) { return '<dt>' + esc(r[0]) + '</dt><dd>' + r[1] + '</dd>'; }).join('') + '</dl>';
    }
    function fact(label, val, sub) {
        return '<div class="domain-fact"><div class="l">' + esc(label) + '</div><div class="v">' + val + '</div>' + (sub ? '<div class="s">' + sub + '</div>' : '') + '</div>';
    }
    function warning(title, text) {
        return '<div class="alert alert-warning mb-0 d-flex gap-2"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><div class="min-w-0">' +
            '<b>' + esc(title) + '</b><div class="fs-13 break-anywhere">' + esc(text) + '</div></div></div>';
    }

    /** Routine expiry dates stay quiet; soon-expiring and expired ones get a coloured pill. */
    function expiryText(r) {
        if (!r.expires_at) return none('Not published');
        return r.expiry_tone === 'success' || r.expiry_tone === 'neutral' ? esc(r.expiry_label) : SW.pill(r.expiry_label, r.expiry_tone, 'bi-calendar-x');
    }

    function registrationHtml(d) {
        const r = d.registration;
        if (!r.found) {
            return warning('Registration details unavailable for ' + (d.domain_display || d.domain), r.error || 'The registry did not return a record.');
        }
        const registrar = r.registrar
            ? (r.registrar_url ? '<a href="' + esc(r.registrar_url) + '" target="_blank" rel="noopener noreferrer">' + esc(r.registrar) + '</a>' : esc(r.registrar))
            : none('Unknown');
        let html = '<div class="domain-facts">' +
            fact('Domain age', r.age_days !== null ? esc(r.age_label) : none('Unknown'), r.registered_at ? 'Registered ' + esc(r.registered_label) : '') +
            fact('Expires', r.expires_at ? esc(r.expires_label) : none('Unknown'), r.expires_at ? expiryText(r) : '') +
            fact('Registrar', r.registrar ? esc(r.registrar) : none('Unknown'), r.registrar_iana_id ? 'IANA ID ' + esc(r.registrar_iana_id) : '') +
            '</div>';
        html += kv([
            ['Domain', esc(d.domain_display || d.domain)],
            ['Registered', r.registered_at ? esc(r.registered_label) : none('Not published')],
            ['Last changed', r.updated_at ? esc(r.updated_label) : none('Not published')],
            ['Expires', r.expires_at ? esc(r.expires_label) + ' · ' + expiryText(r) : none('Not published')],
            ['Registrar', registrar],
            ['Registrant', r.registrant ? esc(r.registrant) + (r.registrant_country ? ' · ' + esc(r.registrant_country) : '') : none('Hidden for privacy')],
            ['Name servers', codes(r.nameservers)],
            ['Status', r.statuses.length ? '<span class="chip-list">' + r.statuses.map(function (s) { return SW.pill(s, 'neutral'); }).join('') + '</span>' : none()],
            ['DNSSEC', r.dnssec === true ? 'Signed' : (r.dnssec === false ? 'Not signed' : none())],
            r.abuse_email ? ['Abuse contact', '<span class="break-anywhere">' + esc(r.abuse_email) + '</span>'] : null,
            ['Source', esc(r.source_label || '—') + (r.server ? ' <span class="text-muted fs-12 break-anywhere">' + esc(r.server) + '</span>' : '')],
        ]);
        return html;
    }

    function hostingHtml(d) {
        const h = d.hosting;
        const dnsRows = [
            ['DNS hosted by', (h.dns_provider ? esc(h.dns_provider) : none('Unrecognised')) + (h.nameservers.length ? '<div class="mt-1">' + codes(h.nameservers) + '</div>' : '')],
            ['Email hosted by', (h.email_provider ? esc(h.email_provider) : (h.mx.length ? none('Unrecognised') : none('No mail servers'))) +
                (h.mx.length ? '<div class="mt-1">' + codes(h.mx.map(function (m) { return m.host; })) + '</div>' : '')],
        ];
        if (!h.primary_ip) {
            return warning('Hosting details unavailable', h.error || 'The website address did not resolve.') + '<div class="mt-3">' + kv(dnsRows) + '</div>';
        }

        const provider = h.provider ? esc(h.provider) : (h.cdn ? 'Hidden by ' + esc(h.cdn) : value(h.organisation, 'Unknown'));
        const providerSub = h.provider && h.cdn ? 'Served through ' + esc(h.cdn) : (h.organisation && h.organisation !== h.provider ? esc(h.organisation) : '');
        let html = '<div class="domain-facts">' +
            fact('Hosting provider', provider, providerSub) +
            fact('Server location', value(h.location_label, 'Unknown'), h.edge ? 'A CDN edge, not the server itself' : '') +
            fact('IP address', '<span class="mono">' + esc(h.primary_ip) + '</span>', h.asn ? 'AS' + esc(h.asn) : '') +
            '</div>';
        html += h.notes.map(function (n) {
            return '<div class="coverage-note mb-3"><i class="bi bi-info-circle" aria-hidden="true"></i><div>' + esc(n) + '</div></div>';
        }).join('');

        const addresses = h.ips.concat(h.ipv6);
        const software = [h.server, h.powered_by].filter(Boolean).join(' · ');
        html += kv([
            ['IP addresses', codes(addresses)],
            ['Reverse DNS', h.reverse_dns ? '<span class="mono break-anywhere">' + esc(h.reverse_dns) + '</span>' : none('None')],
            ['Network', h.asn ? 'AS' + esc(h.asn) + (h.as_name ? ' · ' + esc(h.as_name) : '') + (h.network ? '<div class="fs-12 text-muted mono">' + esc(h.network) + '</div>' : '') : none()],
            ['CDN / proxy', h.cdn ? esc(h.cdn) : 'None detected'],
            ['Web server', software ? esc(software) : none('Not disclosed')],
            h.cname.length ? ['Alias (CNAME)', codes(h.cname)] : null,
        ].concat(dnsRows));
        return html;
    }

    function showRaw(d) {
        const r = d && d.registration;
        if (!r || !r.raw) { SW.toast('The raw record is not available.', 'warning'); return; }
        let modal = document.getElementById('domainRawModal');
        if (!modal) {
            document.body.insertAdjacentHTML('beforeend',
                '<div class="modal fade" id="domainRawModal" tabindex="-1" aria-labelledby="domainRawTitle" aria-hidden="true">' +
                '<div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">' +
                '<div class="modal-header"><h2 class="modal-title" id="domainRawTitle">Raw record</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>' +
                '<div class="modal-body"><p class="fs-13 text-muted" data-raw-source></p><pre class="copy-box domain-raw mb-0" data-raw-body></pre></div>' +
                '<div class="modal-footer"><button type="button" class="btn btn-light" data-raw-copy><i class="bi bi-clipboard" aria-hidden="true"></i> Copy</button>' +
                '<button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button></div></div></div></div>');
            modal = document.getElementById('domainRawModal');
            modal.querySelector('[data-raw-copy]').addEventListener('click', function () {
                const text = modal.querySelector('[data-raw-body]').textContent;
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(text).then(function () { SW.toast('Copied to the clipboard.', 'success'); }, function () { SW.toast('Copy failed. Select the text instead.', 'warning'); });
                }
            });
        }
        modal.querySelector('#domainRawTitle').textContent = (r.source_label || 'Registry') + ' record · ' + (d.domain_display || d.domain);
        modal.querySelector('[data-raw-source]').textContent = 'As returned by ' + (r.server || 'the registry') + ' on ' + SW.fmt.date(d.checked_at, true) + '.';
        modal.querySelector('[data-raw-body]').textContent = r.raw;
        bootstrap.Modal.getOrCreateInstance(modal).show();
    }

    /**
     * Full two-column rendering (Domains page lookup and detail modal). The raw registry record is an inline
     * disclosure (a second modal cannot open on top of one).
     * opts.loadRaw: optional function returning a promise of details that include the raw record.
     */
    function render(container, d, opts) {
        opts = opts || {};
        const r = d.registration;
        container.innerHTML =
            '<div class="d-flex flex-wrap align-items-center gap-2 mb-3">' +
            '<h3 class="mb-0 break-anywhere" style="font-size:16px">' + esc(d.domain_display || d.domain) + '</h3>' +
            (d.host && d.host !== d.domain ? '<span class="text-muted fs-13 break-anywhere">' + esc(d.host) + '</span>' : '') +
            '<span class="ms-auto fs-12 text-muted">Checked ' + SW.timeAgoEl(d.checked_at) + '</span>' +
            '</div>' +
            '<div class="row g-4"><div class="col-lg-6 min-w-0"><h4 class="domain-section-title">Registration</h4>' + registrationHtml(d) + '</div>' +
            '<div class="col-lg-6 min-w-0"><h4 class="domain-section-title">Hosting</h4>' + hostingHtml(d) + '</div></div>' +
            (r.has_raw
                ? '<details class="sw-disclosure mt-4" data-domain-raw-toggle><summary>Raw ' + esc(r.source_label || 'registry') + ' record' +
                  (r.server ? ' <span class="hint break-anywhere">from ' + esc(r.server) + '</span>' : '') + '</summary>' +
                  '<div class="sw-disclosure-body"><pre class="copy-box domain-raw mb-0" data-raw-body></pre></div></details>'
                : '');

        const toggle = container.querySelector('[data-domain-raw-toggle]');
        if (!toggle) return;
        const body = toggle.querySelector('[data-raw-body]');
        if (r.raw) body.textContent = r.raw;
        toggle.addEventListener('toggle', function () {
            if (!toggle.open || body.textContent !== '' || !opts.loadRaw) return;
            body.textContent = 'Loading…';
            opts.loadRaw().then(function (full) {
                body.textContent = full && full.registration.raw ? full.registration.raw : 'The raw record is not available.';
            }, function (e) { body.textContent = e.message; });
        });
    }

    function loading(text) {
        return '<div class="domain-loading"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span><span>' + esc(text) + '</span></div>';
    }

    /**
     * Website details page: the two domain cards, the "Domain" key-figure group and auto-fill of missing data.
     */
    function mountWebsite(section, websiteId) {
        const regEl = section.querySelector('[data-domain-registration]');
        const hostEl = section.querySelector('[data-domain-hosting]');
        const checkedEl = section.querySelector('[data-domain-checked]');
        const rawBtn = section.querySelector('[data-domain-raw]');
        const refreshBtn = section.querySelector('[data-domain-refresh]');
        let current = null;
        let busy = false;

        function setStat(key, html) {
            const el = document.querySelector('[data-domain-stat="' + key + '"]');
            if (el) el.innerHTML = html;
        }

        function paint(d) {
            current = d;
            if (!d) {
                regEl.innerHTML = SW.emptyState('bi-globe-americas', 'Not checked yet', 'Registration details appear after the first lookup.');
                hostEl.innerHTML = SW.emptyState('bi-hdd-network', 'Not checked yet', 'Hosting details appear after the first lookup.');
                ['age', 'expiry', 'host'].forEach(function (k) { setStat(k, '—'); });
                if (rawBtn) rawBtn.hidden = true;
                return;
            }
            regEl.innerHTML = registrationHtml(d);
            hostEl.innerHTML = hostingHtml(d);
            if (checkedEl) checkedEl.innerHTML = 'Checked ' + SW.timeAgoEl(d.checked_at) + (d.stale ? ' · due for a refresh' : '');
            if (rawBtn) rawBtn.hidden = !d.registration.has_raw;
            const r = d.registration, h = d.hosting;
            setStat('age', r.age_days !== null ? esc(r.age_label) : none('Unknown'));
            setStat('expiry', r.expires_at ? expiryText(r) : none('Unknown'));
            setStat('host', h.provider ? esc(h.provider) : (h.cdn ? 'Behind ' + esc(h.cdn) : none('Unknown')));
            SW.tooltips(section);
        }

        async function refresh(force) {
            if (busy) return;
            busy = true;
            if (refreshBtn) SW.setLoading(refreshBtn, true, 'Checking…');
            if (!current) {
                regEl.innerHTML = loading('Asking the domain registry… this can take up to 30 seconds.');
                hostEl.innerHTML = loading('Resolving DNS and the hosting network…');
            }
            try {
                const res = await SW.api('api/domains/refresh.php', { method: 'POST', body: { website_id: websiteId, force: force ? 1 : 0 } });
                paint(res.data.domain);
                if (force) SW.toast(res.message, 'success');
            } catch (e) {
                if (!current) {
                    regEl.innerHTML = SW.emptyState('bi-exclamation-triangle', 'Lookup failed', e.message);
                    hostEl.innerHTML = '';
                }
                SW.toast(e.message, 'danger');
            } finally {
                busy = false;
                if (refreshBtn) SW.setLoading(refreshBtn, false);
            }
        }

        if (refreshBtn) refreshBtn.addEventListener('click', function () { refresh(true); });
        if (rawBtn) {
            rawBtn.addEventListener('click', async function () {
                SW.setLoading(rawBtn, true);
                try {
                    const res = await SW.api('api/domains/show.php', { query: { website_id: websiteId, raw: 1 } });
                    showRaw(res.data.domain);
                } catch (e) { SW.toast(e.message, 'danger'); }
                finally { SW.setLoading(rawBtn, false); }
            });
        }

        SW.api('api/domains/show.php', { query: { website_id: websiteId } }).then(function (res) {
            paint(res.data.domain);
            // Fill in missing or outdated details automatically.
            if (!res.data.domain || res.data.domain.stale) refresh(false);
        }).catch(function (e) {
            regEl.innerHTML = SW.emptyState('bi-wifi-off', 'Unable to load domain details', e.message);
            hostEl.innerHTML = '';
        });
    }

    SW.DomainInfo = { render: render, registrationHtml: registrationHtml, hostingHtml: hostingHtml, showRaw: showRaw, mountWebsite: mountWebsite, loading: loading };
})();
