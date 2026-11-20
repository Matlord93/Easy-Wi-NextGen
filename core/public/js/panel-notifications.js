(() => {
    'use strict';
    function update(root) {
        const rows = [...root.querySelectorAll('[data-notification-row]')];
        const query = root.querySelector('[data-notification-search]').value.trim().toLocaleLowerCase();
        const status = root.querySelector('[data-notification-filter]').value;
        const unread = rows.filter(row => row.dataset.notificationState === 'unread').length;
        const counts = {total: rows.length, unread, read: rows.length - unread};
        for (const [key, count] of Object.entries(counts)) {
            root.querySelector(`[data-notification-count="${key}"] strong`).textContent = String(count);
        }
        let visible = 0;
        for (const row of rows) {
            row.hidden = Boolean(status && row.dataset.notificationState !== status) || !row.textContent.toLocaleLowerCase().includes(query);
            if (!row.hidden) visible++;
        }
        root.querySelector('[data-notification-empty]').hidden = visible !== 0;
    }
    const mount = () => document.querySelectorAll('[data-notifications]').forEach(update);
    document.addEventListener('input', event => {
        if (event.target.matches('[data-notification-search]')) update(event.target.closest('[data-notifications]'));
    });
    document.addEventListener('change', event => {
        if (event.target.matches('[data-notification-filter]')) update(event.target.closest('[data-notifications]'));
    });
    document.addEventListener('DOMContentLoaded', mount);
    document.addEventListener('htmx:afterSwap', mount);
})();
