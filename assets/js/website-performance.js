/* SiteWatch — Core Web Vitals and screenshot panels on the website details page */
(function () {
    'use strict';

    const RATING_TONE = { good: 'success', 'needs-improvement': 'warning', poor: 'danger' };
    const LAB_METRICS = [
        ['lcp', 'LCP', 'Largest Contentful Paint — when the main content finished rendering. Good is under 2.5 s.'],
        ['cls', 'CLS', 'Cumulative Layout Shift — how much the page moves while loading. Good is under 0.1.'],
        ['tbt', 'TBT', 'Total Blocking Time — the lab stand-in for INP, which needs real visitors to measure.'],
        ['ttfb', 'TTFB', 'Time to First Byte in the Lighthouse run. Good is under 0.8 s.'],
        ['fcp', 'FCP', 'First Contentful Paint — when anything first appeared. Good is under 1.8 s.'],
    ];
    const FIELD_METRICS = [
        ['lcp', 'LCP', 'Largest Contentful Paint, 75th percentile of real visitors.'],
        ['inp', 'INP', 'Interaction to Next Paint, 75th percentile. Good is under 200 ms.'],
        ['cls', 'CLS', 'Cumulative Layout Shift, 75th percentile.'],
        ['ttfb', 'TTFB', 'Time to First Byte, 75th percentile of real visitors.'],
    ];

    const id = SW.page.id;
    const conf = SW.page.performance || {};
    let strategy = SW.storage.get('sw-cwv-strategy', 'mobile');
    let summary = null;

    function tile(label, cell, help) {
        const value = cell && cell.value !== null && cell.value !== undefined ? cell.display : '—';
        const tone = cell ? RATING_TONE[cell.rating] : null;
        return '<div class="kv-tile" title="' + SW.escape(help) + '">' +
            '<div class="kv-tile-label">' + SW.escape(label) + '</div>' +
            '<div class="kv-tile-value' + (tone ? ' text-' + tone : '') + '">' + SW.escape(value) + '</div>' +
            '</div>';
    }

    function settingsHint(what) {
        if (!conf.canManageSettings) {
            return '<p class="fs-13 mb-0">Ask an administrator to enable it under Monitoring Settings.</p>';
        }
        return '<a class="btn btn-sm btn-primary" href="' + SW.url('admin/settings.php', { section: 'monitoring' }) + '">' +
            '<i class="bi bi-sliders"></i> Set up ' + SW.escape(what) + '</a>';
    }

    function renderVitals() {
        const body = document.querySelector('[data-cwv-body]');
        const sub = document.querySelector('[data-cwv-sub]');
        if (!body) return;

        if (!conf.vitalsEnabled) {
            body.innerHTML = SW.emptyState(
                'bi-lightning-charge',
                'Core Web Vitals are switched off',
                'LCP, CLS and INP need a real browser, so Google PageSpeed Insights measures them. Enable it to start collecting results.',
                settingsHint('Core Web Vitals')
            );
            return;
        }

        const run = summary && summary.runs ? summary.runs[strategy] : null;
        if (!run) {
            body.innerHTML = SW.emptyState(
                'bi-hourglass-split',
                'Not measured yet',
                summary && summary.last_error
                    ? 'Last attempt failed: ' + summary.last_error
                    : 'Results appear after cron/vitals-check.php runs' + (conf.canRun ? ', or use "Measure now".' : '.')
            );
            if (sub) sub.textContent = 'Measured by Google PageSpeed Insights';
            return;
        }

        if (sub) {
            sub.textContent = 'Lighthouse score ' + (run.score === null ? '—' : run.score) + ' · measured ' + run.fetched_label;
        }

        let html = '<div class="d-flex align-items-center gap-3 mb-3">' +
            '<div class="cwv-score tone-' + (RATING_TONE[run.score_rating] || 'neutral') + '">' +
            (run.score === null ? '—' : SW.escape(String(run.score))) + '</div>' +
            '<div><div class="fw-600">Performance score</div>' +
            '<div class="fs-13 text-muted">Lighthouse, ' + SW.escape(strategy) + ' · ' + SW.escape(run.fetched_label) + '</div></div></div>';

        html += '<div class="form-label">Lab results <span class="text-muted fw-normal fs-12">— a throttled Lighthouse render, reproducible</span></div>';
        html += '<div class="kv-tiles mb-3">' + LAB_METRICS.map(function (m) {
            return tile(m[1], run.lab[m[0]], m[2]);
        }).join('') + '</div>';

        html += '<div class="form-label">Field results <span class="text-muted fw-normal fs-12">— real Chrome visitors over 28 days, the only source of INP</span></div>';
        if (run.has_field) {
            html += '<div class="kv-tiles">' + FIELD_METRICS.map(function (m) {
                return tile(m[1], run.field[m[0]], m[2]);
            }).join('') + '</div>';
            html += '<p class="fs-12 text-muted mb-0 mt-2">' +
                (run.field_source === 'origin'
                    ? 'This URL does not have enough traffic on its own, so these are for the whole origin.'
                    : 'Measured for this exact URL.') +
                (run.field_verdict ? ' Chrome rates it <b>' + SW.escape(run.field_verdict.toLowerCase()) + '</b>.' : '') + '</p>';
        } else {
            html += '<p class="fs-13 text-muted mb-0">' +
                'No real-user data yet. Chrome only reports it once a site has enough traffic, so INP is unavailable ' +
                'for this website — the Total Blocking Time above is the closest lab equivalent.</p>';
        }

        body.innerHTML = html;
    }

    function renderScreenshot(shot) {
        const body = document.querySelector('[data-shot-body]');
        const sub = document.querySelector('[data-shot-sub]');
        if (!body) return;

        if (!conf.screenshotsEnabled) {
            body.innerHTML = SW.emptyState(
                'bi-camera',
                'Screenshots are switched off',
                'A free external service renders each page. Enable it to see how every website looks.',
                settingsHint('screenshots')
            );
            return;
        }
        if (!shot) {
            body.innerHTML = SW.emptyState(
                'bi-image',
                'No screenshot yet',
                'One is captured on the screenshot interval' + (conf.canRun ? ', or use "Capture now".' : '.')
            );
            if (sub) sub.textContent = 'How this website looks right now';
            return;
        }

        if (sub) sub.textContent = 'Captured ' + shot.captured_label + ' · ' + shot.provider;
        // The id changes with every capture, so the browser always fetches the new image.
        const src = SW.url('api/websites/screenshot.php', { id: shot.id });
        // Not lazy-loaded: this is the one image the panel exists to show, and a lazy image inserted into
        // a frame that has no height until it loads can sit unloaded indefinitely.
        body.innerHTML = '<a href="' + src + '" target="_blank" rel="noopener" class="shot-frame">' +
            '<img src="' + src + '" alt="Screenshot of this website, captured ' + SW.escape(shot.captured_label) + '" decoding="async">' +
            '</a>';
    }

    async function loadVitals() {
        if (!conf.vitalsEnabled) { renderVitals(); return; }
        try {
            const res = await SW.api('api/performance/vitals.php', { query: { website_id: id, strategy: strategy } });
            summary = res.data.summary;
        } catch (e) {
            summary = null;
        }
        renderVitals();
    }

    async function loadScreenshot() {
        if (!conf.screenshotsEnabled) { renderScreenshot(null); return; }
        try {
            const res = await SW.api('api/performance/screenshots.php', { query: { website_id: id } });
            renderScreenshot(res.data.latest);
        } catch (e) {
            renderScreenshot(null);
        }
    }

    async function run(action, btn, label) {
        SW.setLoading(btn, true, label);
        try {
            const res = await SW.api('api/performance/run.php', { method: 'POST', body: { website_id: id, action: action } });
            SW.toast(res.message, 'success');
            if (action === 'screenshot') { await loadScreenshot(); } else { summary = res.data.summary; renderVitals(); }
        } catch (e) {
            SW.toast(e.message, 'danger', { delay: 9000, title: action === 'screenshot' ? 'Capture failed' : 'Measurement failed' });
        } finally {
            SW.setLoading(btn, false);
        }
    }

    document.addEventListener('sw:ready', function () {
        if (!document.querySelector('[data-cwv-body]')) return;

        SW.qsa('[data-cwv-strategy] button').forEach(function (btn) {
            btn.classList.toggle('active', btn.getAttribute('data-strategy') === strategy);
            btn.addEventListener('click', function () {
                strategy = btn.getAttribute('data-strategy');
                SW.storage.set('sw-cwv-strategy', strategy);
                SW.qsa('[data-cwv-strategy] button').forEach(function (b) { b.classList.toggle('active', b === btn); });
                renderVitals();
            });
        });

        const runBtn = document.querySelector('[data-cwv-run]');
        if (runBtn) runBtn.addEventListener('click', function () { run('vitals', runBtn, 'Measuring…'); });

        const shotBtn = document.querySelector('[data-shot-capture]');
        if (shotBtn) shotBtn.addEventListener('click', function () { run('screenshot', shotBtn, 'Capturing…'); });

        loadVitals();
        loadScreenshot();
    });
})();
