(() => {
    'use strict';
    if (window.EasyWiInfrastructure) return;
    const busy = new WeakSet();
    const retries = new WeakMap();
    function selectTab(root, id, focus = false) {
        const tabs = [...root.querySelectorAll('[data-infra-tablist] [data-infra-tab]')];
        if (!tabs.some(tab => tab.hash === '#' + id)) return;
        for (const tab of tabs) {
            const selected = tab.hash === '#' + id;
            tab.id = tab.hash.slice(1) + '-tab';
            tab.setAttribute('role', 'tab'); tab.setAttribute('aria-selected', String(selected));
            tab.setAttribute('aria-controls', tab.hash.slice(1)); tab.tabIndex = selected ? 0 : -1;
            if (focus && selected) tab.focus();
        }
        root.querySelector('[data-infra-tablist]')?.setAttribute('role', 'tablist');
        for (const pane of root.querySelectorAll('[data-infra-pane]')) {
            pane.setAttribute('role', 'tabpanel'); pane.setAttribute('aria-labelledby', pane.id + '-tab'); pane.hidden = pane.id !== id;
        }
    }
    function mount() {
        document.querySelectorAll('[data-infra-tabs]').forEach(root => {
            const first = root.querySelector('[data-infra-tab]');
            const wanted = [...root.querySelectorAll('[data-infra-tab]')].some(t => t.hash === location.hash) ? location.hash : first?.hash;
            if (wanted) selectTab(root, wanted.slice(1));
        });
    }
    document.addEventListener('click', event => {
        const tab = event.target.closest('[data-infra-tab], [data-infra-shortcut]');
        if (!tab || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault(); selectTab(tab.closest('[data-infra-tabs]'), tab.hash.slice(1));
        history.replaceState(null, '', tab.hash);
    });
    document.addEventListener('keydown', event => {
        const tab = event.target.closest('[data-infra-tab]');
        if (!tab || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        const root = tab.closest('[data-infra-tabs]'), tabs = [...root.querySelectorAll('[data-infra-tab]')];
        let index = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (tabs.indexOf(tab) + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
        event.preventDefault(); selectTab(root, tabs[index].hash.slice(1), true); history.replaceState(null, '', tabs[index].hash);
    });
    window.addEventListener('hashchange', mount);
    function filter(root) {
        const query = (root.querySelector('[data-infra-filter]')?.value || '').trim().toLocaleLowerCase();
        const state = root.querySelector('[data-infra-state]')?.value || '';
        let count = 0;
        root.querySelectorAll('[data-infra-item]').forEach(row => {
            row.hidden = !(row.textContent.toLocaleLowerCase().includes(query) && (!state || row.dataset.state === state));
            if (!row.hidden) count++;
        });
        const empty = root.querySelector('[data-infra-no-results]');
        if (empty) empty.hidden = count > 0;
    }
    document.addEventListener('input', event => { const root = event.target.closest('[data-infra-list]'); if (root) filter(root); });
    document.addEventListener('change', event => { const root = event.target.closest('[data-infra-list]'); if (root) filter(root); });
    async function refresh() {
        if (document.hidden) return;
        await Promise.all([...document.querySelectorAll('[data-infra-usage-url]')].map(async panel => {
            const retry = retries.get(panel);
            if (busy.has(panel) || retry?.stopped || Date.now() < (retry?.at || 0)) return;
            busy.add(panel);
            const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 10000);
            try {
                const url = new URL(panel.dataset.infraUsageUrl, location.href);
                if (url.origin !== location.origin) throw new Error('Invalid metrics origin');
                const response = await fetch(url, {credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: controller.signal, headers: {Accept: 'application/json'}});
                if ([401, 403, 404].includes(response.status)) { retries.set(panel, {stopped: true}); throw new Error('Unavailable'); }
                if (!response.ok) throw new Error('Unavailable');
                const data = await response.json();
                if (typeof data.html !== 'string') throw new Error('Invalid metrics');
                const state = new Map([...panel.querySelectorAll('[data-infra-series]')].map(root => [root.dataset.seriesRole, {open:root.querySelector('.infra-history')?.open, range:root.querySelector('[data-history-export-range]')?.value}]));
                const selectors = ['.infra-history summary', '[data-history-export-range]', '[data-history-export]'];
                const active = document.activeElement;
                const focused = panel.contains(active) ? {role:active.closest('[data-infra-series]')?.dataset.seriesRole, selector:selectors.find(selector => active.matches?.(selector))} : null;
                panel.innerHTML = data.html; retries.delete(panel);
                panel.querySelectorAll('[data-infra-series]').forEach(root => {
                    const saved = state.get(root.dataset.seriesRole), details = root.querySelector('.infra-history'), range = root.querySelector('[data-history-export-range]');
                    if (details && saved?.open) details.open = true;
                    if (range && ['current','5','20'].includes(saved?.range)) range.value = saved.range;
                });
                window.EasyWiInfrastructureHistory?.mount(panel);
                if (focused?.selector) [...panel.querySelectorAll('[data-infra-series]')].find(root => root.dataset.seriesRole === focused.role)?.querySelector(focused.selector)?.focus({preventScroll: true});
                const guests = panel.closest?.('.infra-page')?.querySelector('[data-infra-guests]');
                if (guests && typeof data.guests_html === 'string' && !guests.contains(document.activeElement)) {
                    const values = [...guests.querySelectorAll('[data-infra-filter], [data-infra-state]')].map(input => input.value);
                    guests.innerHTML = data.guests_html;
                    [...guests.querySelectorAll('[data-infra-filter], [data-infra-state]')].forEach((input, index) => { input.value = values[index] || ''; });
                    guests.querySelectorAll('[data-infra-list]').forEach(filter);
                }
                panel.parentElement?.querySelector('[data-usage-error]')?.setAttribute('hidden', '');
            } catch (_) {
                window.EasyWiInfrastructureHistory?.interrupt(panel);
                if (!retries.get(panel)?.stopped) { const failures = (retry?.failures || 0) + 1; retries.set(panel, {failures, at: Date.now() + Math.min(300000, 20000 * 2 ** Math.min(4, failures - 1))}); }
                panel.parentElement?.querySelector('[data-usage-error]')?.removeAttribute('hidden');
            } finally { clearTimeout(timeout); busy.delete(panel); }
        }));
    }
    window.EasyWiInfrastructure = {mount, refresh, filter};
    mount(); setInterval(refresh, 20000);
})();
