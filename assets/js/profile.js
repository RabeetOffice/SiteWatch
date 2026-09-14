/* SiteWatch — profile page */
(function () {
    'use strict';

    async function submit(form, action) {
        const btn = form.querySelector('button[type="submit"]');
        SW.showErrors(form, {});
        const body = { action: action };
        new FormData(form).forEach(function (v, k) { body[k] = v; });
        SW.setLoading(btn, true, 'Saving…');
        try {
            const res = await SW.api('api/profile/update.php', { method: 'POST', body: body });
            SW.toast(res.message, 'success');
            if (action === 'password') form.reset();
            if (res.data && res.data.reload) setTimeout(function () { window.location.reload(); }, 700);
        } catch (e) { SW.showErrors(form, e.errors || {}); SW.toast(e.message, 'danger'); }
        finally { SW.setLoading(btn, false); }
    }

    document.addEventListener('sw:ready', function () {
        const profile = document.getElementById('profileForm');
        const password = document.getElementById('passwordForm');
        if (profile) profile.addEventListener('submit', function (ev) { ev.preventDefault(); submit(profile, 'profile'); });
        if (password) password.addEventListener('submit', function (ev) { ev.preventDefault(); submit(password, 'password'); });
    });
})();
