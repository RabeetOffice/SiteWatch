/* SiteWatch — website details page */
(function () {
    'use strict';

    const id = SW.page.id;
    let website = SW.page.website;
    let rtChart = null;
    let rtRange = SW.storage.get('sw-rt-range', '24h');
    let rtData = null;
    let checksPage = 1;
    let checksOnly = '';
    let checkBusy = false;

    function setStat(key, html) { const el = document.querySelector('[data-stat="' + key + '"]'); if (el) el.innerHTML = html; }

    function renderHeader(w) {
        website = w;
        const statusEl = document.getElementById('siteStatus');
        if (statusEl) statusEl.innerHTML = '<span class="sw-badge lg sev-' + w.severity + (w.severity === 'down' ? ' live' : '') + '">' + SW.escape(w.status_label) + '</span>';
        const last = document.getElementById('siteLastChecked');
        if (last) last.innerHTML = '<i class="bi bi-arrow-repeat"></i> Last checked ' + (w.last_checked_at ? SW.timeAgoEl(w.last_checked_at) : 'never');
        const err = document.getElementById('siteError');
        if (err) err.innerHTML = (w.last_error_message && w.severity !== 'ok') ? '<i class="bi bi-info-circle"></i> ' + SW.escape(w.last_error_message) : '';
        setStat('status', SW.escape(w.status_label));
        setStat('status_sub', (w.last_http_status ? 'HTTP ' + w.last_http_status : '') + (w.last_response_time !== null ? (w.last_http_status ? ' · ' : '') + SW.fmt.ms(w.last_response_time) : '') || '&nbsp;');
        setStat('ssl', SW.escape(w.ssl.label));
        setStat('ssl_sub', SW.escape(w.ssl.applicable && w.ssl.expires_at ? 'expires ' + w.ssl.expires_label : (w.ssl.error || '')) + '&nbsp;');
        const pause = document.getElementById('btnPause');
        if (pause) { pause.setAttribute('data-enabled', w.monitoring_enabled ? '1' : '0'); pause.innerHTML = '<i class="bi ' + (w.monitoring_enabled ? 'bi-pause-circle' : 'bi-play-circle') + '"></i> ' + (w.monitoring_enabled ? 'Pause Monitoring' : 'Resume Monitoring'); }
    }

    function renderStats(stats) {
        setStat('uptime_24h', SW.escape(SW.fmt.uptime(stats.uptime_24h)));
        setStat('uptime_24h_sub', stats.checks_24h + ' checks');
        setStat('uptime_7d', SW.escape(SW.fmt.uptime(stats.uptime_7d)));
        setStat('uptime_30d', SW.escape(SW.fmt.uptime(stats.uptime_30d)));
        setStat('uptime_90d_sub', '90d: ' + SW.escape(SW.fmt.uptime(stats.uptime_90d)) + ' · all: ' + SW.escape(SW.fmt.uptime(stats.uptime_all)));
        setStat('avg_24h', SW.escape(SW.fmt.ms(stats.avg_24h)));
        setStat('avg_sub', stats.min_24h !== null ? 'min ' + SW.fmt.ms(stats.min_24h) + ' · max ' + SW.fmt.ms(stats.max_24h) : 'last 24 hours');
        setStat('incidents_month', SW.escape(stats.incidents_month));
        setStat('incidents_sub', stats.incidents_30d + ' in the last 30 days');
    }

    function renderIncidents(list) {
        const el = document.getElementById('siteIncidents');
        if (!el) return;
        if (!list.length) { el.innerHTML = SW.emptyState('bi-shield-check', 'No incidents recorded.', 'This website has not had a confirmed outage.'); return; }
        el.innerHTML = list.map(function (i) {
            return '<div class="incident-row ' + (i.is_open ? 'open' : '') + '"><span class="marker"></span><div class="body">' +
                '<div class="title">' + SW.escape(i.title) + (i.is_open ? SW.badge('OPEN', 'Open', 'down', 'no-dot') : '') + '</div>' +
                '<div class="meta">' + SW.escape(i.started_label) + ' · ' + (i.is_open ? 'ongoing ' : '') + SW.escape(i.duration_label) + (i.http_status ? ' · HTTP ' + i.http_status : '') + '</div>' +
                (i.error_message ? '<div class="msg">' + SW.escape(i.error_message) + '</div>' : '') + '</div></div>';
        }).join('');
    }

    async function loadShow() {
        const res = await SW.api('api/websites/show.php', { query: { id: id } });
        renderHeader(res.data.website);
        renderStats(res.data.stats);
        renderIncidents(res.data.incidents);
    }

    function renderChecks(data) {
        const body = document.getElementById('checksBody');
        if (!body) return;
        if (!data.rows.length) { body.innerHTML = '<tr><td colspan="6">' + SW.emptyState('bi-clock-history', checksOnly ? 'No failed checks.' : 'No checks yet.', checksOnly ? 'Every recorded check succeeded.' : 'Results appear after the first monitoring run.') + '</td></tr>'; }
        else {
            body.innerHTML = data.rows.map(function (c) {
                return '<tr><td class="nowrap fs-13" title="' + SW.escape(c.checked_at) + ' UTC">' + SW.escape(c.checked_label) + '<div class="fs-12 text-muted">' + SW.escape(SW.fmt.timeAgo(c.checked_at)) + '</div></td>' +
                    '<td>' + SW.badge(c.status, c.status_label, c.severity) + (c.is_failure && c.is_up ? '<div class="fs-12 text-muted">unconfirmed</div>' : '') + '</td>' +
                    '<td>' + SW.httpCode(c.http_status) + '</td><td>' + SW.responseTime(c.response_time) + '</td>' +
                    '<td class="fs-13" style="max-width:320px"><span class="d-inline-block truncate" style="max-width:320px" title="' + SW.escape(c.error_message || '') + '">' + (c.error_message ? SW.escape(c.error_message) : '<span class="text-faint">—</span>') + '</span>' + (c.redirect_count ? '<div class="fs-12 text-muted">' + c.redirect_count + ' redirect' + (c.redirect_count === 1 ? '' : 's') + ' → ' + SW.escape(c.final_url || '') + '</div>' : '') + '</td>' +
                    '<td class="hide-mobile">' + SW.pill(c.source === 'manual' ? 'Manual' : 'Cron', 'neutral') + '</td></tr>';
            }).join('');
        }
        SW.pagination(document.getElementById('checksPagination'), data.page, data.per_page, data.total, function (p) { checksPage = p; loadChecks(); });
    }

    async function loadChecks() {
        const body = document.getElementById('checksBody');
        if (body && !body.children.length) body.innerHTML = SW.skeletonRows(6, 6);
        const res = await SW.api('api/websites/checks.php', { query: { id: id, page: checksPage, per_page: 15, only: checksOnly } });
        renderChecks(res.data);
    }

    function renderTimeline(data) {
        const checks = document.getElementById('timelineChecks');
        const labels = document.getElementById('timelineChecksLabels');
        if (checks) {
            if (!data.checks.length) { checks.innerHTML = '<div class="text-muted fs-13 align-self-center">No checks yet.</div>'; }
            else {
                checks.innerHTML = data.checks.map(function (c) {
                    const cls = c.severity === 'ok' ? 'ok' : (c.severity === 'down' && c.is_up === false ? 'down' : (c.severity === 'down' ? 'warning' : c.severity === 'warning' ? 'warning' : 'neutral'));
                    const tip = SW.escape(SW.fmt.date(c.checked_at, true)) + '<br>' + SW.escape(c.status_label + (c.http_status ? ' · HTTP ' + c.http_status : '') + (c.response_time !== null ? ' · ' + SW.fmt.ms(c.response_time) : ''));
                    return '<span class="seg ' + cls + '" data-bs-toggle="tooltip" data-bs-html="true" title="' + tip + '"></span>';
                }).join('');
                labels.innerHTML = '<span>' + SW.escape(SW.fmt.timeAgo(data.checks[0].checked_at)) + '</span><span>' + data.checks.length + ' checks</span><span>' + SW.escape(SW.fmt.timeAgo(data.checks[data.checks.length - 1].checked_at)) + '</span>';
            }
        }
        const days = document.getElementById('timelineDays');
        const dayLabels = document.getElementById('timelineDaysLabels');
        if (days) {
            days.innerHTML = data.days.map(function (d) {
                let cls = 'neutral';
                if (d.total > 0) cls = d.uptime >= 99.9 ? 'ok' : (d.uptime >= 95 ? 'warning' : 'down');
                const tip = d.total > 0
                    ? SW.escape(d.label) + '<br>' + SW.escape('Uptime ' + SW.fmt.uptime(d.uptime) + ' · ' + d.total + ' checks' + (d.incidents ? ' · ' + d.incidents + ' incident' + (d.incidents === 1 ? '' : 's') : '')) + (d.avg !== null ? '<br>' + SW.escape('Avg ' + SW.fmt.ms(d.avg)) : '')
                    : SW.escape(d.label) + '<br>No data';
                return '<span class="seg ' + cls + '" data-bs-toggle="tooltip" data-bs-html="true" title="' + tip + '"></span>';
            }).join('');
            if (data.days.length) dayLabels.innerHTML = '<span>' + SW.escape(data.days[0].label) + '</span><span>Today</span>';
        }
        SW.tooltips(document.getElementById('timelineChecks'));
        SW.tooltips(document.getElementById('timelineDays'));
    }

    async function loadTimeline() {
        const res = await SW.api('api/websites/timeline.php', { query: { id: id } });
        renderTimeline(res.data);
    }

    function renderRt() {
        if (!rtData || typeof Chart === 'undefined') return;
        const c = SW.chartColors();
        const el = document.getElementById('chartRt');
        if (rtChart) { rtChart.destroy(); rtChart = null; }
        const summary = document.getElementById('rtSummary');
        if (summary) summary.textContent = rtData.summary.checks ? 'Avg ' + SW.fmt.ms(rtData.summary.avg) + ' · min ' + SW.fmt.ms(rtData.summary.min) + ' · max ' + SW.fmt.ms(rtData.summary.max) + ' · ' + rtData.summary.checks + ' checks' : 'No data for this range yet';
        const datasets = [{ label: 'Average', data: rtData.values, borderColor: c.primary, backgroundColor: c.primarySoft, fill: true, tension: 0.35, pointRadius: rtData.values.length > 80 ? 0 : 2, pointHitRadius: 12, borderWidth: 2, spanGaps: true }];
        if (rtData.max && rtData.max.some(function (v) { return v !== null; })) datasets.push({ label: 'Max', data: rtData.max, borderColor: c.warning, borderDash: [4, 4], fill: false, tension: 0.35, pointRadius: 0, borderWidth: 1.5, spanGaps: true });
        const thresholds = SW.thresholds;
        rtChart = new Chart(el, {
            type: 'line',
            data: { labels: rtData.labels, datasets: datasets },
            options: {
                responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
                scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } }, y: { beginAtZero: true, suggestedMax: Math.max(1000, thresholds.slow * 1.1), grid: { color: c.grid }, ticks: { callback: function (v) { return SW.fmt.ms(v); }, maxTicksLimit: 6 } } },
                plugins: {
                    legend: { display: datasets.length > 1, position: 'top', align: 'end', labels: { boxWidth: 10, usePointStyle: true, pointStyle: 'line' } },
                    tooltip: { callbacks: { title: function (items) { return rtData.timestamps[items[0].dataIndex]; }, label: function (item) { return ' ' + item.dataset.label + ': ' + SW.fmt.ms(item.raw); }, afterBody: function (items) { const d = rtData.down ? rtData.down[items[0].dataIndex] : 0; return d ? [d + ' failed check' + (d === 1 ? '' : 's')] : []; } } },
                    annotationLine: { y: thresholds.slow },
                },
            },
            plugins: [{
                id: 'annotationLine',
                afterDraw: function (chart, args, opts) {
                    const y = chart.scales.y; if (!y || !opts.y || opts.y > y.max) return;
                    const ctx = chart.ctx; const py = y.getPixelForValue(opts.y);
                    ctx.save(); ctx.strokeStyle = c.warning; ctx.setLineDash([3, 4]); ctx.lineWidth = 1; ctx.beginPath(); ctx.moveTo(chart.chartArea.left, py); ctx.lineTo(chart.chartArea.right, py); ctx.stroke();
                    ctx.fillStyle = c.warning; ctx.font = '10px ' + Chart.defaults.font.family; ctx.fillText('slow threshold', chart.chartArea.left + 4, py - 4); ctx.restore();
                },
            }],
        });
    }

    async function loadRt() {
        const res = await SW.api('api/websites/response-times.php', { query: { id: id, range: rtRange } });
        rtData = res.data;
        renderRt();
    }

    async function checkNow() {
        if (checkBusy) return;
        checkBusy = true;
        const btn = document.getElementById('btnCheckNow');
        SW.setLoading(btn, true, 'Checking…');
        try {
            const res = await SW.api('api/websites/check.php', { method: 'POST', body: { id: id } });
            const r = res.data.result;
            renderHeader(res.data.website);
            SW.toast('Check completed: ' + r.status_label + (r.http_status ? ' · HTTP ' + r.http_status : '') + (r.response_time !== null ? ' · ' + SW.fmt.ms(r.response_time) : ''), r.severity === 'down' ? 'danger' : r.severity === 'warning' ? 'warning' : 'success');
            if (res.data.incident_opened) SW.toast('Incident opened.', 'danger');
            if (res.data.incident_resolved) SW.toast('Website recovered — incident resolved.', 'success');
            checksPage = 1;
            await Promise.all([loadShow(), loadChecks(), loadTimeline(), loadRt()]);
        } catch (e) { SW.toast(e.message, 'danger'); }
        finally { checkBusy = false; SW.setLoading(btn, false); }
    }

    document.addEventListener('sw:ready', function () {
        if (!id) return;
        SW.qsa('#rtRange button').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-range') === rtRange); b.addEventListener('click', function () { rtRange = b.getAttribute('data-range'); SW.storage.set('sw-rt-range', rtRange); SW.qsa('#rtRange button').forEach(function (x) { x.classList.toggle('active', x === b); }); loadRt().catch(function (e) { SW.toast(e.message, 'danger'); }); }); });
        SW.qsa('#checksFilter button').forEach(function (b) { b.addEventListener('click', function () { checksOnly = b.getAttribute('data-only'); checksPage = 1; SW.qsa('#checksFilter button').forEach(function (x) { x.classList.toggle('active', x === b); }); loadChecks().catch(function (e) { SW.toast(e.message, 'danger'); }); }); });
        document.getElementById('btnCheckNow').addEventListener('click', checkNow);
        document.getElementById('btnPause').addEventListener('click', async function () {
            const btn = this; const enabled = btn.getAttribute('data-enabled') === '1';
            SW.setLoading(btn, true);
            try { const res = await SW.api('api/websites/pause.php', { method: 'POST', body: { id: id, action: enabled ? 'pause' : 'resume' } }); SW.setLoading(btn, false); renderHeader(res.data.website); SW.toast(res.message, 'success'); }
            catch (e) { SW.setLoading(btn, false); SW.toast(e.message, 'danger'); }
        });
        document.getElementById('btnDelete').addEventListener('click', async function () {
            const ok = await SW.confirm({ title: 'Delete ' + website.name + '?', message: 'All checks, incidents and statistics for this website will be permanently removed.', confirmText: 'Delete website' });
            if (!ok) return;
            try { await SW.api('api/websites/delete.php', { method: 'POST', body: { id: id } }); SW.toast('Website deleted.', 'success'); setTimeout(function () { window.location.href = SW.url('admin/websites.php'); }, 500); }
            catch (e) { SW.toast(e.message, 'danger'); }
        });

        Promise.all([loadShow(), loadChecks(), loadTimeline(), loadRt()]).catch(function (e) { SW.toast(e.message, 'danger'); });
        SW.poll(async function () { await loadShow(); if (checksPage === 1) await loadChecks(); await loadTimeline(); }, Math.max(15000, (SW.config.refresh || 30) * 1000));
        document.addEventListener('sw:theme', renderRt);
    });
})();
