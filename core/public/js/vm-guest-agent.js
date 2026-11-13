(() => {
    'use strict';
    if (window.EasyWiGuestAgent) return;
    window.EasyWiGuestAgent = true;
    let leaving = false;
    window.addEventListener('pagehide', () => { leaving = true; });
    window.addEventListener('pageshow', () => { leaving = false; });
    const wait = ms => new Promise(resolve => window.setTimeout(resolve, ms));
    async function json(url, options = {}) {
        const target = new URL(url, window.location.href);
        if (target.origin !== window.location.origin) throw new Error('Invalid diagnostic target');
        const controller = new AbortController(), timer = window.setTimeout(() => controller.abort(), 22000);
        try {
            const response = await fetch(target, {...options, signal: controller.signal, credentials: 'same-origin', cache: 'no-store', redirect: 'error', headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});
            if (!response.ok) throw new Error('Diagnostic unavailable');
            return await response.json();
        } finally { window.clearTimeout(timer); }
    }
    document.addEventListener('submit', async event => {
        const form = event.target;
        if (!form.matches('[data-vm-agent-form]')) return;
        event.preventDefault();
        if (form.dataset.busy) return;
        const panel = form.closest('[data-vm-agent-panel]'), button = form.querySelector('button'), state = panel.querySelector('[data-vm-agent-state]');
        let labels;
        try { labels = JSON.parse(panel.querySelector('[data-vm-agent-labels]').textContent); } catch (_) { return; }
        const show = code => { state.textContent = labels[code] || labels.unavailable; };
        const renderNetwork = network => {
            const list = panel.querySelector('[data-vm-agent-network-list]');
            list.replaceChildren();
            const interfaces = network?.state === 'available' && Array.isArray(network.interfaces) ? network.interfaces.slice(0, 16) : [];
            let key = 'network_' + (network?.state || 'not_reported');
            if (network?.state === 'available' && !interfaces.length) key = 'network_empty';
            panel.querySelector('[data-vm-agent-network-state]').textContent = labels[key] || labels.network_unavailable;
            panel.querySelector('[data-vm-agent-network-limited]').hidden = network?.limited !== true;
            for (const adapter of interfaces) {
                if (!adapter || typeof adapter.name !== 'string') continue;
                const card = document.createElement('article'); card.className = 'infra-network-adapter';
                const title = document.createElement('h4'); title.textContent = adapter.name; card.append(title);
                const mac = document.createElement('p'); mac.textContent = labels.network_mac + ': ' + (typeof adapter.mac === 'string' ? adapter.mac : '—'); card.append(mac);
                const addresses = document.createElement('ul'); addresses.className = 'infra-network-addresses';
                for (const ip of Array.isArray(adapter.addresses) ? adapter.addresses.slice(0, 16) : []) {
                    if (!ip || !['ipv4', 'ipv6'].includes(ip.family) || typeof ip.address !== 'string' || !Number.isInteger(ip.prefix)) continue;
                    const item = document.createElement('li'), family = document.createElement('strong'), address = document.createElement('span');
                    family.textContent = ip.family === 'ipv4' ? 'IPv4' : 'IPv6'; address.textContent = ip.address + '/' + ip.prefix;
                    item.append(family, address); addresses.append(item);
                }
                if (!addresses.children.length) { const empty = document.createElement('li'); empty.textContent = labels.network_no_addresses; addresses.append(empty); }
                card.append(addresses); list.append(card);
            }
        };
        const render = report => {
            if (!report || typeof report.state !== 'string') throw new Error('Invalid observation');
            show(report.state);
            renderNetwork(report.state === 'reachable' ? report.network : {state:'unavailable'});
            for (const field of panel.querySelectorAll('[data-vm-agent-field]')) {
                const value = report[field.dataset.vmAgentField];
                field.textContent = report.state === 'reachable' && typeof value === 'string' ? value : '—';
            }
            const time = panel.querySelector('[data-vm-agent-time]'), date = typeof report.observed_at === 'string' ? new Date(report.observed_at) : null;
            time.textContent = date && Number.isFinite(date.getTime()) ? date.toLocaleString(document.documentElement.lang) : '—';
            if (date && Number.isFinite(date.getTime())) time.setAttribute('datetime', date.toISOString()); else time.removeAttribute('datetime');
        };
        form.dataset.busy = '1'; button.disabled = true; show('checking'); renderNetwork({state:'checking'});
        for (const field of panel.querySelectorAll('[data-vm-agent-field], [data-vm-agent-time]')) field.textContent = '—';
        panel.querySelector('[data-vm-agent-time]').removeAttribute('datetime');
        try {
            const body = new FormData(form);
            if (body.has('operation_id')) body.set('operation_id', crypto.randomUUID());
            let data = await json(form.getAttribute('action'), {method: 'POST', body});
            if (form.dataset.vmAgentPoll) {
                if (typeof data.job_id !== 'string' || !/^[a-f0-9-]{36}$/.test(data.job_id)) throw new Error('Invalid diagnostic job');
                const url = form.dataset.vmAgentPoll.replace('__JOB__', encodeURIComponent(data.job_id));
                show('pending');
                for (let i = 0; i < 20; i++) {
                    if (leaving || document.hidden) throw new Error('Diagnostic interrupted');
                    data = await json(url);
                    if (data.status === 'success') break;
                    if (data.status === 'failed') throw new Error('Diagnostic failed');
                    if (!['queued', 'running'].includes(data.status)) throw new Error('Invalid job state');
                    if (i === 19) throw new Error('Diagnostic still pending');
                    await wait(2000);
                }
            }
            render(data.guest_agent);
        } catch (_) { show('failed'); renderNetwork({state:'unavailable'}); }
        finally {
            await wait(1000);
            delete form.dataset.busy; button.disabled = false;
        }
    });
})();
