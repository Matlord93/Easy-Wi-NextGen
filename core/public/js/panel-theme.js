/* Apply before the first paint; storage can be unavailable in private sessions. */
(() => {
    'use strict';
    if (window.EasyWITheme) return;
    const root = document.documentElement;
    const valid = value => value === 'light' || value === 'dark';
    const read = () => { try { const value = localStorage.getItem('theme'); return valid(value) ? value : 'dark'; } catch (_) { return root.classList.contains('dark') ? 'dark' : 'light'; } };
    let mode = read();
    function sync() {
        root.classList.toggle('dark', mode === 'dark');
        root.dataset.theme = mode;
        root.style.colorScheme = mode;
        const de = root.lang.startsWith('de');
        document.querySelectorAll('#theme-toggle, [data-panel-theme-toggle]').forEach(button => {
            const label = mode === 'dark' ? (de ? 'Zum hellen Design wechseln' : 'Switch to light theme') : (de ? 'Zum dunklen Design wechseln' : 'Switch to dark theme');
            button.setAttribute('aria-label', label); button.title = label;
            button.setAttribute('aria-pressed', String(mode === 'dark'));
            const moon = button.querySelector('.theme-icon-dark');
            const sun = button.querySelector('.theme-icon-light');
            if (moon) moon.style.display = mode === 'light' ? '' : 'none';
            if (sun) sun.style.display = mode === 'dark' ? '' : 'none';
        });
    }
    function set(value) {
        if (!valid(value)) return;
        mode = value; sync();
        try { localStorage.setItem('theme', mode); } catch (_) { /* The current tab still works. */ }
        window.dispatchEvent(new CustomEvent('easywi:themechange', {detail: {theme: mode}}));
    }
    window.EasyWITheme = Object.freeze({set, get: () => mode});
    sync();
    document.addEventListener('DOMContentLoaded', sync);
    document.addEventListener('htmx:afterSwap', sync);
    document.addEventListener('click', event => {
        if (event.target.closest('#theme-toggle, [data-panel-theme-toggle]')) set(mode === 'dark' ? 'light' : 'dark');
    });
    window.addEventListener('storage', event => {
        if (event.key === 'theme' || event.key === null) { mode = valid(event.newValue) ? event.newValue : 'dark'; sync(); }
    });
})();
