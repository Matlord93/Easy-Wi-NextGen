(() => {
    'use strict';
    if (window.EasyWiCmsMediaPicker) return;
    const fields = new Set(['image_path', 'hero_background_image_path', 'content', 'rich_text_content']);
    function updateButtons() {
        const root = document.getElementById('cms-block-form');
        if (!root) return;
        const version = root.querySelector('[name="version"]')?.value, type = root.querySelector('[name="type"]')?.value.trim();
        const field = version === '2' ? ({image:'image_path',hero:'hero_background_image_path',rich_text:'rich_text_content'}[type] || null)
            : (['server_list','server_featured'].includes(type) ? null : 'content');
        root.querySelectorAll('[data-cms-media-open]').forEach(button => { button.hidden = button.dataset.cmsMediaOpen !== field; });
    }
    function abortRequests(root) {
        root?.querySelectorAll('[data-cms-media-open], [data-cms-media-dialog] [hx-get]').forEach(element => {
            element.dispatchEvent(new Event('htmx:abort', {bubbles:true}));
        });
    }
    function close(dialog) {
        abortRequests(dialog.closest('#cms-block-form'));
        if (typeof dialog.close === 'function') dialog.close();
        else dialog.removeAttribute('open');
    }
    document.addEventListener('click', event => {
        const opener = event.target.closest('[data-cms-media-open]');
        if (opener) {
            const root = opener.closest('#cms-block-form'), dialog = root?.querySelector('[data-cms-media-dialog]');
            if (!dialog || !fields.has(opener.dataset.cmsMediaOpen)) return;
            dialog.dataset.field = opener.dataset.cmsMediaOpen;
            const content = dialog.querySelector('#cms-media-picker-content');
            content.textContent = dialog.dataset.loading;
            if (typeof dialog.showModal === 'function') { if (!dialog.open) dialog.showModal(); }
            else dialog.setAttribute('open', '');
            return;
        }
        const closer = event.target.closest('[data-cms-media-close]');
        if (closer) { const dialog = closer.closest('[data-cms-media-dialog]'); if (dialog) close(dialog); return; }
        const selection = event.target.closest('[data-cms-media-select]');
        if (!selection) return;
        const dialog = selection.closest('[data-cms-media-dialog]'), root = dialog?.closest('#cms-block-form');
        if (!root || !fields.has(dialog.dataset.field)) return;
        const name = dialog.dataset.field, field = root.querySelector(`[name="${name}"]`), path = selection.dataset.cmsMediaPath;
        if (!field || !path?.startsWith('/uploads/')) return;
        const alt = selection.dataset.cmsMediaAlt || '';
        let caret = null;
        if (name === 'content' || name === 'rich_text_content') {
            const image = document.createElement('img'); image.setAttribute('src', path); image.setAttribute('alt', alt);
            const start = field.selectionStart ?? field.value.length, end = field.selectionEnd ?? start;
            field.setRangeText(image.outerHTML, start, end, 'end'); caret = field.selectionEnd;
        } else {
            field.value = path;
            const altField = root.querySelector('[name="image_alt"]');
            if (name === 'image_path' && altField && !altField.value.trim()) { altField.value = alt; altField.dispatchEvent(new Event('input', {bubbles:true})); }
        }
        field.dispatchEvent(new Event('input', {bubbles:true})); field.dispatchEvent(new Event('change', {bubbles:true}));
        close(dialog); field.focus(); if (caret !== null) field.setSelectionRange(caret, caret);
    });
    document.addEventListener('htmx:beforeSwap', event => {
        const detail = event.detail;
        if (detail.target?.id === 'cms-block-form') abortRequests(detail.target);
        if (detail.target?.id === 'cms-media-picker-content' && !detail.target.isConnected) detail.shouldSwap = false;
    });
    document.addEventListener('cancel', event => {
        if (event.target.matches('[data-cms-media-dialog]')) abortRequests(event.target.closest('#cms-block-form'));
    }, true);
    function loadError(event) {
        const dialog = event.detail?.target?.closest('[data-cms-media-dialog]');
        if (dialog) dialog.querySelector('#cms-media-picker-content').textContent = dialog.dataset.error;
    }
    ['htmx:responseError', 'htmx:sendError', 'htmx:timeout'].forEach(name => document.addEventListener(name, loadError));
    document.addEventListener('DOMContentLoaded', updateButtons);
    document.addEventListener('htmx:afterSwap', updateButtons);
    document.addEventListener('input', event => { if (event.target.matches('#cms-block-form [name="type"], #cms-block-form [name="version"]')) updateButtons(); });
    document.addEventListener('change', event => { if (event.target.matches('#cms-block-form [name="type"], #cms-block-form [name="version"]')) updateButtons(); });
    window.EasyWiCmsMediaPicker = true;
})();
