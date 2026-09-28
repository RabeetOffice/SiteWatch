/* SiteWatch — Profile › This device: install the app and turn desktop notifications on or off */
(function () {
    'use strict';

    let info = null;

    function supported() {
        return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && window.isSecureContext;
    }

    /** "Chrome on Windows", "Safari on macOS"… so people recognise their devices in the list. */
    function deviceLabel() {
        const ua = navigator.userAgent;
        const browser = /Edg\//.test(ua) ? 'Edge' : /OPR\//.test(ua) ? 'Opera' : /Firefox\//.test(ua) ? 'Firefox' : /Chrome\//.test(ua) ? 'Chrome' : /Safari\//.test(ua) ? 'Safari' : 'Browser';
        const os = /Windows/.test(ua) ? 'Windows' : /Mac OS X|Macintosh/.test(ua) ? 'macOS' : /Android/.test(ua) ? 'Android' : /iPhone|iPad/.test(ua) ? 'iOS' : /Linux/.test(ua) ? 'Linux' : 'unknown system';
        return browser + ' on ' + os + (SW.pwa && SW.pwa.standalone() ? ' (app)' : '');
    }

    function keyBytes(base64) {
        const padding = '='.repeat((4 - (base64.length % 4)) % 4);
        const raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'));
        const out = new Uint8Array(raw.length);
        for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
        return out;
    }

    /** First 12 hex characters of the endpoint's SHA-256, as the server lists devices. */
    async function endpointHash(endpoint) {
        const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(endpoint));
        return Array.prototype.map.call(new Uint8Array(digest), function (b) { return b.toString(16).padStart(2, '0'); }).join('').slice(0, 12);
    }

    async function registration() {
        if (SW.pwa && SW.pwa.registration) return SW.pwa.registration;
        return navigator.serviceWorker.ready;
    }

    async function currentSubscription() {
        const reg = await registration();
        return reg.pushManager.getSubscription();
    }

    function chosenEvents() {
        return SW.qsa('[data-push-events] input:checked').map(function (i) { return i.value; });
    }

    function setStatus(text, tone) {
        const el = document.querySelector('[data-push-status]');
        if (!el) return;
        el.textContent = text;
        el.className = 'fs-13 ' + (tone ? 'text-' + tone : 'text-muted');
    }

    function renderDevices() {
        const list = document.querySelector('[data-push-devices]');
        const card = document.querySelector('[data-push-devices-card]');
        if (!list || !card) return;
        card.hidden = !info.devices.length;
        list.innerHTML = info.devices.map(function (d) {
            return '<li><i class="bi bi-window-desktop text-muted" aria-hidden="true"></i><div class="min-w-0 flex-grow-1"><div class="fw-500">' + SW.escape(d.device) + '</div>' +
                '<div class="d">Turned on ' + SW.escape(SW.fmt.date(d.created_at)) + (d.last_used_at ? ' · last notification ' + SW.timeAgoEl(d.last_used_at) : '') +
                (d.last_error ? ' · <span class="text-danger">' + SW.escape(d.last_error) + '</span>' : '') + '</div></div>' +
                '<button type="button" class="btn btn-sm btn-ghost" data-remove-device="' + d.id + '" aria-label="Turn off notifications for ' + SW.escape(d.device) + '"><i class="bi bi-x-lg"></i></button></li>';
        }).join('');
    }

    function renderEvents(selected) {
        const wrap = document.querySelector('[data-push-events]');
        if (!wrap) return;
        wrap.innerHTML = Object.keys(info.events).map(function (key) {
            const on = !selected || selected.indexOf(key) !== -1;
            return '<label class="form-check"><input class="form-check-input" type="checkbox" value="' + SW.escape(key) + '"' + (on ? ' checked' : '') + '>' +
                '<span class="form-check-label">' + SW.escape(info.events[key]) + '</span></label>';
        }).join('');
    }

    async function subscribe() {
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
            throw new Error(permission === 'denied'
                ? 'Notifications are blocked for this site. Allow them in the browser\'s site settings (the icon left of the address), then try again.'
                : 'Notifications were not allowed.');
        }
        const reg = await registration();
        let sub = await reg.pushManager.getSubscription();
        if (!sub) sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(info.public_key) });
        const json = sub.toJSON();
        const res = await SW.api('api/push/device.php', { method: 'POST', body: {
            action: 'subscribe', endpoint: json.endpoint, keys: json.keys, device: deviceLabel(), events: chosenEvents(),
            encoding: (window.PushManager && PushManager.supportedContentEncodings || ['aes128gcm'])[0],
        } });
        info.devices = res.data.devices;
        SW.storage.set('sw-push', true);
        return res;
    }

    async function unsubscribe() {
        const sub = await currentSubscription();
        const endpoint = sub ? sub.endpoint : '';
        if (sub) await sub.unsubscribe();
        const res = await SW.api('api/push/device.php', { method: 'POST', body: { action: 'unsubscribe', endpoint: endpoint } });
        info.devices = res.data.devices;
        SW.storage.set('sw-push', false);
        return res;
    }

    async function refreshState() {
        const toggle = document.getElementById('pushToggle');
        const options = document.querySelector('[data-push-options]');
        if (!supported()) {
            setStatus('This browser cannot show desktop notifications' + (window.isSecureContext ? '.' : ' on an address without HTTPS.'), 'warning');
            return;
        }
        if (!info.allowed) {
            setStatus('Your role cannot see incidents, so there is nothing to notify you about.');
            return;
        }
        if (!info.public_key) {
            setStatus(info.error || 'Desktop notifications are not available on this server.', 'warning');
            return;
        }
        const sub = await currentSubscription();
        const hash = sub ? await endpointHash(sub.endpoint) : null;
        const mine = hash ? info.devices.find(function (d) { return d.endpoint_end === hash; }) : null;
        const on = !!sub && Notification.permission === 'granted';
        toggle.disabled = false;
        toggle.checked = on;
        options.hidden = !on;
        SW.storage.set('sw-push', on);
        SW.storage.set('sw-notify', on);
        if (Notification.permission === 'denied') {
            setStatus('Blocked in this browser\'s site settings. Allow notifications for this site to turn them on.', 'warning');
            toggle.disabled = true;
        } else {
            setStatus(on ? 'On for this device.' : 'Off for this device.', on ? 'success' : null);
        }
        renderEvents(mine ? mine.events : null);
    }

    document.addEventListener('sw:ready', async function () {
        const toggle = document.getElementById('pushToggle');
        if (!toggle) return;
        if (SW.pwa) SW.pwa.syncInstall();
        try {
            info = (await SW.api('api/push/device.php')).data;
        } catch (e) {
            setStatus('Could not load the notification settings: ' + e.message, 'danger');
            return;
        }
        renderDevices();
        refreshState().catch(function (e) { setStatus(e.message, 'danger'); });

        toggle.addEventListener('change', async function () {
            toggle.disabled = true;
            try {
                const res = toggle.checked ? await subscribe() : await unsubscribe();
                SW.toast(res.message, 'success');
            } catch (e) {
                SW.toast(e.message, 'danger', { delay: 9000 });
            } finally {
                renderDevices();
                await refreshState().catch(function () {});
            }
        });

        document.addEventListener('change', async function (ev) {
            if (!ev.target.closest || !ev.target.closest('[data-push-events]')) return;
            try { await subscribe(); SW.toast('Notification choices saved for this device.', 'success'); } catch (e) { SW.toast(e.message, 'danger'); }
        });

        document.addEventListener('click', async function (ev) {
            const test = ev.target.closest ? ev.target.closest('[data-push-test]') : null;
            if (test) {
                SW.setLoading(test, true, 'Sending…');
                try { SW.toast((await SW.api('api/push/device.php', { method: 'POST', body: { action: 'test' } })).message, 'success', { delay: 7000 }); }
                catch (e) { SW.toast(e.message, 'danger', { delay: 9000 }); }
                finally { SW.setLoading(test, false); }
                return;
            }
            const remove = ev.target.closest ? ev.target.closest('[data-remove-device]') : null;
            if (remove) {
                try {
                    const res = await SW.api('api/push/device.php', { method: 'POST', body: { action: 'unsubscribe', id: parseInt(remove.getAttribute('data-remove-device'), 10) } });
                    info.devices = res.data.devices;
                    renderDevices();
                    await refreshState();
                } catch (e) { SW.toast(e.message, 'danger'); }
            }
        });
    });
})();
