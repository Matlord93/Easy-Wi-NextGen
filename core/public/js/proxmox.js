(function () {
    'use strict';
    if (window.EasyWiProxmox) { window.EasyWiProxmox.mount(); return; }
    let labels = {};
    let running = false;
    let reloadScheduled = false;
    let stopped = false;
    const dirty = new WeakSet();
    document.addEventListener('input', (event) => { if (event.target.form) dirty.add(event.target.form); });
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!form.matches('[data-pve-mutation]')) return;
        if (form.dataset.pveBusy) { event.preventDefault(); return; }
        form.dataset.pveBusy = '1';
        form.querySelectorAll('button[type=submit], button:not([type])').forEach((button) => { button.disabled = true; });
    });
    window.addEventListener('pageshow', () => {
        document.querySelectorAll('[data-pve-mutation]').forEach((form) => {
            if (!form.dataset.pveBusy) return;
            delete form.dataset.pveBusy;
            form.querySelectorAll('button').forEach((button) => { button.disabled = false; });
        });
    });
    function mount() {
        document.querySelectorAll('[data-pve-labels]').forEach((element) => {
            try { labels = {...labels, ...JSON.parse(element.textContent)}; } catch (_) {}
        });
    }
    async function json(url) {
        const response = await fetch(url, {credentials: 'same-origin', headers: {Accept: 'application/json'}, redirect: 'error'});
        if ([401, 403].includes(response.status)) stopped = true;
        if (!response.ok) throw new Error('Unavailable');
        return response.json();
    }
    async function refresh() {
        if (running || stopped || document.hidden) return;
        running = true;
        try {
            for (const row of document.querySelectorAll('[data-pve-task-url]')) {
                try {
                    const data = await json(row.dataset.pveTaskUrl);
                    if (!['submitting', 'running', 'uncertain', 'succeeded', 'failed'].includes(data.state) || typeof data.active !== 'boolean') throw new Error('Invalid task response');
                    const state = row.querySelector('[data-pve-task-state]');
                    if (state) state.textContent = (labels[data.state] || data.state) + (data.result && data.result !== 'OK' ? ' · ' + (labels[data.result] || '') : '');
                    if (!data.active || data.poll === false) {
                        delete row.dataset.pveTaskUrl;
                        const editing = document.activeElement?.matches('input, select, textarea') || [...document.querySelectorAll('form')].some((form) => dirty.has(form));
                        if (!editing && !reloadScheduled) { reloadScheduled = true; window.setTimeout(() => window.location.reload(), 1000); }
                    }
                } catch (_) { /* Keep the last confirmed state. */ }
            }
            const now = Date.now();
            for (const panel of document.querySelectorAll('[data-pve-resources-url]')) {
                if (panel.contains?.(document.activeElement) && document.activeElement?.matches('input, select, textarea')) continue;
                if (now < Number(panel.dataset.pveRetryAt || 0) || now - Number(panel.dataset.pveUpdated || 0) < 20000) continue;
                try {
                    const data = await json(panel.dataset.pveResourcesUrl);
                    if (typeof data.html !== 'string') throw new Error('Invalid resource response');
                    const filters = [...(panel.querySelectorAll?.('[data-infra-filter], [data-infra-state]') || [])].map(input => input.value);
                    panel.innerHTML = data.html;
                    [...(panel.querySelectorAll?.('[data-infra-filter], [data-infra-state]') || [])].forEach((input, index) => { input.value = filters[index] || ''; });
                    panel.querySelectorAll?.('[data-infra-list]').forEach(root => window.EasyWiInfrastructure?.filter(root));
                    const state = panel.closest('section')?.querySelector('[data-pve-refresh-state]');
                    if (state) state.textContent = (labels.updated || '{time}').replace('{time}', new Intl.DateTimeFormat(document.documentElement.lang, {timeStyle: 'medium'}).format(new Date()));
                    panel.dataset.pveUpdated = String(Date.now());
                    delete panel.dataset.pveFailures; delete panel.dataset.pveRetryAt;
                } catch (_) {
                    const failures = Math.min(5, Number(panel.dataset.pveFailures || 0) + 1);
                    panel.dataset.pveFailures = String(failures);
                    panel.dataset.pveRetryAt = String(Date.now() + Math.min(300000, 20000 * 2 ** (failures - 1)));
                    const state = panel.closest('section')?.querySelector('[data-pve-refresh-state]');
                    if (state) state.textContent = labels.refresh_failed || '';
                }
            }
        } finally { running = false; }
    }
    window.EasyWiProxmox = {mount, refresh};
    mount();
    window.setInterval(refresh, 5000);
})();
