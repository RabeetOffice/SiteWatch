/* SiteWatch — WordPress (SiteWatch Connector) section on the website details page */
(function () {
    'use strict';

    const STATE_TONES = { none: 'neutral', pending: 'info', connected: 'success', stale: 'warning', deactivated: 'warning', disconnected: 'warning' };
    const CHECK_ICONS = {
        ok: 'bi-check-circle-fill text-success', info: 'bi-info-circle text-muted',
        warning: 'bi-exclamation-triangle-fill text-warning', critical: 'bi-x-octagon-fill text-danger',
    };
    const SEVERITY_ICONS = {
        info: 'bi-dot text-muted', warning: 'bi-exclamation-triangle text-warning', critical: 'bi-shield-exclamation text-danger',
    };
    const VULN_TONES = { critical: 'danger', high: 'danger', medium: 'warning', low: 'neutral', none: 'neutral' };
    const TABS = [
        ['errors', 'Errors'], ['security', 'Security'], ['updates', 'Updates'], ['plugins', 'Plugins & themes'],
        ['performance', 'Performance'], ['remote', 'Remote actions'], ['activity', 'Activity'], ['environment', 'Environment'],
    ];

    const id = SW.page.id;
    const conf = SW.page.connector || {};
    let data = null;
    let tab = SW.storage.get('sw-wp-tab', 'errors');
    let key = null;

    const el = {};

    function esc(v) { return SW.escape(v === null || v === undefined ? '' : String(v)); }
    function ago(unix) { return unix ? SW.escape(new Date(unix * 1000).toLocaleString()) : '—'; }
    function bytes(n) {
        if (n === null || n === undefined) return '—';
        const u = ['B', 'KB', 'MB', 'GB', 'TB'];
        let i = 0;
        while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
        return (i ? n.toFixed(1) : n) + ' ' + u[i];
    }

    async function load() {
        try {
            const res = await SW.api('api/connector/show.php', { query: { website_id: id } });
            data = res.data.connector;
            render();
        } catch (e) {
            el.body.innerHTML = '<p class="text-danger fs-13 mb-0">' + esc(e.message) + '</p>';
        }
    }

    async function manage(action, confirmOpts) {
        if (confirmOpts && !(await SW.confirm(confirmOpts))) return;
        try {
            const res = await SW.api('api/connector/manage.php', { method: 'POST', body: { website_id: id, action: action } });
            if (res.data.key) key = res.data.key;
            if (res.data.connector) data = res.data.connector;
            if (action === 'revoke') key = null;
            if (res.message) SW.toast(res.message, 'success');
            render();
        } catch (e) { SW.toast(e.message, 'danger'); }
    }

    function renderActions() {
        const html = [];
        html.push('<a class="btn btn-sm btn-light" href="' + SW.url('api/connector/plugin.php') + '"><i class="bi bi-download" aria-hidden="true"></i> Download plugin</a>');
        if (conf.canManage) {
            if (data.state === 'none') {
                html.push('<button type="button" class="btn btn-sm btn-primary" data-wp="create"><i class="bi bi-key" aria-hidden="true"></i> Create connection key</button>');
            } else {
                if (data.plugin_outdated && data.can_self_update) {
                    html.push('<button type="button" class="btn btn-sm btn-primary" data-wp="update"' + (data.want_update ? ' disabled' : '') + '><i class="bi bi-arrow-up-circle" aria-hidden="true"></i> ' +
                        (data.want_update ? 'Update requested' : 'Update plugin to ' + esc(data.bundled_version)) + '</button>');
                }
                if (data.state !== 'pending') html.push('<button type="button" class="btn btn-sm btn-light" data-wp="refresh"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Refresh health report</button>');
                html.push('<div class="dropdown"><button type="button" class="btn-icon btn-sm" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More connector actions"><i class="bi bi-three-dots" aria-hidden="true"></i></button>' +
                    '<ul class="dropdown-menu dropdown-menu-end">' +
                    '<li><a class="dropdown-item" href="#" data-wp="show"><i class="bi bi-key"></i> Show connection key</a></li>' +
                    '<li><a class="dropdown-item" href="#" data-wp="create"><i class="bi bi-arrow-clockwise"></i> Replace key</a></li>' +
                    '<li><hr class="dropdown-divider"></li><li><a class="dropdown-item text-danger" href="#" data-wp="revoke"><i class="bi bi-x-circle"></i> Revoke key</a></li>' +
                    '</ul></div>');
            }
        }
        el.actions.innerHTML = html.join('');
        SW.dropdowns(el.actions);
    }

    function keyBox() {
        if (!key) return '';
        return '<div class="wp-key mb-3">' +
            '<label class="form-label" for="wpKey">Connection key <span class="text-muted fw-normal">· paste in WordPress → Settings → SiteWatch</span></label>' +
            '<div class="d-flex gap-2"><textarea class="form-control mono fs-12" id="wpKey" rows="2" readonly>' + esc(key) + '</textarea>' +
            '<button type="button" class="btn btn-light" data-wp="copy"><i class="bi bi-clipboard" aria-hidden="true"></i> Copy</button></div>' +
            '<div class="form-text">Treat it like a password: anyone with this key can send reports for this website. Replace it if it leaks.</div></div>';
    }

    function setupSteps() {
        return '<ol class="wp-steps">' +
            '<li><b>Download the plugin</b> with the button above.</li>' +
            '<li>In WordPress: <b>Plugins → Add New → Upload Plugin</b>, choose the zip, then <b>Activate</b>.</li>' +
            '<li>' + (conf.canManage ? 'Click <b>Create connection key</b> and copy it.' : 'Ask an administrator for a connection key.') + '</li>' +
            '<li>In WordPress: <b>Settings → SiteWatch</b>, paste the key and click <b>Connect</b>.</li></ol>';
    }

    function tiles() {
        const snap = data.snapshot || {};
        const env = snap.environment || {};
        const updates = data.updates_pending;
        const issues = data.security_issues;
        const vulns = data.vulnerabilities ? data.vulnerabilities.count : null;
        const tile = function (label, value, tone, help) {
            return '<div class="kv-tile"' + (help ? ' title="' + esc(help) + '"' : '') + '><div class="kv-tile-label">' + esc(label) + '</div>' +
                '<div class="kv-tile-value' + (tone ? ' text-' + tone : '') + '">' + value + '</div></div>';
        };
        return '<div class="kv-tiles mb-3">' +
            tile('WordPress', esc(data.wp_version || env.wp_version || '—')) +
            tile('PHP', esc(data.php_version || '—')) +
            tile('Plugin', esc(data.plugin_version || '—') + (data.plugin_outdated ? ' <i class="bi bi-arrow-up-circle text-warning" title="Version ' + esc(data.bundled_version) + ' is available: download and upload it in WordPress"></i>' : '')) +
            tile('Last report', esc(data.last_seen_at ? SW.fmt.timeAgo(data.last_seen_at) : 'Never'), data.state === 'stale' ? 'warning' : '') +
            tile('Updates', updates === null ? '—' : String(updates), updates ? 'warning' : 'success', 'Core, plugin and theme updates waiting') +
            tile('Security', issues === null ? '—' : (issues ? issues + ' to fix' : 'OK'), issues ? 'danger' : 'success', 'Security checks in warning or critical state') +
            tile('Vulnerabilities', vulns === null ? '—' : (vulns ? String(vulns) : 'None known'), vulns ? 'danger' : (vulns === null ? '' : 'success'), 'Known vulnerabilities in the installed plugins, themes and WordPress version') +
            '</div>';
    }

    /** What WordPress itself saw: used to tell a real outage from SiteWatch's checks being blocked. */
    function insideView() {
        const p = data.pulse;
        if (!p) return '';
        const probe = p.probe_at
            ? esc(p.probe_ago) + (p.probe_status ? ' (HTTP ' + esc(p.probe_status) + ')' : '')
            : 'Not seen yet';
        return '<div class="wp-inside mb-3" title="Recorded inside WordPress. When SiteWatch checks fail but WordPress keeps serving pages, the alert is held for up to 30 minutes.">' +
            '<span><i class="bi bi-check-circle text-success" aria-hidden="true"></i> Last page served: <b>' + esc(p.ok_ago) + '</b></span>' +
            '<span><i class="bi bi-x-circle ' + (p.error_at ? 'text-danger' : 'text-muted') + '" aria-hidden="true"></i> Last server error: <b>' + esc(p.error_ago) + '</b></span>' +
            '<span><i class="bi bi-broadcast text-muted" aria-hidden="true"></i> Last SiteWatch check that reached WordPress: <b>' + probe + '</b></span></div>';
    }

    /** Daily summary of PHP warnings, notices and deprecations (plugin 1.3.0+, switched on in Monitoring Settings). */
    function phpWarnings() {
        const w = data.snapshot && data.snapshot.php_warnings;
        let html = '<h4 class="wp-subhead mb-2 mt-4">PHP warnings and deprecations</h4>';
        if (!w || !w.enabled) {
            return html + '<p class="fs-13 text-muted mb-0">' + (data.insights && data.insights.php_warnings
                ? 'Switched on: the first summary arrives with the next daily health report (plugin 1.3.0 or later).'
                : 'Off. Turn on "Collect PHP warnings and deprecations" under Monitoring Settings → WordPress plugin to see what will break on the next PHP version.') + '</p>';
        }
        if (!w.entries.length) {
            return html + '<p class="fs-13 text-success mb-0"><i class="bi bi-check2-circle" aria-hidden="true"></i> No PHP warnings' + (w.since ? ' since ' + ago(w.since) : '') + '.</p>';
        }
        const tones = { Warning: 'warning', Notice: 'neutral', Deprecated: 'info' };
        return html + '<p class="fs-12 text-muted mb-2">At least ' + esc(w.total.toLocaleString()) + ' in ' + esc(w.places) + ' places since ' + ago(w.since) + '. Deprecations become errors in a later PHP version.</p>' +
            '<div class="sw-table-wrap"><table class="sw-table compact"><thead><tr><th>Type</th><th>Source</th><th>Message</th><th class="text-end">Count</th></tr></thead><tbody>' +
            w.entries.map(function (e) {
                const c = e.component || {};
                return '<tr><td><span class="sw-pill tone-' + (tones[e.type] || 'neutral') + '">' + esc(e.type) + '</span></td>' +
                    '<td><div class="fw-600">' + esc(c.name ? c.name + (c.version ? ' ' + c.version : '') : (c.type === 'core' ? 'WordPress core' : '—')) + '</div>' +
                    '<div class="fs-12 text-muted mono">' + esc(e.file) + ':' + esc(e.line) + '</div></td>' +
                    '<td class="fs-13">' + esc(e.message) + '</td><td class="text-end mono">' + esc(Number(e.count).toLocaleString()) + '</td></tr>';
            }).join('') + '</tbody></table></div>';
    }

    /** Snapshot plugin for a plugin folder slug (fatal errors know the folder, not the main file). */
    function pluginBySlug(slug) {
        return ((data.snapshot && data.snapshot.plugins) || []).find(function (p) {
            return p.file && (p.file.indexOf(slug + '/') === 0 || p.file === slug + '.php');
        }) || null;
    }
    function allows(action) {
        return conf.canRemote && data.remote && (data.remote.allowed || []).indexOf(action) !== -1;
    }
    /** One-click remote action button; the click handler confirms and sends it. */
    function goButton(action, args, label, cls, confirmText) {
        return '<button type="button" class="btn btn-sm ' + (cls || 'btn-light') + '" data-remote-go="' + esc(action) + '" data-remote-args="' + esc(JSON.stringify(args)) + '"' +
            (confirmText ? ' data-remote-confirm="' + esc(confirmText) + '"' : '') + '>' + label + '</button>';
    }
    /** Deactivate / roll back buttons for a plugin named by a fatal error or an auto-fix. */
    function pluginFixButtons(p, withDeactivate) {
        if (!p) return '';
        let html = '';
        if (withDeactivate && p.active && allows('deactivate_plugin')) {
            html += goButton('deactivate_plugin', { plugin: p.file }, 'Deactivate ' + esc(p.name), 'btn-light', 'Deactivate ' + p.name + '? Features that depend on it stop working until it is activated again.');
        }
        if (!p.active && allows('activate_plugin')) {
            html += goButton('activate_plugin', { plugin: p.file }, 'Activate again', 'btn-light', 'Activate ' + p.name + ' again? If it still crashes, the error comes back (auto-fix does not switch it off again for 24 hours).');
        }
        if (p.previous_version && allows('rollback_plugin') && p.updated_at && Date.now() / 1000 - p.updated_at < 7 * 86400) {
            html += goButton('rollback_plugin', { plugin: p.file, version: p.previous_version }, 'Roll back to ' + esc(p.previous_version), 'btn-light',
                'Install ' + p.name + ' ' + p.previous_version + ' from WordPress.org again, replacing ' + p.version + '? Check the Security tab: an older version can have known vulnerabilities.');
        }
        return html;
    }

    function tabErrors() {
        if (!data.errors.length) {
            return SW.emptyState('bi-emoji-smile', 'No fatal errors reported.', 'When PHP crashes on this site, the file, line and plugin or theme responsible appear here.') + phpWarnings();
        }
        return '<div class="wp-errors">' + data.errors.map(function (e) {
            const d = e.data || {};
            const comp = d.component || {};
            const req = d.request || {};
            return '<article class="wp-error">' +
                '<div class="d-flex flex-wrap gap-2 align-items-center mb-1"><b>' + esc(comp.name ? comp.name + (comp.version ? ' ' + comp.version : '') : 'Unknown source') + '</b>' +
                '<span class="sw-pill tone-danger">' + esc(d.error_type || 'Fatal error') + '</span>' +
                (e.occurrences > 1 ? '<span class="sw-pill tone-neutral">' + esc(e.occurrences) + '×</span>' : '') +
                '<span class="fs-12 text-muted ms-auto" title="First seen ' + esc(e.first_label) + '">' + esc(e.last_ago) + '</span></div>' +
                '<pre class="wp-error-msg">' + esc(d.message) + '</pre>' +
                '<div class="fs-12 text-muted"><span class="mono">' + esc(d.file) + ':' + esc(d.line) + '</span>' +
                (req.path ? ' · ' + esc((req.method || '') + ' ' + req.path) + ' (' + esc(req.context || 'front') + ')' : '') +
                ' · first ' + esc(e.first_label) + (e.notified ? ' · alert sent' : '') + '</div>' +
                (comp.type === 'plugin' && comp.slug ? (function (b) { return b ? '<div class="d-flex flex-wrap gap-2 mt-2">' + b + '</div>' : ''; })(pluginFixButtons(pluginBySlug(comp.slug), true)) : '') +
                '</article>';
        }).join('') + '</div>' + phpWarnings();
    }

    /** Known vulnerabilities from the latest scan (done by SiteWatch against the WPVulnerability database). */
    function vulnerabilities() {
        const v = data.vulnerabilities;
        let html = '<div class="d-flex flex-wrap align-items-baseline gap-2 mb-2"><h4 class="wp-subhead">Known vulnerabilities</h4><span class="fs-12 text-muted">' +
            (v ? 'Checked ' + esc(v.checked_label) + ' against the <a href="' + esc(v.source_url) + '" target="_blank" rel="noopener">' + esc(v.source) + '</a> database' +
                (v.stale ? ' · checking the new health report' : '')
                : 'Not checked yet: the first check runs within a few minutes of the health report') + '</span></div>';
        if (!v) return html;
        if (v.unavailable) {
            html += '<p class="fs-12 text-warning mb-2"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> ' + esc(v.unavailable) + ' of ' + esc(v.components) +
                ' components could not be looked up; they are retried within the hour.</p>';
        }
        if (!v.items.length) {
            html += '<p class="fs-13 text-success mb-2"><i class="bi bi-shield-check" aria-hidden="true"></i> No known vulnerabilities in the ' + esc(v.components) +
                ' installed plugins, themes and WordPress version.</p>';
        } else {
            html += '<div class="sw-table-wrap mb-2"><table class="sw-table compact"><thead><tr><th>Component</th><th>Vulnerability</th><th>Severity</th><th>Fix</th></tr></thead><tbody>' +
                v.items.map(function (i) {
                    const kind = i.component === 'core' ? 'WordPress core' : (i.component === 'theme' ? 'Theme' : 'Plugin');
                    const fix = i.fixed_in ? 'Update to ' + esc(i.fixed_in) + ' or later'
                        : i.unfixed ? '<span class="text-danger">No fix yet: remove or replace it</span>'
                        : i.component === 'core' ? 'Update WordPress' : 'Update to the latest version';
                    const severity = i.severity
                        ? '<span class="sw-pill tone-' + (VULN_TONES[i.severity] || 'neutral') + '">' + esc(i.severity.charAt(0).toUpperCase() + i.severity.slice(1)) +
                            (i.score !== null && i.score !== undefined ? ' ' + esc(i.score) : '') + '</span>'
                        : '<span class="text-faint">Unknown</span>';
                    return '<tr><td><div class="fw-600">' + esc(i.name) + ' <span class="mono fw-normal">' + esc(i.version) + '</span></div>' +
                        '<div class="fs-12 text-muted">' + kind + (i.active === false ? ' · inactive' : '') + (i.update ? ' · ' + esc(i.update) + ' available' : '') + '</div></td>' +
                        '<td>' + (i.link ? '<a href="' + esc(i.link) + '" target="_blank" rel="noopener">' + esc(i.title) + '</a>' : esc(i.title)) +
                        (i.cve ? '<div class="fs-12 text-muted mono">' + esc(i.cve) + '</div>' : '') + '</td>' +
                        '<td>' + severity + '</td><td class="fs-13">' + fix + '</td></tr>';
                }).join('') + '</tbody></table></div>';
        }
        if (v.closed.length) {
            html += '<p class="fs-12 text-muted mb-2"><i class="bi bi-archive" aria-hidden="true"></i> Closed on WordPress.org, so no more updates: ' +
                v.closed.map(function (c) { return '<b>' + esc(c.name) + '</b>' + (c.reason ? ' (' + esc(c.reason) + ')' : ''); }).join(', ') + '</p>';
        }
        return html + '<p class="fs-12 text-faint mb-0">Matched by plugin and theme folder name, so a custom plugin that shares its folder name with a WordPress.org plugin can show that plugin\'s vulnerabilities.</p>';
    }

    function tabSecurity() {
        const checks = (data.snapshot && data.snapshot.security) || [];
        const vulnHtml = '<section class="mb-4">' + vulnerabilities() + '</section>';
        if (!checks.length) return vulnHtml + SW.emptyState('bi-shield', 'No security report yet.', 'It arrives with the plugin\'s first health report.');
        const order = { critical: 0, warning: 1, info: 2, ok: 3 };
        return vulnHtml + '<h4 class="wp-subhead mb-2">Security checks</h4><ul class="wp-checks">' + checks.slice().sort(function (a, b) { return order[a.status] - order[b.status]; }).map(function (c) {
            return '<li><i class="bi ' + (CHECK_ICONS[c.status] || CHECK_ICONS.info) + '" aria-hidden="true"></i><div><div class="fw-600">' + esc(c.label) +
                '<span class="visually-hidden"> (' + esc(c.status) + ')</span></div>' + (c.detail ? '<div class="fs-12 text-muted">' + esc(c.detail) + '</div>' : '') + '</div></li>';
        }).join('') + '</ul>';
    }

    function tabUpdates() {
        const u = (data.snapshot && data.snapshot.updates) || null;
        if (!u) return SW.emptyState('bi-arrow-up-circle', 'No update information yet.', '');
        const rows = [];
        if (u.core && u.core.latest) rows.push(['WordPress', u.core.current, u.core.latest, 'Core']);
        (u.plugins || []).forEach(function (p) { rows.push([p.name, p.version, p.update, p.active ? 'Plugin' : 'Plugin (inactive)']); });
        (u.themes || []).forEach(function (t) { rows.push([t.name, t.version, t.update, t.active ? 'Theme (active)' : 'Theme']); });
        if (!rows.length) return SW.emptyState('bi-check2-circle', 'Everything is up to date.', 'WordPress, plugins and themes have no pending updates.');
        return '<div class="sw-table-wrap"><table class="sw-table compact"><thead><tr><th>Name</th><th>Type</th><th>Installed</th><th>Available</th></tr></thead><tbody>' +
            rows.map(function (r) { return '<tr><td class="fw-600">' + esc(r[0]) + '</td><td>' + esc(r[3]) + '</td><td class="mono">' + esc(r[1]) + '</td><td class="mono text-warning">' + esc(r[2]) + '</td></tr>'; }).join('') +
            '</tbody></table></div>';
    }

    function tabPlugins() {
        const snap = data.snapshot || {};
        const plugins = snap.plugins || [];
        const themes = snap.themes || [];
        if (!plugins.length && !themes.length) return SW.emptyState('bi-plug', 'No plugin list yet.', '');
        const vulnerable = {};
        ((data.vulnerabilities && data.vulnerabilities.items) || []).forEach(function (i) {
            vulnerable[i.component + ':' + i.slug] = (vulnerable[i.component + ':' + i.slug] || 0) + 1;
        });
        const flag = function (component, slug) {
            const n = vulnerable[component + ':' + String(slug || '').toLowerCase()];
            return n ? ' <i class="bi bi-shield-exclamation text-danger" title="' + n + ' known vulnerabilit' + (n === 1 ? 'y' : 'ies') + ': see the Security tab"></i>' : '';
        };
        const row = function (name, version, state, update, auto, slug) {
            return '<tr><td class="fw-600">' + esc(name) + flag('plugin', slug) + '</td><td class="mono">' + esc(version) + '</td><td>' + state + '</td><td>' +
                (update ? '<span class="text-warning mono">' + esc(update) + '</span>' : '<span class="text-faint">—</span>') + '</td><td>' + (auto ? 'On' : '<span class="text-faint">Off</span>') + '</td></tr>';
        };
        return '<div class="sw-table-wrap"><table class="sw-table compact"><thead><tr><th>Plugin</th><th>Version</th><th>State</th><th>Update</th><th>Auto-update</th></tr></thead><tbody>' +
            plugins.map(function (p) { return row(p.name, p.version, p.active ? '<span class="sw-pill tone-success">Active</span>' : '<span class="sw-pill tone-neutral">Inactive</span>', p.update, p.auto_update, p.slug); }).join('') +
            '</tbody></table></div>' +
            '<div class="sw-table-wrap mt-3"><table class="sw-table compact"><thead><tr><th>Theme</th><th>Version</th><th>State</th><th>Update</th></tr></thead><tbody>' +
            themes.map(function (t) {
                return '<tr><td class="fw-600">' + esc(t.name) + flag('theme', t.slug) + '</td><td class="mono">' + esc(t.version) + '</td><td>' +
                    (t.active ? '<span class="sw-pill tone-success">Active</span>' : (t.parent ? '<span class="sw-pill tone-info">Parent theme</span>' : '<span class="sw-pill tone-neutral">Installed</span>')) +
                    '</td><td>' + (t.update ? '<span class="text-warning mono">' + esc(t.update) + '</span>' : '<span class="text-faint">—</span>') + '</td></tr>';
            }).join('') + '</tbody></table></div>';
    }

    /** Page generation time measured inside WordPress on sampled requests (plugin 1.3.0+). */
    function tabPerformance() {
        const perf = data.snapshot && data.snapshot.performance;
        if (!perf) {
            return SW.emptyState('bi-speedometer2', 'No page speed data yet.', data.insights && data.insights.perf_sample
                ? 'Plugin 1.3.0 or later measures 1 in ' + data.insights.perf_sample + ' requests and reports with the daily health report.'
                : 'Page speed sampling is off under Monitoring Settings → WordPress plugin.');
        }
        const labels = { front: 'Pages', admin: 'Admin', ajax: 'AJAX', rest: 'REST API' };
        const ms = function (v) { return v === null || v === undefined ? '—' : Number(v).toLocaleString() + ' ms'; };
        const keys = Object.keys(perf.contexts || {}).sort(function (a, b) { return (labels[a] ? Object.keys(labels).indexOf(a) : 9) - (labels[b] ? Object.keys(labels).indexOf(b) : 9); });
        let html = '<p class="fs-12 text-muted mb-2">Time WordPress took to build the page (server side, before the network), from ' + esc((perf.requests || 0).toLocaleString()) +
            ' sampled requests' + (perf.sample_rate ? ' (1 in ' + esc(perf.sample_rate) + ')' : '') + (perf.since ? ' since ' + ago(perf.since) : '') + '.</p>';
        if (!keys.length) {
            html += '<p class="fs-13 text-muted">No sampled requests in this period yet. Low-traffic sites need a day or two.</p>';
        } else {
            html += '<div class="sw-table-wrap mb-3"><table class="sw-table compact"><thead><tr><th>Requests</th><th class="text-end">Samples</th><th class="text-end">Median</th><th class="text-end">75%</th><th class="text-end">95%</th><th class="text-end">99%</th><th class="text-end">Slowest</th><th class="text-end">Queries</th><th class="text-end">Memory</th></tr></thead><tbody>' +
                keys.map(function (k) {
                    const c = perf.contexts[k];
                    const tone = c.p95 > 3000 ? ' text-danger' : (c.p95 > 1000 ? ' text-warning' : '');
                    return '<tr><td class="fw-600">' + esc(labels[k] || k) + '</td><td class="text-end mono">' + esc(c.samples) + '</td><td class="text-end mono">' + ms(c.p50) + '</td><td class="text-end mono">' + ms(c.p75) +
                        '</td><td class="text-end mono' + tone + '">' + ms(c.p95) + '</td><td class="text-end mono">' + ms(c.p99) + '</td><td class="text-end mono">' + ms(c.max) +
                        '</td><td class="text-end mono">' + esc(c.avg_queries) + '</td><td class="text-end mono">' + esc(c.avg_memory) + ' MB</td></tr>';
                }).join('') + '</tbody></table></div>';
        }
        if (perf.slowest && perf.slowest.length) {
            html += '<h4 class="wp-subhead mb-2">Slowest sampled requests</h4><ul class="wp-activity mb-3">' + perf.slowest.map(function (r) {
                return '<li><i class="bi bi-hourglass-split text-muted" aria-hidden="true"></i><div class="min-w-0"><div class="mono fs-13 text-truncate">' + esc(r.p || '/') + '</div>' +
                    '<div class="fs-12 text-muted">' + ms(r.ms) + ' · ' + esc(r.q) + ' queries · ' + esc(r.mem) + ' MB · ' + esc(labels[r.c] || r.c) + (r.s >= 500 ? ' · HTTP ' + esc(r.s) : '') + '</div></div></li>';
            }).join('') + '</ul>';
        }
        html += '<h4 class="wp-subhead mb-2">Slow database queries</h4>';
        if (!perf.savequeries) {
            html += '<p class="fs-13 text-muted mb-0">Not recorded. Add <code>define("SAVEQUERIES", true);</code> to wp-config.php for a day to see queries slower than 50 ms (it costs some memory on every request, so remove it afterwards).</p>';
        } else if (!perf.slow_queries.length) {
            html += '<p class="fs-13 text-success mb-0"><i class="bi bi-check2-circle" aria-hidden="true"></i> No query over 50 ms in the sampled requests.</p>';
        } else {
            html += '<div class="sw-table-wrap"><table class="sw-table compact"><thead><tr><th>Query (values replaced by ?)</th><th>Called from</th><th class="text-end">Times</th><th class="text-end">Slowest</th><th class="text-end">Total</th></tr></thead><tbody>' +
                perf.slow_queries.map(function (q) {
                    return '<tr><td class="mono fs-12">' + esc(q.sql) + '</td><td class="fs-12">' + esc(q.caller) + '</td><td class="text-end mono">' + esc(q.count) + '</td><td class="text-end mono">' + ms(q.max_ms) + '</td><td class="text-end mono">' + ms(q.total_ms) + '</td></tr>';
                }).join('') + '</tbody></table></div>';
        }
        return html;
    }

    const COMMAND_TONES = { pending: 'info', sent: 'info', done: 'success', failed: 'danger', expired: 'warning' };

    /** Remote actions (plugin 1.4.0+): only what the site's WordPress administrator allowed. */
    function tabRemote() {
        const r = data.remote;
        if (!r) return SW.emptyState('bi-sliders', 'Not connected.', '');
        const allowed = r.allowed;
        let html = '';
        if (allowed === null) {
            html += '<p class="fs-13 text-muted">Remote actions need SiteWatch Connector 1.4.0 or later on this site' + (data.plugin_outdated ? ' (the update to ' + esc(data.bundled_version) + ' is on its way).' : '.') + '</p>';
        } else if (!allowed.length) {
            html += '<p class="fs-13 text-muted">This site allows no remote actions. A WordPress administrator can allow them one by one under <b>Settings → SiteWatch → Remote actions</b>.</p>';
        } else if (!conf.canRemote) {
            html += '<p class="fs-13 text-muted">This site allows: ' + esc(allowed.map(function (a) { return r.actions[a]; }).join(', ')) + '. Your role does not include "Run remote actions".</p>';
        } else {
            html += '<p class="fs-12 text-muted mb-3">The site runs a request after its next report (usually within 5 minutes). Allowed on this site: ' + esc(allowed.map(function (a) { return r.actions[a]; }).join(', ')) + '.</p>';
            const plugins = ((data.snapshot && data.snapshot.plugins) || []).filter(function (p) { return p.file && p.file.indexOf('sitewatch-connector/') !== 0; });
            const option = function (p) { return '<option value="' + esc(p.file) + '">' + esc(p.name + ' ' + p.version) + '</option>'; };
            const row = function (title, help, controls) {
                return '<div class="wp-remote-row"><div class="min-w-0"><div class="fw-600">' + title + '</div><div class="fs-12 text-muted">' + help + '</div></div><div class="d-flex flex-wrap gap-2 align-items-center">' + controls + '</div></div>';
            };
            if (allowed.indexOf('clear_cache') !== -1) {
                html += row('Clear caches', 'Page cache plugins (LiteSpeed, WP Rocket, W3 Total Cache, WP Super Cache and others) and the object cache.',
                    '<button type="button" class="btn btn-sm btn-light" data-remote="clear_cache"><i class="bi bi-trash3" aria-hidden="true"></i> Clear caches</button>');
            }
            if (allowed.indexOf('maintenance') !== -1) {
                html += row('Maintenance page', r.maintenance_until ? '<span class="text-warning">On until ' + esc(r.maintenance_label) + '.</span> Visitors see a 503 maintenance page; signed-in editors see the site. Alerts are held.'
                        : 'Visitors see "Briefly unavailable for scheduled maintenance"; signed-in editors keep working. SiteWatch holds maintenance alerts meanwhile.',
                    (r.maintenance_until ? '<button type="button" class="btn btn-sm btn-primary" data-remote="maintenance_off">End maintenance</button>' : '') +
                    '<select class="form-select form-select-sm w-auto" data-remote-minutes aria-label="Duration">' + [[15, '15 min'], [30, '30 min'], [60, '1 hour'], [120, '2 hours'], [240, '4 hours'], [1440, '24 hours']].map(function (o) { return '<option value="' + o[0] + '"' + (o[0] === 60 ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select>' +
                    '<input type="text" class="form-control form-control-sm w-auto" maxlength="200" placeholder="Message (optional)" data-remote-message aria-label="Maintenance message">' +
                    '<button type="button" class="btn btn-sm btn-light" data-remote="maintenance_on">' + (r.maintenance_until ? 'Restart' : 'Switch on') + '</button>');
            }
            if (allowed.indexOf('update_plugins') !== -1) {
                const updates = plugins.filter(function (p) { return p.update; });
                html += row('Update plugins', updates.length ? updates.length + ' update(s) in the last health report, installed with WordPress\u2019s own updater (a broken update is rolled back on WordPress 6.6+).' : 'No plugin updates in the last health report.',
                    updates.length ? '<div class="wp-remote-list">' + updates.map(function (p) {
                        return '<label class="form-check fs-13"><input class="form-check-input" type="checkbox" data-remote-update value="' + esc(p.file) + '" checked> ' + esc(p.name) + ' <span class="mono text-muted">' + esc(p.version) + ' \u2192 ' + esc(p.update) + '</span></label>';
                    }).join('') + '</div><button type="button" class="btn btn-sm btn-light" data-remote="update_plugins"><i class="bi bi-arrow-up-circle" aria-hidden="true"></i> Update selected</button>' : '');
            }
            if (allowed.indexOf('deactivate_plugin') !== -1) {
                const active = plugins.filter(function (p) { return p.active; });
                html += row('Deactivate a plugin', 'For example the plugin behind a fatal error. It stays installed and can be activated again.',
                    active.length ? '<select class="form-select form-select-sm w-auto" data-remote-deactivate aria-label="Plugin to deactivate">' + active.map(option).join('') + '</select><button type="button" class="btn btn-sm btn-light" data-remote="deactivate_plugin">Deactivate</button>' : '<span class="fs-13 text-muted">No active plugins.</span>');
            }
            if (allowed.indexOf('activate_plugin') !== -1) {
                const inactive = plugins.filter(function (p) { return !p.active; });
                html += row('Activate a plugin', 'WordPress refuses a plugin that fails while loading, so a broken plugin is not switched on.',
                    inactive.length ? '<select class="form-select form-select-sm w-auto" data-remote-activate aria-label="Plugin to activate">' + inactive.map(option).join('') + '</select><button type="button" class="btn btn-sm btn-light" data-remote="activate_plugin">Activate</button>' : '<span class="fs-13 text-muted">No inactive plugins.</span>');
            }
        }

        const af = data.autofix;
        html += '<h4 class="wp-subhead mb-2 mt-4">Auto-fix</h4><p class="fs-13 ' + (af && af.enabled ? '' : 'text-muted') + ' mb-2">' + (af === null || af === undefined
            ? 'Needs SiteWatch Connector 1.5.0 or later.'
            : af.enabled
                ? '<i class="bi bi-bandaid text-success" aria-hidden="true"></i> On: a plugin whose fatal error repeats 3 times in 10 minutes is deactivated and you get an alert.' +
                    (af.protected.length ? ' Never touched: ' + esc(af.protected.join(', ')) + '.' : '')
                : 'Off. A WordPress administrator can switch it on under Settings → SiteWatch → Auto-fix.') + '</p>';
        if (allows('rollback_plugin')) {
            const rollbacks = ((data.snapshot && data.snapshot.plugins) || []).filter(function (p) { return p.previous_version; });
            html += '<div class="wp-remote-row"><div class="min-w-0"><div class="fw-600">Roll back a plugin update</div><div class="fs-12 text-muted">Installs the version a plugin had before its last update, from WordPress.org. Versions are recorded at each update from plugin 1.5.0 on.</div></div>' +
                '<div class="d-flex flex-wrap gap-2 align-items-center">' + (rollbacks.length ? rollbacks.map(function (p) {
                    return goButton('rollback_plugin', { plugin: p.file, version: p.previous_version }, esc(p.name) + ': ' + esc(p.version) + ' \u2192 ' + esc(p.previous_version), 'btn-light',
                        'Install ' + p.name + ' ' + p.previous_version + ' from WordPress.org again, replacing ' + p.version + '? Check the Security tab: an older version can have known vulnerabilities.');
                }).join('') : '<span class="fs-13 text-muted">No update recorded yet.</span>') + '</div></div>';
        }
        html += '<h4 class="wp-subhead mb-2 mt-4">Recent requests</h4>';
        if (!r.commands.length) return html + '<p class="fs-13 text-muted mb-0">None yet.</p>';
        return html + '<ul class="wp-activity">' + r.commands.map(function (c) {
            const d = c.details || {};
            let what = c.label;
            if (c.args.plugin) what += ': ' + c.args.plugin;
            if (c.args.plugins) what += ': ' + c.args.plugins.length + ' plugin(s)';
            if (c.action === 'maintenance') what += c.args.mode === 'on' ? ' on for ' + c.args.minutes + ' min' : ' off';
            if (c.action === 'rollback_plugin') what += ' to ' + c.args.version;
            const lines = (d.plugins || []).map(function (p) { return esc(p.name) + ': ' + esc(p.message) + (p.to ? ' (' + esc(p.from) + ' \u2192 ' + esc(p.to) + ')' : ''); });
            return '<li><span class="sw-pill tone-' + (COMMAND_TONES[c.status] || 'neutral') + '">' + esc(c.status_label) + '</span><div class="min-w-0">' +
                '<div>' + esc(what) + '</div><div class="fs-12 text-muted">' + esc(c.created_ago) + (c.requested_by ? ' · by ' + esc(c.requested_by) : '') + (c.message ? ' · ' + esc(c.message) : '') + '</div>' +
                (lines.length ? '<div class="fs-12 text-muted">' + lines.join('<br>') + '</div>' : '') + '</div></li>';
        }).join('') + '</ul>';
    }

    async function remote(action, args, confirmOpts) {
        if (confirmOpts && !(await SW.confirm(confirmOpts))) return;
        try {
            const res = await SW.api('api/connector/command.php', { method: 'POST', body: { website_id: id, action: action, args: args || {} } });
            if (res.data.connector) data = res.data.connector;
            SW.toast(res.message, 'success');
            render();
        } catch (e) { SW.toast(e.message, 'danger'); }
    }

    function remoteClick(button) {
        const panel = button.closest('[data-wp-panel]');
        const pick = function (sel) { const el = panel.querySelector(sel); return el ? el.value : ''; };
        const name = function (sel) { const el = panel.querySelector(sel); return el && el.selectedOptions[0] ? el.selectedOptions[0].textContent : ''; };
        switch (button.getAttribute('data-remote')) {
            case 'clear_cache':
                return remote('clear_cache', {});
            case 'maintenance_on':
                return remote('maintenance', { mode: 'on', minutes: parseInt(pick('[data-remote-minutes]'), 10), message: pick('[data-remote-message]') }, {
                    title: 'Switch the maintenance page on?', confirmText: 'Switch on',
                    message: 'Visitors will see a maintenance page for ' + name('[data-remote-minutes]') + ' (signed-in editors see the site). It ends by itself.',
                });
            case 'maintenance_off':
                return remote('maintenance', { mode: 'off' });
            case 'update_plugins': {
                const files = SW.qsa('[data-remote-update]:checked', panel).map(function (c) { return c.value; });
                if (!files.length) return SW.toast('Select at least one plugin.', 'warning');
                return remote('update_plugins', { plugins: files }, { title: 'Update ' + files.length + ' plugin(s)?', confirmText: 'Update', danger: false, icon: 'bi-arrow-up-circle', message: 'WordPress installs the updates from WordPress.org after the site\u2019s next report.' });
            }
            case 'deactivate_plugin':
                return remote('deactivate_plugin', { plugin: pick('[data-remote-deactivate]') }, { title: 'Deactivate ' + name('[data-remote-deactivate]') + '?', danger: true, confirmText: 'Deactivate', message: 'Features that depend on this plugin stop working until it is activated again.' });
            case 'activate_plugin':
                return remote('activate_plugin', { plugin: pick('[data-remote-activate]') }, { title: 'Activate ' + name('[data-remote-activate]') + '?', confirmText: 'Activate', danger: false, message: 'The plugin runs on every page again.' });
        }
    }

    function tabActivity() {
        if (!data.activity.length) return SW.emptyState('bi-clock-history', 'No activity reported yet.', 'Plugin and theme changes, WordPress updates and administrator sign-ins appear here.');
        return '<ul class="wp-activity">' + data.activity.map(function (a) {
            const d = a.data || {};
            let extra = '';
            if (a.type === 'plugin_auto_deactivated' && d.error) {
                extra = esc(d.count) + ' crashes in 10 min · ' + esc(d.error.message || '');
            } else if (a.type === 'login_failures' && d.ips) {
                extra = Object.keys(d.ips).slice(0, 5).map(function (ip) { return esc(ip) + ' (' + esc(d.ips[ip]) + ')'; }).join(', ');
            } else if (a.type === 'file_changed') {
                extra = esc(d.change) + (d.size_before !== null && d.size_after !== null ? ', ' + esc(d.size_before) + ' → ' + esc(d.size_after) + ' bytes' : '');
            } else if (a.type === 'setting_changed') {
                extra = esc(d.option) + ': “' + esc(d.from) + '” → “' + esc(d.to) + '”';
            } else if (d.ip) {
                extra = 'from ' + esc(d.ip);
            }
            return '<li><i class="bi ' + (SEVERITY_ICONS[a.severity] || SEVERITY_ICONS.info) + '" aria-hidden="true"></i><div class="min-w-0">' +
                '<div>' + esc(a.title) + (a.occurrences > 1 ? ' <span class="text-muted">×' + esc(a.occurrences) + '</span>' : '') + '</div>' +
                '<div class="fs-12 text-muted">' + esc(a.last_label) + (d.by ? ' · by ' + esc(d.by) : '') + (extra ? ' · ' + extra : '') + '</div></div></li>';
        }).join('') + '</ul>';
    }

    function tabEnvironment() {
        const s = data.snapshot;
        if (!s) return SW.emptyState('bi-hdd-stack', 'No health report yet.', '');
        const env = s.environment || {};
        const db = s.database || {};
        const cron = s.cron || {};
        const kv = function (k, v) { return '<dt>' + esc(k) + '</dt><dd>' + v + '</dd>'; };
        return '<div class="row g-4"><div class="col-lg-6"><dl class="kv-list mb-0">' +
            kv('Site URL', esc(env.home_url)) + kv('Server', esc(env.server_software || '—')) + kv('Database server', esc(env.db_server || '—')) +
            kv('PHP memory limit', esc(env.memory_limit) + (env.wp_memory_limit ? ' <span class="text-muted">(WordPress ' + esc(env.wp_memory_limit) + ')</span>' : '')) +
            kv('Max execution time', esc(env.max_execution_time) + ' s') + kv('Upload limit', esc(env.upload_max_filesize) + ' / post ' + esc(env.post_max_size)) +
            kv('Object cache', env.object_cache ? 'Yes' : 'No') + kv('Image library', esc(env.image_library)) +
            (env.missing_extensions && env.missing_extensions.length ? kv('Missing PHP extensions', '<span class="text-warning">' + esc(env.missing_extensions.join(', ')) + '</span>') : '') +
            kv('Free disk space', bytes(env.disk_free_bytes)) + kv('Locale / timezone', esc(env.locale) + ' · ' + esc(env.timezone || '—')) +
            '</dl></div><div class="col-lg-6"><dl class="kv-list mb-3">' +
            kv('Database size', bytes(db.size_bytes) + ' · ' + esc(db.tables) + ' tables') +
            kv('Autoloaded options', bytes(db.autoload_bytes) + (db.autoload_bytes > 1048576 ? ' <span class="text-warning">(large: slows every page)</span>' : '')) +
            kv('Post revisions', esc(db.revisions)) + kv('Expired transients', esc(db.expired_transients)) +
            kv('Scheduled tasks', esc(cron.events) + ' · ' + (cron.overdue ? '<span class="text-warning">' + esc(cron.overdue) + ' overdue</span>' : 'none overdue') + (cron.disabled ? ' · WP-Cron disabled' : '')) +
            kv('Report generated', ago(s.generated_at)) +
            '</dl><div class="form-label">Administrators</div><ul class="wp-admins">' + (s.admins || []).map(function (a) {
                return '<li><b>' + esc(a.login) + '</b>' + (a.name && a.name !== a.login ? ' <span class="text-muted">(' + esc(a.name) + ')</span>' : '') +
                    '<span class="fs-12 text-muted"> · last sign-in ' + (a.last_login ? ago(a.last_login) : 'not recorded yet') + '</span></li>';
            }).join('') + '</ul></div></div>';
    }

    function render() {
        el.sub.textContent = data.state === 'none'
            ? 'Install the free plugin to see why errors happen, not just that they happened'
            : data.state_label + (data.last_seen_at ? ' · last report ' + SW.fmt.timeAgo(data.last_seen_at) : '') + (data.site_url ? ' · ' + data.site_url : '');
        renderActions();

        const statePill = '<span class="sw-pill tone-' + (STATE_TONES[data.state] || 'neutral') + '">' + esc(data.state_label) + '</span>';
        let html = '';
        if (data.state === 'none' || data.state === 'pending') {
            html += '<div class="wp-setup"><div class="d-flex align-items-center gap-2 mb-2">' + statePill +
                (data.state === 'pending' ? '<span class="fs-13 text-muted">Key created ' + esc(SW.fmt.timeAgo(data.key_created_at)) + '. Waiting for WordPress to connect.</span>' : '') + '</div>' +
                '<p class="fs-13 text-muted mb-2">The SiteWatch Connector plugin reports fatal errors with the file, line and plugin responsible (without turning on debug), a daily health and security report, and changes such as plugin updates and new administrators.</p>' +
                keyBox() + setupSteps() + '</div>';
            el.body.innerHTML = html;
            return;
        }

        if (data.state !== 'connected') {
            const why = {
                stale: 'No report for over 30 minutes. The site may be down, WP-Cron may not be running (low-traffic sites report less often), or the plugin was removed.',
                deactivated: 'The plugin was deactivated in WordPress. Activate it again to resume reporting.',
                disconnected: 'Someone clicked Disconnect in WordPress. Paste the key again under Settings → SiteWatch to reconnect.',
            }[data.state] || '';
            html += '<div class="alert alert-warning py-2 fs-13">' + esc(why) + '</div>';
        }
        const bannerShown = {};
        (data.auto_deactivated || []).forEach(function (ev) {
            const d = ev.data || {};
            if (bannerShown[d.file]) return; // newest first: one banner per plugin
            bannerShown[d.file] = true;
            const p = ((data.snapshot && data.snapshot.plugins) || []).find(function (x) { return x.file === d.file; }) || null;
            if (Date.now() - new Date(ev.last_occurred_at.replace(' ', 'T') + 'Z').getTime() > 86400000 || (p && p.active)) return;
            const err = d.error || {};
            html += '<div class="alert alert-warning py-2 fs-13"><div><i class="bi bi-bandaid" aria-hidden="true"></i> <b>' + esc(d.name || d.file) + '</b> was deactivated automatically ' + esc(ev.last_ago) +
                ' after ' + esc(d.count) + ' fatal errors in 10 minutes: <span class="mono">' + esc(err.message || '') + '</span> <span class="text-muted">(' + esc(err.file || '') + ':' + esc(err.line || '') + ')</span></div>' +
                (p ? '<div class="d-flex flex-wrap gap-2 mt-2">' + pluginFixButtons(p, false) + '</div>' : '') + '</div>';
        });
        if (data.remote && data.remote.maintenance_until) {
            html += '<div class="alert alert-warning py-2 fs-13"><i class="bi bi-cone-striped" aria-hidden="true"></i> Maintenance page switched on from SiteWatch until ' + esc(data.remote.maintenance_label) + '. Maintenance alerts are held until then.</div>';
        }
        const r = data.reachability;
        if (r) {
            const since = r.probe_ago ? 'for ' + Math.round(r.probe_ago / 3600) + ' h' : 'since the plugin was connected';
            html += r.state === 'blocked'
                ? '<div class="alert alert-danger py-2 fs-13"><b>SiteWatch is probably blocked on this site.</b> Checks are failing, yet WordPress keeps serving pages to visitors and has not seen a SiteWatch check ' + esc(since) + '. ' +
                    'A firewall, security plugin or CDN is likely stopping the checks. Allow-list requests whose User-Agent contains <code>' + esc(r.user_agent) + '</code>' +
                    (r.server_ip ? ' and this SiteWatch server’s address <code>' + esc(r.server_ip) + '</code>' : '') +
                    (r.probe_ip ? ' (checks last reached WordPress from <code>' + esc(r.probe_ip) + '</code>)' : '') + '.</div>'
                : '<div class="alert alert-info py-2 fs-13">SiteWatch’s checks have not reached WordPress ' + esc(since) + ' while the site works. A page cache or CDN probably answers them, which is fine while checks pass. ' +
                    'If checks start failing while visitors are fine, allow-list the User-Agent <code>' + esc(r.user_agent) + '</code>' + (r.server_ip ? ' and <code>' + esc(r.server_ip) + '</code>' : '') + ' in your firewall or CDN.</div>';
        }
        if (data.plugin_outdated) {
            html += '<div class="alert alert-info py-2 fs-13">Plugin version ' + esc(data.bundled_version) + ' is available (this site runs ' + esc(data.plugin_version) + '). ' +
                (!data.can_self_update ? 'This copy cannot update itself yet: click <b>Download plugin</b>, then in WordPress go to Plugins → Add New → Upload Plugin and choose <b>Replace current with uploaded</b>. Later versions install themselves.'
                    : data.want_update ? 'Update requested: the site installs it after its next report.'
                    : data.auto_update ? 'The site installs it automatically within a few minutes.'
                    : 'Automatic updates are off (Monitoring Settings); click "Update plugin" above.') + '</div>';
        }
        if (data.update_result) {
            const failed = /^failed/i.test(data.update_result);
            html += '<p class="fs-12 mb-2 ' + (failed ? 'text-warning' : 'text-muted') + '"><i class="bi ' + (failed ? 'bi-exclamation-triangle' : 'bi-arrow-up-circle') + '" aria-hidden="true"></i> Last self-update ' +
                esc(data.update_label || '') + ': ' + esc(data.update_result) + '</p>';
        }
        if (data.want_snapshot) {
            html += '<p class="fs-12 text-muted">A fresh health report was requested; it arrives with the next report from the site.</p>';
        }
        html += keyBox() + tiles() + insideView();

        const counts = {
            errors: data.errors.length,
            security: (data.security_issues || 0) + (data.vulnerabilities ? data.vulnerabilities.count : 0),
            updates: data.updates_pending || 0,
            remote: data.remote ? data.remote.commands.filter(function (c) { return c.status === 'pending' || c.status === 'sent'; }).length : 0,
        };
        html += '<div class="segmented mb-3 wp-tabs" role="tablist" aria-label="WordPress details">' + TABS.map(function (t) {
            const n = counts[t[0]];
            return '<button type="button" role="tab" data-wp-tab="' + t[0] + '" aria-selected="' + (tab === t[0]) + '"' + (tab === t[0] ? ' class="active"' : '') + '>' +
                esc(t[1]) + (n ? ' <span class="wp-count">' + n + '</span>' : '') + '</button>';
        }).join('') + '</div><div role="tabpanel" data-wp-panel></div>';
        el.body.innerHTML = html;
        renderTab();
    }

    function renderTab() {
        const panel = el.body.querySelector('[data-wp-panel]');
        if (!panel) return;
        const renderers = { errors: tabErrors, security: tabSecurity, updates: tabUpdates, plugins: tabPlugins, performance: tabPerformance, remote: tabRemote, activity: tabActivity, environment: tabEnvironment };
        panel.innerHTML = (renderers[tab] || tabErrors)();
    }

    document.addEventListener('sw:ready', function () {
        const section = document.getElementById('wordpressSection');
        if (!section) return;
        el.body = section.querySelector('[data-wp-body]');
        el.actions = section.querySelector('[data-wp-actions]');
        el.sub = section.querySelector('[data-wp-sub]');

        section.addEventListener('click', function (ev) {
            const t = ev.target.closest('[data-wp-tab]');
            if (t) {
                tab = t.getAttribute('data-wp-tab');
                SW.storage.set('sw-wp-tab', tab);
                SW.qsa('[data-wp-tab]', section).forEach(function (b) {
                    const on = b === t;
                    b.classList.toggle('active', on);
                    b.setAttribute('aria-selected', String(on));
                });
                renderTab();
                return;
            }
            const go = ev.target.closest('[data-remote-go]');
            if (go) {
                ev.preventDefault();
                let args = {};
                try { args = JSON.parse(go.getAttribute('data-remote-args') || '{}'); } catch (e) { /* keep empty */ }
                const text = go.getAttribute('data-remote-confirm');
                remote(go.getAttribute('data-remote-go'), args, text ? { title: go.textContent.trim() + '?', message: text, confirmText: go.textContent.trim(), danger: go.getAttribute('data-remote-go') === 'deactivate_plugin' } : null);
                return;
            }
            const rb = ev.target.closest('[data-remote]');
            if (rb) {
                ev.preventDefault();
                remoteClick(rb);
                return;
            }
            const a = ev.target.closest('[data-wp]');
            if (!a) return;
            ev.preventDefault();
            const action = a.getAttribute('data-wp');
            if (action === 'copy') {
                const box = document.getElementById('wpKey');
                if (navigator.clipboard && box) {
                    navigator.clipboard.writeText(box.value).then(function () { SW.toast('Connection key copied.', 'success'); },
                        function () { box.select(); SW.toast('Copy failed. The key is selected; press Ctrl+C.', 'warning'); });
                } else if (box) { box.select(); }
            } else if (action === 'create') {
                manage('create', data.state === 'none' ? null : {
                    title: 'Replace the connection key?', danger: true, confirmText: 'Replace key',
                    message: 'The plugin stops reporting until the new key is pasted in WordPress (Settings → SiteWatch).',
                });
            } else if (action === 'update') {
                manage('update');
            } else if (action === 'revoke') {
                manage('revoke', { title: 'Revoke the connection key?', message: 'The plugin on this site can no longer send reports. Stored errors and activity are kept.', confirmText: 'Revoke key' });
            } else {
                manage(action);
            }
        });

        load();
        if (location.hash === '#wordpressSection') section.scrollIntoView();
        SW.poll(load, 60000);
    });
})();
