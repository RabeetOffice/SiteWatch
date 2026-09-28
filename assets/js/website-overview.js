/* SiteWatch — website page › Overview: health banner and score, key figures, 90 days, map, events */
(function () {
    'use strict';

    let data = null;

    /** Approximate centre of each country on the dot map (latitude, longitude). */
    const CENTRES = {
        US: [39, -98], GB: [54, -2], IE: [53, -8], AU: [-25, 134], CA: [57, -106], DE: [51, 10], IN: [22, 79], PK: [30, 70],
        AE: [24, 54], SG: [1.3, 103.8], BR: [-10, -52], ZA: [-29, 24], TR: [39, 35], RU: [60, 95], CN: [35, 104],
        NZ: [-41, 174], FR: [46, 2], NL: [52, 5], JP: [36, 138], NG: [9, 8], SA: [24, 45], ID: [-2, 118], IR: [32, 53],
    };
    const COUNTRY_TONES = { reachable: 'success', slow: 'warning', geo_blocked: 'warning', blocked: 'danger', unreachable: 'danger', down: 'danger', no_probe: 'neutral' };

    function set(key, html) {
        SW.qsa('[data-ov="' + key + '"]').forEach(function (el) { el.innerHTML = html; });
    }

    function durationSince(utc) {
        const d = SW.fmt.parseUtc(utc);
        if (!d) return '';
        return SW.fmt.duration(Math.max(0, (Date.now() - d.getTime()) / 1000));
    }

    function tone(score) { return score === null ? 'neutral' : score >= 85 ? 'success' : score >= 60 ? 'warning' : 'danger'; }

    // ------------------------------------------------------------------
    // Banner
    // ------------------------------------------------------------------
    function renderBanner() {
        const b = data.banner;
        const states = {
            up: ['success', 'Online for ' + durationSince(b.since)],
            warning: ['warning', b.status_label],
            down: ['danger', 'Down for ' + durationSince(b.since)],
            paused: ['neutral', 'Monitoring paused'],
            pending: ['neutral', 'Waiting for the first check'],
        };
        const s = states[b.state] || states.pending;
        set('state', '<span class="pulse tone-' + s[0] + (b.state === 'up' || b.state === 'down' ? ' live' : '') + '" aria-hidden="true"></span><span>' + SW.escape(s[1]) + '</span>');

        const meta = [];
        if (b.last_checked_at) {
            meta.push('<span>Last check ' + SW.timeAgoEl(b.last_checked_at) + (b.last_http ? ' · <b>HTTP ' + b.last_http + '</b>' : '') + (b.last_rt ? ' in <b>' + SW.escape(SW.fmt.ms(b.last_rt)) + '</b>' : '') + '</span>');
        }
        if (b.next_check_at) meta.push('<span data-next-check="' + SW.escape(b.next_check_at) + '">Next check ' + nextCheckText(b.next_check_at) + '</span>');
        if (b.state === 'warning' && b.since) meta.push('<span>No confirmed outage since ' + SW.escape(SW.fmt.date(b.since)) + '</span>');
        set('meta', meta.join('') || '&nbsp;');

        // The current error is already shown under the website name, on every tab.
        renderShot(b.screenshot);
    }

    // ------------------------------------------------------------------
    // Screenshot: thumbnail, or a placeholder that says why there is none
    // ------------------------------------------------------------------
    function renderShot(shot) {
        const slot = document.querySelector('[data-ov="shot"]');
        if (!slot) return;
        const perf = SW.page.performance || {};
        if (shot) {
            const current = slot.querySelector('img');
            if (current && current.getAttribute('src') === shot.url) return;
            slot.innerHTML = '<button type="button" class="ov-shot" data-shot-preview="' + SW.escape(shot.url) + '" data-shot-label="' + SW.escape('Captured ' + shot.captured_label + (shot.provider ? ' · ' + shot.provider : '')) + '" title="Preview the screenshot">' +
                '<img src="' + SW.escape(shot.url) + '" alt="Latest screenshot of the website" loading="lazy">' +
                '<span class="ov-shot-cap"><i class="bi bi-zoom-in" aria-hidden="true"></i> ' + SW.escape(SW.fmt.timeAgo(shot.captured_at)) + '</span></button>';
            return;
        }
        let text, action = '';
        if (!perf.screenshotsEnabled) {
            text = 'Screenshots are off';
            if (perf.canManageSettings) action = '<a class="ov-shot-act" href="' + SW.url('admin/settings.php', { tab: 'monitoring' }) + '#screenshot_enabled">Turn on</a>';
        } else {
            text = 'No screenshot yet';
            if (perf.canRun) action = '<button type="button" class="ov-shot-act" data-shot-capture-now>Capture now</button>';
        }
        slot.innerHTML = '<div class="ov-shot empty"><i class="bi bi-image" aria-hidden="true"></i><span>' + text + '</span>' + action + '</div>';
    }

    async function capture(btn) {
        SW.setLoading(btn, true, 'Capturing…');
        try {
            const res = await SW.api('api/performance/run.php', { method: 'POST', body: { website_id: SW.page.id, action: 'screenshot' } });
            SW.toast(res.message, 'success');
            await load();
            return true;
        } catch (e) {
            SW.toast(e.message, 'danger', { delay: 9000, title: 'Capture failed' });
            return false;
        } finally {
            if (document.contains(btn)) SW.setLoading(btn, false);
        }
    }

    function preview(url, label) {
        const modal = document.getElementById('shotPreview');
        if (!modal) { window.open(url, '_blank', 'noopener'); return; }
        modal.querySelector('[data-shot-image]').src = url;
        modal.querySelector('[data-shot-full]').href = url;
        modal.querySelector('[data-shot-caption]').textContent = label || '';
        bootstrap.Modal.getOrCreateInstance(modal).show();
    }

    function nextCheckText(utc) {
        const d = SW.fmt.parseUtc(utc);
        if (!d) return '';
        const secs = Math.round((d.getTime() - Date.now()) / 1000);
        if (secs <= 0) return 'due now';
        const m = Math.floor(secs / 60), s = secs % 60;
        return 'in ' + (m ? m + ':' + String(s).padStart(2, '0') : s + ' sec');
    }

    // ------------------------------------------------------------------
    // Health score
    // ------------------------------------------------------------------
    function renderScore() {
        const sc = data.score;
        const ring = document.getElementById('ovScore');
        if (!ring) return;
        const t = tone(sc.value);
        ring.className = 'ov-ring tone-' + t;
        ring.querySelector('.value').setAttribute('stroke-dasharray', (sc.value || 0) + ' 100');
        set('score', sc.value === null ? '–' : String(sc.value));
        set('score_word', sc.value === null ? '' : '· ' + (t === 'success' ? 'healthy' : t === 'warning' ? 'needs attention' : 'poor'));
        ring.setAttribute('aria-label', sc.value === null ? 'Health score not available' : 'Health score ' + sc.value + ' out of 100. Show how it is calculated.');
        const parts = document.getElementById('ovScoreParts');
        if (parts) {
            parts.innerHTML = sc.parts.map(function (p) {
                return '<div class="part"><div class="t">' + SW.escape(p.label) + '</div><div class="p">' + p.points + ' <small>/ ' + p.max + '</small></div>' +
                    '<span class="ov-bar"><span class="tone-' + (p.points >= p.max * 0.85 ? 'success' : p.points >= p.max * 0.5 ? 'warning' : 'danger') + '" style="width:' + Math.round(p.points / p.max * 100) + '%"></span></span>' +
                    '<div class="d mt-1">' + SW.escape(p.note) + '</div></div>';
            }).join('') + (sc.parts.length ? '<div class="note">Out of 100. A website that is down right now scores 40 at most.</div>' : '<div class="note">The score appears once the website is monitored and checked.</div>');
        }
    }

    // ------------------------------------------------------------------
    // Key figures
    // ------------------------------------------------------------------
    function bar(key, pct, t) {
        const el = document.querySelector('[data-ov="' + key + '"]');
        if (!el) return;
        el.className = 'tone-' + t;
        requestAnimationFrame(function () { el.style.width = Math.max(0, Math.min(100, pct)) + '%'; });
    }

    function spark(values) {
        const nums = values.filter(function (v) { return v !== null; });
        if (nums.length < 2) return '<span class="fs-12 text-faint">Too few checks for a trend</span>';
        const max = Math.max.apply(null, nums) * 1.1 || 1;
        const w = 100, h = 26, step = w / (values.length - 1);
        const pts = [];
        values.forEach(function (v, i) { if (v !== null) pts.push([i * step, h - (v / max) * h]); });
        const line = pts.map(function (p) { return p[0].toFixed(1) + ',' + p[1].toFixed(1); }).join(' ');
        const area = 'M' + pts[0][0].toFixed(1) + ',' + h + ' L' + line.replace(/ /g, ' L') + ' L' + pts[pts.length - 1][0].toFixed(1) + ',' + h + ' Z';
        return '<svg viewBox="0 0 100 26" preserveAspectRatio="none" aria-hidden="true"><path class="ov-spark-area" d="' + area + '"/><polyline class="ov-spark-line" points="' + line + '"/></svg>';
    }

    function renderTiles() {
        const t = data.tiles;
        const up = t.uptime.value;
        set('uptime', SW.escape(SW.fmt.uptime(up)));
        document.querySelector('[data-ov="uptime"]').className = 'v' + (up !== null && up < 99 ? ' tone-danger' : '');
        bar('uptime_bar', up === null ? 0 : up, up === null ? 'neutral' : up >= 99.9 ? 'success' : up >= 99 ? 'warning' : 'danger');
        set('uptime_sub', (t.uptime.downtime ? SW.escape(t.uptime.downtime_label) + ' down' : 'No downtime') + ' · ' + t.uptime.incidents + (t.uptime.incidents === 1 ? ' incident' : ' incidents'));

        const r = t.response;
        set('response', SW.escape(SW.fmt.ms(r.avg)));
        const rv = document.querySelector('[data-ov="response"]');
        if (rv) rv.className = 'v' + (r.avg !== null && r.avg >= SW.thresholds.critical ? ' tone-danger' : r.avg !== null && r.avg >= SW.thresholds.slow ? ' tone-warning' : '');
        set('response_spark', spark(r.spark || []));
        const sub = [];
        if (r.p95 !== null) sub.push('95% under ' + SW.escape(SW.fmt.ms(r.p95)));
        if (r.change !== null && r.change !== 0) sub.push('<span class="' + (r.change > 0 ? 'worse' : 'better') + '">' + (r.change > 0 ? '+' : '−') + Math.abs(r.change) + '%</span> vs last week');
        set('response_sub', sub.join(' · ') || SW.fmt.num(r.checks) + ' checks');

        const ssl = t.ssl;
        if (!ssl.applicable) {
            set('ssl', SW.escape(ssl.label));
            bar('ssl_bar', 0, 'neutral');
            set('ssl_sub', ssl.label === 'No HTTPS' ? 'The website is served without HTTPS' : 'Certificate checks are off');
        } else {
            const days = ssl.days_remaining;
            set('ssl', ssl.valid === false ? SW.escape(ssl.label) : days !== null ? days + ' days' : 'Valid');
            document.querySelector('[data-ov="ssl"]').className = 'v' + (ssl.tone === 'danger' ? ' tone-danger' : ssl.tone === 'warning' ? ' tone-warning' : '');
            bar('ssl_bar', days === null ? 100 : days / 90 * 100, ssl.tone === 'success' ? 'success' : ssl.tone === 'warning' ? 'warning' : ssl.tone === 'danger' ? 'danger' : 'neutral');
            set('ssl_sub', SW.escape([ssl.issuer, ssl.expires_at ? 'expires ' + ssl.expires_label : null].filter(Boolean).join(' · ') || ssl.error || ''));
        }

        const d = t.domain;
        if (document.querySelector('[data-ov="domain"]')) {
            if (!d) {
                set('domain', '—');
                bar('domain_bar', 0, 'neutral');
                set('domain_sub', 'Registration details not looked up yet');
            } else {
                const days = d.expires_days;
                const label = days === null ? 'Unknown' : days < 0 ? 'Expired' : days >= 60 ? Math.round(days / 30.4) + ' months' : days + ' days';
                set('domain', SW.escape(label));
                document.querySelector('[data-ov="domain"]').className = 'v' + (days !== null && days < 30 ? ' tone-danger' : days !== null && days < 60 ? ' tone-warning' : '');
                bar('domain_bar', days === null ? 0 : days / 365 * 100, days === null ? 'neutral' : days < 30 ? 'danger' : days < 60 ? 'warning' : 'info');
                set('domain_sub', SW.escape([days !== null && days >= 0 ? 'renews ' + d.expires_label : null, d.age_label, d.host].filter(Boolean).join(' · ')));
            }
        }
    }

    // ------------------------------------------------------------------
    // 90 days
    // ------------------------------------------------------------------
    function renderDays() {
        const el = document.getElementById('ovDays');
        if (!el) return;
        el.innerHTML = data.days.map(function (d) {
            const cls = d.total === 0 || d.uptime === null ? 'none' : d.uptime >= 99.9 ? '' : d.uptime >= 95 ? 'warn' : 'down';
            const tip = d.total === 0 ? d.label + ' · no checks recorded'
                : d.label + ' · ' + SW.fmt.uptime(d.uptime) + ' uptime · ' + d.total + ' checks' + (d.incidents ? ' · ' + d.incidents + (d.incidents === 1 ? ' incident' : ' incidents') : '') + (d.avg !== null ? ' · avg ' + SW.fmt.ms(d.avg) : '');
            return '<span class="' + cls + '" title="' + SW.escape(tip) + '"></span>';
        }).join('');
        set('uptime_90', SW.escape(SW.fmt.uptime(data.uptime_90d)));
    }

    // ------------------------------------------------------------------
    // Events
    // ------------------------------------------------------------------
    function renderEvents() {
        const el = document.getElementById('ovEvents');
        if (!el) return;
        if (!data.events.length) {
            el.innerHTML = '<li class="d-block">' + SW.emptyState('bi-clock-history', 'Nothing has happened yet', 'Incidents, WordPress events and changes to this website appear here.') + '</li>';
            return;
        }
        el.innerHTML = data.events.map(function (e) {
            return '<li><span class="ic tone-' + SW.escape(e.tone) + '" aria-hidden="true"><i class="bi ' + SW.escape(e.icon) + '"></i></span>' +
                '<div class="min-w-0"><div class="t">' + SW.escape(e.title) + '</div>' + (e.meta ? '<div class="m">' + SW.escape(e.meta) + '</div>' : '') + '</div>' +
                '<div class="w">' + SW.timeAgoEl(e.at) + '</div></li>';
        }).join('');
    }

    // ------------------------------------------------------------------
    // Country map (called by website-tabs.js with the country results)
    // ------------------------------------------------------------------
    SW.countryMap = function (list) {
        const el = document.getElementById('ovMap');
        const w = window.SW_WORLD;
        if (!el || !w) return;
        const cell = 6, r = 1.7;
        const width = w.cols * cell, height = w.rows.length * cell;
        let dots = '';
        w.rows.forEach(function (row, y) {
            if (!row) return;
            row.split(' ').forEach(function (run) {
                const parts = run.split('.');
                const start = +parts[0], len = +parts[1];
                for (let x = start; x < start + len; x++) dots += 'M' + (x * cell + cell / 2 - r) + ' ' + (y * cell + cell / 2) + 'a' + r + ' ' + r + ' 0 1 0 ' + (2 * r) + ' 0a' + r + ' ' + r + ' 0 1 0 ' + (-2 * r) + ' 0';
            });
        });
        let pins = '';
        (list || []).forEach(function (c) {
            const pos = CENTRES[c.code];
            if (!pos) return;
            const x = (pos[1] + 180) / w.step * cell;
            const y = (w.top - pos[0]) / w.step * cell;
            const t = c.result ? COUNTRY_TONES[c.result] || 'neutral' : 'none';
            if (t === 'danger' || t === 'warning') pins += '<circle class="halo tone-' + t + '" cx="' + x.toFixed(1) + '" cy="' + y.toFixed(1) + '" r="13"/>';
            pins += '<circle class="pin tone-' + t + '" cx="' + x.toFixed(1) + '" cy="' + y.toFixed(1) + '" r="7"><title>' + SW.escape(c.name + ': ' + (c.result || 'not checked')) + '</title></circle>';
        });
        el.innerHTML = '<svg viewBox="0 0 ' + width + ' ' + height + '" role="presentation"><path class="land" d="' + dots + '"/>' + pins + '</svg>';
    };

    // ------------------------------------------------------------------

    function render() {
        renderBanner();
        renderScore();
        renderTiles();
        renderDays();
        renderEvents();
    }

    async function load() {
        const res = await SW.api('api/websites/overview.php', { query: { id: SW.page.id } });
        data = res.data;
        render();
    }

    document.addEventListener('sw:ready', function () {
        if (!document.getElementById('ovBanner')) return;
        SW.countryMap([]);
        load().catch(function (e) { set('state', '<span>' + SW.escape(e.message) + '</span>'); });

        // Screenshots open in a preview window, from the banner and from the Performance tab.
        document.addEventListener('click', function (ev) {
            const t = ev.target.closest ? ev.target : null;
            if (!t) return;
            const open = t.closest('[data-shot-preview]');
            if (open) { ev.preventDefault(); preview(open.getAttribute('data-shot-preview'), open.getAttribute('data-shot-label')); return; }
            const now = t.closest('[data-shot-capture-now]');
            if (now) { capture(now); return; }
            const again = t.closest('[data-shot-recapture]');
            if (again) {
                capture(again).then(function (ok) {
                    if (!ok || !data || !data.banner.screenshot) return;
                    preview(data.banner.screenshot.url, 'Captured ' + data.banner.screenshot.captured_label + (data.banner.screenshot.provider ? ' · ' + data.banner.screenshot.provider : ''));
                    document.dispatchEvent(new CustomEvent('sw:screenshot-captured'));
                });
            }
        });

        const ring = document.getElementById('ovScore');
        ring.addEventListener('click', function () {
            const parts = document.getElementById('ovScoreParts');
            parts.hidden = !parts.hidden;
            ring.setAttribute('aria-expanded', String(!parts.hidden));
        });

        // "Next check in 2:48" counts down; the page reloads the data when it runs out.
        setInterval(function () {
            SW.qsa('[data-next-check]').forEach(function (el) { el.textContent = 'Next check ' + nextCheckText(el.getAttribute('data-next-check')); });
        }, 1000);
        SW.poll(load, Math.max(20000, (SW.config.refresh || 30) * 1000));
        document.addEventListener('sw:website-checked', function () { load().catch(function () {}); });
    });
})();
