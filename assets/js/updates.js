/* SiteWatch — database updates page */
(function () {
    'use strict';

    document.addEventListener('sw:ready', function () {
        const btn = document.getElementById('btnRunUpdate');
        if (!btn) return;

        btn.addEventListener('click', async function () {
            const ok = await SW.confirm({
                title: 'Update the database now?',
                message: 'This changes table structure only; your data is kept. Make sure you have a recent database backup, and keep this page open until it finishes.',
                confirmText: 'Update database',
                danger: false,
                icon: 'bi-database-up',
            });
            if (!ok) return;

            const errorBox = document.getElementById('updateError');
            if (errorBox) errorBox.hidden = true;
            SW.setLoading(btn, true, 'Updating…');
            try {
                const res = await SW.api('api/system/migrate.php', { method: 'POST', body: {} });
                SW.toast(res.message, 'success');
                setTimeout(function () { window.location.href = SW.url('admin/updates.php'); }, 900);
            } catch (e) {
                SW.setLoading(btn, false);
                if (errorBox) {
                    errorBox.hidden = false;
                    errorBox.querySelector('[data-error]').textContent = e.message;
                }
                SW.toast(e.message, 'danger', { title: 'Update failed', delay: 10000 });
            }
        });
    });
})();
