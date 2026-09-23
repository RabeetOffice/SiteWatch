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
    const TABS = [
        ['errors', 'Errors'], ['security', 'Security'], ['updates', 'Updates'], ['plugins', 'Plugins & themes'],
        ['activity', 'Activity'], ['environment', 'Environment'],
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

    function tabErrors() {
        if (!data.errors.length) {
            return SW.emptyState('bi-emoji-smile', 'No fatal errors reported.', 'When PHP crashes on this site, the file, line and plugin or theme responsible appear here.');
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
                ' · first ' + esc(e.first_label) + (e.notified ? ' · alert sent' : '') + '</div></article>';
        }).join('') + '</div>';
    }

    function tabSecurity() {
        const checks = (data.snapshot && data.snapshot.security) || [];
        if (!checks.length) return SW.emptyState('bi-shield', 'No security report yet.', 'It arrives with the plugin\'s first health report.');
        const order = { critical: 0, warning: 1, info: 2, ok: 3 };
        return '<ul class="wp-checks">' + checks.slice().sort(function (a, b) { return order[a.status] - order[b.status]; }).map(function (c) {
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
        const row = function (name, version, state, update, auto) {
            return '<tr><td class="fw-600">' + esc(name) + '</td><td class="mono">' + esc(version) + '</td><td>' + state + '</td><td>' +
                (update ? '<span class="text-warning mono">' + esc(update) + '</span>' : '<span class="text-faint">—</span>') + '</td><td>' + (auto ? 'On' : '<span class="text-faint">Off</span>') + '</td></tr>';
        };
        return '<div class="sw-table-wrap"><table class="sw-table compact"><thead><tr><th>Plugin</th><th>Version</th><th>State</th><th>Update</th><th>Auto-update</th></tr></thead><tbody>' +
            plugins.map(function (p) { return row(p.name, p.version, p.active ? '<span class="sw-pill tone-success">Active</span>' : '<span class="sw-pill tone-neutral">Inactive</span>', p.update, p.auto_update); }).join('') +
            '</tbody></table></div>' +
            '<div class="sw-table-wrap mt-3"><table class="sw-table compact"><thead><tr><th>Theme</th><th>Version</th><th>State</th><th>Update</th></tr></thead><tbody>' +
            themes.map(function (t) {
                return '<tr><td class="fw-600">' + esc(t.name) + '</td><td class="mono">' + esc(t.version) + '</td><td>' +
                    (t.active ? '<span class="sw-pill tone-success">Active</span>' : (t.parent ? '<span class="sw-pill tone-info">Parent theme</span>' : '<span class="sw-pill tone-neutral">Installed</span>')) +
                    '</td><td>' + (t.update ? '<span class="text-warning mono">' + esc(t.update) + '</span>' : '<span class="text-faint">—</span>') + '</td></tr>';
            }).join('') + '</tbody></table></div>';
    }

    function tabActivity() {
        if (!data.activity.length) return SW.emptyState('bi-clock-history', 'No activity reported yet.', 'Plugin and theme changes, WordPress updates and administrator sign-ins appear here.');
        return '<ul class="wp-activity">' + data.activity.map(function (a) {
            const d = a.data || {};
            let extra = '';
            if (a.type === 'login_failures' && d.ips) {
                extra = Object.keys(d.ips).slice(0, 5).map(function (ip) { return esc(ip) + ' (' + esc(d.ips[ip]) + ')'; }).join(', ');
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
            security: data.security_issues || 0,
            updates: data.updates_pending || 0,
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
        const renderers = { errors: tabErrors, security: tabSecurity, updates: tabUpdates, plugins: tabPlugins, activity: tabActivity, environment: tabEnvironment };
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
