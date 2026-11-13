(() => {
    'use strict';
    let editing = false;
    document.addEventListener('input', () => { editing = true; });
    document.addEventListener('click', event => {
        if (event.target.closest('[data-kvm-refresh-ready]')) { window.location.reload(); return; }
        const button = event.target.closest('[data-kvm-confirm]');
        if (button && !window.confirm(button.dataset.kvmConfirm)) event.preventDefault();
    });
    const pending = [...document.querySelectorAll('[data-kvm-job]')];
    let stopped = false;
    async function poll() {
        if (stopped || !pending.length) return;
        if (document.hidden) { window.setTimeout(poll, 4000); return; }
        try {
            const results = await Promise.all(pending.map(async row => {
                const response = await fetch(row.dataset.kvmJob, {credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'}});
                if (response.status === 401 || response.status === 403 || response.redirected) { stopped = true; throw new Error('Session expired'); }
                if (!response.ok) throw new Error('Job status unavailable');
                return response.json();
            }));
            if (results.some(job => ['success', 'failed'].includes(job.status))) {
                if (editing) { document.querySelector('[data-kvm-refresh-ready]')?.removeAttribute('hidden'); stopped = true; return; }
                window.location.reload(); return;
            }
            document.querySelector('[data-kvm-poll-error]')?.setAttribute('hidden', '');
        } catch (_) {
            document.querySelector('[data-kvm-poll-error]')?.removeAttribute('hidden');
        }
        if (!stopped) window.setTimeout(poll, 4000);
    }
    if (pending.length) window.setTimeout(poll, 2000);
})();
