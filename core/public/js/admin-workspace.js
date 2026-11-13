(() => {
    'use strict';
    const body = document.body;
    if (!body?.classList.contains('admin-workspace')) return;
    const de = document.documentElement.lang.startsWith('de');
    let id = 0;
    const observedTables = new Set();
    const menus = new Set();
    const tableObserver = window.ResizeObserver ? new ResizeObserver(entries => {
        for (const {target: table} of entries) {
            const wrapper = table.closest('.aw-table-scroll');
            if (wrapper) wrapper.tabIndex = table.scrollWidth > wrapper.clientWidth + 1 ? 0 : -1;
        }
    }) : null;
    function identify(element) {
        if (!element.id) { do { id++; } while (document.getElementById('aw-field-' + id)); element.id = 'aw-field-' + id; }
        return element.id;
    }
    function enhance(scope) {
        for (const table of observedTables) if (!table.isConnected) { tableObserver?.unobserve(table); observedTables.delete(table); }
        if (!scope?.querySelectorAll) return;
        scope.querySelectorAll('input:not([type=hidden]), select, textarea').forEach(field => {
            // Associate existing adjacent labels; never infer labels from field
            // names or change values, submit targets, names or validation rules.
            if (field.labels?.length || field.hasAttribute('aria-label') || field.hasAttribute('aria-labelledby')) return;
            const parent = field.parentElement;
            if (!parent || parent.querySelectorAll('input:not([type=hidden]),select,textarea').length !== 1) return;
            const label = parent.querySelector('label:not([for])');
            if (!label || label.querySelector('input,select,textarea')) return;
            label.htmlFor = identify(field);
        });
        scope.querySelectorAll('input[required],select[required],textarea[required]').forEach(field => {
            const label = field.labels?.[0];
            if (!label || label.contains(field) || label.querySelector('.aw-required')) return;
            const mark = document.createElement('span'); mark.className = 'aw-required'; mark.textContent = '*'; mark.setAttribute('aria-hidden', 'true'); label.append(mark);
        });
        scope.querySelectorAll('details.ng-overflow').forEach(details => {
            if (menus.has(details)) return;
            const menu = details.querySelector('.ng-overflow__menu');
            if (!menu?.showPopover) return;
            menus.add(details); menu.setAttribute('popover', 'manual');
            details.addEventListener('toggle', () => {
                if (!details.open) { if (menu.matches(':popover-open')) menu.hidePopover(); return; }
                for (const other of menus) if (other !== details) other.open = false;
                menu.showPopover();
                const anchor = details.querySelector('summary').getBoundingClientRect();
                const width = menu.getBoundingClientRect().width;
                menu.style.left = Math.max(12, Math.min(anchor.right - width, window.innerWidth - width - 12)) + 'px';
                menu.style.top = Math.max(12, Math.min(anchor.bottom + 8, window.innerHeight - menu.getBoundingClientRect().height - 12)) + 'px';
            });
        });
        for (const menu of menus) if (!menu.isConnected) menus.delete(menu);
        const tables = [...scope.querySelectorAll('table')];
        if (scope.matches?.('table')) tables.push(scope);
        tables.forEach(table => {
            let wrapper = table.closest('.aw-table-scroll');
            if (!wrapper) {
                if (table.closest('.overflow-x-auto,.overflow-auto,[data-no-table-wrapper]')) return;
                wrapper = document.createElement('div'); wrapper.className = 'aw-table-scroll'; wrapper.setAttribute('role', 'region');
                wrapper.setAttribute('aria-label', de ? 'Tabelle – bei Bedarf horizontal scrollen' : 'Table – scroll horizontally if needed');
                table.before(wrapper); wrapper.append(table);
            }
            if (tableObserver && !observedTables.has(table)) { tableObserver.observe(table); observedTables.add(table); }
            wrapper.tabIndex = table.scrollWidth > wrapper.clientWidth + 1 ? 0 : -1;
        });
    }
    document.addEventListener('pointerdown', event => { for (const details of menus) if (details.open && !details.contains(event.target)) details.open = false; });
    document.addEventListener('keydown', event => { if (event.key === 'Escape') for (const details of menus) if (details.open) { details.open = false; details.querySelector('summary')?.focus(); } });
    window.addEventListener('resize', () => { for (const details of menus) details.open = false; });
    const mobile = window.matchMedia('(max-width: 768px)');
    const expandMobileSidebar = () => { if (mobile.matches) body.classList.remove('is-sidebar-collapsed'); };
    mobile.addEventListener('change', expandMobileSidebar);
    expandMobileSidebar();
    document.querySelectorAll('.ng-sidebar__item').forEach(link => {
        const label = link.textContent.trim();
        if (label) { link.setAttribute('aria-label', label); link.title = label; }
    });
    const search = document.querySelector('[data-admin-nav-search]');
    const groups = [...document.querySelectorAll('.ng-sidebar__group')];
    const empty = document.querySelector('[data-admin-nav-empty]');
    const initial = new Map();
    function filterNav() {
        const query = search.value.trim().toLocaleLowerCase();
        body.dataset.adminNavFiltering = query ? 'true' : 'false';
        let found = 0;
        for (const group of groups) {
            if (query && !initial.has(group)) initial.set(group, group.open);
            let matches = 0;
            group.querySelectorAll('.ng-sidebar__item').forEach(link => {
                const visible = !query || link.textContent.toLocaleLowerCase().includes(query);
                link.hidden = !visible; if (visible) matches++;
            });
            group.hidden = !matches; found += matches;
            if (query && matches) group.open = true;
            if (!query && initial.has(group)) group.open = initial.get(group);
        }
        if (!query) initial.clear();
        if (empty) empty.hidden = found > 0;
    }
    if (search) {
        search.addEventListener('input', filterNav);
        search.addEventListener('keydown', event => { if (event.key === 'Escape' && search.value) { event.stopPropagation(); search.value = ''; filterNav(); } });
    }
    document.addEventListener('invalid', event => { let current = event.target.parentElement; while (current) { if (current.tagName === 'DETAILS') current.open = true; current = current.parentElement; } }, true);
    document.addEventListener('htmx:beforeSwap', event => {
        const detail = event.detail;
        if (detail.target?.matches('[data-admin-validation]') && [400, 422].includes(detail.xhr.status)) {
            detail.shouldSwap = true;
            detail.isError = false;
        }
    });
    document.addEventListener('htmx:afterSwap', event => {
        // With outerHTML the former target is detached; enhance the replacement.
        enhance(event.detail.target?.isConnected ? event.detail.target : document);
    });
    enhance(document);
})();
