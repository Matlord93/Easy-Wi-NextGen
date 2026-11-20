(() => {
    'use strict';
    const toggle = document.querySelector('[data-cms-menu-toggle]');
    const navigation = document.getElementById('cms-site-navigation');
    if (!toggle || !navigation) return;
    document.body.dataset.cmsReady = 'true';
    function close(focus = false) {
        navigation.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
        if (focus) toggle.focus();
    }
    toggle.addEventListener('click', () => {
        const open = navigation.classList.toggle('is-open');
        toggle.setAttribute('aria-expanded', String(open));
    });
    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        if (navigation.classList.contains('is-open')) close(true);
        document.querySelectorAll('.cms-account[open]').forEach(menu => { menu.open = false; menu.querySelector('summary').focus(); });
    });
    document.addEventListener('click', event => {
        if (event.target.closest('#cms-site-navigation a')) close();
        if (!event.target.closest('.cms-account')) document.querySelectorAll('.cms-account[open]').forEach(menu => menu.open = false);
    });
    window.addEventListener('resize', () => { if (innerWidth > 980) close(); });
})();
