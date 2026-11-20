(() => {
    'use strict';
    const banner = document.getElementById('cookie-banner');
    const dialog = document.getElementById('cookie-settings-modal');
    if (!banner || !dialog || dialog.dataset.cookieReady) return;
    dialog.dataset.cookieReady = 'true';
    const name = banner.dataset.cookieName;
    const version = Number(banner.dataset.cookieVersion);
    const defaults = {version, necessary: true, statistics: false, marketing: false};
    const toggles = [...dialog.querySelectorAll('[data-cookie-toggle]')];
    const tabs = [...dialog.querySelectorAll('[data-cookie-tab]')];
    const policyPaths = new Set(['/cookies', '/cookie-richtlinie']);
    const settingsPaths = new Set(['/cookie-preferences', '/cookie-preferenzen', '/cookie-einstellungen']);
    let lastFocus = null;
    let queue = Promise.resolve();
    const read = () => {
        try {
            const raw = document.cookie.split('; ').find(row => row.startsWith(name + '='));
            if (!raw) return null;
            const consent = JSON.parse(decodeURIComponent(raw.slice(name.length + 1)));
            if (!consent || Number(consent.version) !== version) return null;
            return {...defaults, statistics: consent.statistics === true, marketing: consent.marketing === true};
        } catch { return null; }
    };
    let selection = read();
    const write = consent => {
        try { document.cookie = `${name}=${encodeURIComponent(JSON.stringify(consent))}; path=/; max-age=31536000; samesite=lax${location.protocol === 'https:' ? '; secure' : ''}`; } catch { /* The server can still persist the preference. */ }
    };
    const apply = consent => {
        toggles.forEach(toggle => { toggle.checked = consent[toggle.dataset.cookieToggle] === true; });
        window.__easywiConsentStatistics = consent.statistics;
        window.__easywiConsentMarketing = consent.marketing;
    };
    const view = (key, focus = false) => {
        tabs.forEach(tab => {
            const active = tab.dataset.cookieTab === key;
            tab.setAttribute('aria-selected', String(active));
            tab.tabIndex = active ? 0 : -1;
            if (active && focus) tab.focus();
        });
        dialog.querySelectorAll('[data-cookie-pane]').forEach(pane => { pane.hidden = pane.dataset.cookiePane !== key; });
    };
    const open = (key = 'settings') => {
        if (!dialog.open) {
            lastFocus = document.activeElement;
            selection = read();
            apply(selection || defaults);
            dialog.showModal();
        }
        banner.hidden = true;
        view(key);
        tabs.find(tab => tab.dataset.cookieTab === key)?.focus();
    };
    const close = () => { if (dialog.open) dialog.close(); };
    dialog.addEventListener('close', () => {
        banner.hidden = Boolean(selection);
        if (['#cookie-settings', '#cookie-policy'].includes(location.hash)) history.replaceState(null, '', location.pathname + location.search);
        if (lastFocus?.isConnected) lastFocus.focus({preventScroll: true});
    });
    const save = consent => {
        selection = consent;
        apply(consent);
        write(consent);
        banner.hidden = true;
        close();
        window.dispatchEvent(new CustomEvent('easywi:cookie-consent', {detail: {...consent}}));
        // Preserve the latest choice when an earlier response arrives after another click.
        queue = queue.then(async () => {
            try {
                await fetch('/cookie-consent', {method: 'POST', headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, credentials: 'same-origin', body: JSON.stringify(consent)});
            } catch { /* Keep the local first-party cookie while offline. */ }
            write(selection);
        });
    };
    document.addEventListener('click', event => {
        if (!(event.target instanceof Element)) return;
        const target = event.target.closest('[data-cookie-policy], [data-cookie-open], [data-cookie-settings], [data-cookie-tab], [data-cookie-close], [data-cookie-save], [data-cookie-accept], [data-cookie-reject], a[href]');
        if (!target || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button > 0) return;
        if (target.matches('[data-cookie-policy]')) { event.preventDefault(); open('policy'); }
        else if (target.matches('[data-cookie-open], [data-cookie-settings]')) { event.preventDefault(); open(); }
        else if (target.matches('[data-cookie-tab]')) view(target.dataset.cookieTab);
        else if (target.matches('[data-cookie-close]')) close();
        else if (target.matches('[data-cookie-accept]')) save({...defaults, statistics: true, marketing: true});
        else if (target.matches('[data-cookie-reject]')) save({...defaults});
        else if (target.matches('[data-cookie-save]')) save({...defaults, statistics: toggles.find(t => t.dataset.cookieToggle === 'statistics')?.checked === true, marketing: toggles.find(t => t.dataset.cookieToggle === 'marketing')?.checked === true});
        else if (target instanceof HTMLAnchorElement) {
            let url;
            try { url = new URL(target.href, location.href); } catch { return; }
            const path = url.pathname.replace(/\/$/, '');
            if (url.origin === location.origin && (policyPaths.has(path) || settingsPaths.has(path))) { event.preventDefault(); open(policyPaths.has(path) ? 'policy' : 'settings'); }
        }
    });
    dialog.addEventListener('keydown', event => {
        const tab = event.target.closest('[data-cookie-tab]');
        if (!tab || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        const index = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (tabs.indexOf(tab) + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
        view(tabs[index].dataset.cookieTab, true);
    });
    const fromHash = () => { if (location.hash === '#cookie-settings') open(); else if (location.hash === '#cookie-policy') open('policy'); };
    window.addEventListener('hashchange', fromHash);
    apply(selection || defaults);
    banner.hidden = Boolean(selection);
    fromHash();
})();
