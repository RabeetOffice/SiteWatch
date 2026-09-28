/* ==========================================================================
   SiteWatch service worker
   - Keeps the app shell (styles, scripts, fonts, icons) for an instant start.
   - Pages and API data always come from the network; monitoring data is never shown stale as current.
     When the network is down, navigations get a small offline page instead.
   - Shows Web Push notifications and opens the right page when one is clicked.
   ========================================================================== */
'use strict';

const VERSION = '2.0.0';
const SHELL = 'sitewatch-shell-' + VERSION;
const SCOPE = self.registration.scope; // e.g. https://example.com/ or http://localhost/sitewatch/
const OFFLINE_URL = SCOPE + 'offline.html';

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(SHELL)
            .then(function (cache) { return cache.addAll([OFFLINE_URL, SCOPE + 'assets/images/icons/icon-192.png', SCOPE + 'assets/images/favicon.svg']); })
            .then(function () { return self.skipWaiting(); })
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys()
            .then(function (keys) { return Promise.all(keys.filter(function (k) { return k.indexOf('sitewatch-') === 0 && k !== SHELL; }).map(function (k) { return caches.delete(k); })); })
            .then(function () { return self.clients.claim(); })
    );
});

self.addEventListener('fetch', function (event) {
    const req = event.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== self.location.origin || url.href.indexOf(SCOPE) !== 0) return;

    if (req.mode === 'navigate') {
        event.respondWith(fetch(req).catch(function () { return caches.match(OFFLINE_URL); }));
        return;
    }

    // Static assets are versioned in their URL (?v=…), so the exact URL can be served from the cache.
    if (url.pathname.indexOf('/assets/') !== -1 && url.pathname.indexOf('/storage/') === -1) {
        event.respondWith(
            caches.open(SHELL).then(function (cache) {
                return cache.match(req).then(function (hit) {
                    if (hit) return hit;
                    return fetch(req).then(function (res) {
                        if (res.ok && res.type === 'basic') cache.put(req, res.clone());
                        return res;
                    });
                });
            })
        );
    }
    // Everything else (pages fetched in the background, API calls, screenshots) goes straight to the network.
});

// ---------------------------------------------------------------------------
// Web Push
// ---------------------------------------------------------------------------
self.addEventListener('push', function (event) {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch (e) { data = { title: 'SiteWatch', body: event.data ? event.data.text() : '' }; }
    const title = data.title || 'SiteWatch';
    const options = {
        body: data.body || '',
        tag: data.tag || undefined,
        renotify: !!data.tag,
        requireInteraction: !!data.sticky,
        icon: SCOPE + 'assets/images/icons/icon-192.png',
        badge: SCOPE + 'assets/images/icons/badge-96.png',
        timestamp: data.time ? Date.parse(data.time) : Date.now(),
        data: { url: data.url ? new URL(data.url, SCOPE).href : SCOPE + 'admin/dashboard.php' },
    };
    const tasks = [self.registration.showNotification(title, options)];
    if (typeof data.open_incidents === 'number' && self.navigator.setAppBadge) {
        tasks.push(data.open_incidents > 0 ? self.navigator.setAppBadge(data.open_incidents) : self.navigator.clearAppBadge());
    }
    event.waitUntil(Promise.all(tasks).catch(function () {}));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    const target = (event.notification.data && event.notification.data.url) || SCOPE + 'admin/dashboard.php';
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
            for (let i = 0; i < list.length; i++) {
                const client = list[i];
                if (client.url.indexOf(SCOPE) === 0 && 'focus' in client) {
                    client.postMessage({ type: 'sw-open', url: target });
                    return client.focus();
                }
            }
            return self.clients.openWindow(target);
        })
    );
});
