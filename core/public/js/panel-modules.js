/* Progressive tabs: forms, their values and existing module event handlers stay in place. */
(() => {
    'use strict';
    if (window.EasyWiModulePanels) return;
    function activate(root, key, focus = false) {
        const tabs = [...root.querySelectorAll('[data-panel-tab]')];
        const panels = [...root.querySelectorAll('[data-panel-section]')];
        if (!tabs.some(t => t.dataset.panelTab === key) || !panels.some(p => p.dataset.panelSection === key)) return;
        root.dataset.panelActive = key;
        tabs.forEach((tab, index) => {
            const selected = tab.dataset.panelTab === key;
            tab.id ||= `${root.dataset.panelTabs}-tab-${index}`;
            tab.setAttribute('role','tab'); tab.setAttribute('aria-selected',String(selected)); tab.tabIndex = selected ? 0 : -1;
            const owned = panels.filter(p => p.dataset.panelSection === tab.dataset.panelTab);
            owned.forEach((p,i) => { p.id ||= `${root.dataset.panelTabs}-${tab.dataset.panelTab}-${i}`; p.setAttribute('role','tabpanel'); p.setAttribute('aria-labelledby',tab.id); });
            tab.setAttribute('aria-controls',owned.map(p => p.id).join(' '));
            if (selected && focus) tab.focus();
        });
        panels.forEach(p => { p.hidden = p.dataset.panelSection !== key; });
        root.querySelector('[data-panel-tablist]')?.setAttribute('role','tablist');
    }
    function frame() {
        const main = document.querySelector('.ng-content');
        if (!main) return;
        document.querySelectorAll('[data-panel-webspace-domains]').forEach(target => {
            const root=target.closest('[data-panel-tabs]');
            root.querySelectorAll('.pm-webspace-domain-row').forEach(row=>{
                const details=row.querySelector('details'); if(!details) return;
                const card=document.createElement('section'); card.className='cust-panel'; const heading=document.createElement('h2'); heading.className='cust-panel__title'; heading.textContent=row.dataset.panelDomainName;
                const head=document.createElement('div'); head.className='cust-panel__head'; head.append(heading);
                const body=document.createElement('div'); body.className='cust-panel__body'; body.append(details); card.append(head,body); target.append(card); row.remove();
            });
        });
        const active = document.querySelector('.ng-sidebar a[aria-current="page"]');
        let title = main.querySelector('h1');
        if (!title) {
            const label = document.querySelector('.ng-topbar__title h1')?.textContent.trim() || active?.textContent.trim();
            if (label) { const header=document.createElement('header'); header.className='pm-page-head'; title=document.createElement('h1'); title.textContent=label; header.append(title); main.prepend(header); }
        }
        if (title) {
            const header=title.closest('.pm-page-head,.pm-edit-header,.cust-hero,.tkt-hero,.infra-hero,.server-hero,.cust-panel__head,header') || title.parentElement;
            const anchor=header.classList.contains('tkt-hero') && header.parentElement.classList.contains('ng-card') ? header.parentElement : header;
            if (!main.querySelector('.pm-breadcrumb,.infra-breadcrumb') && active) {
                const crumb=document.createElement('nav'); crumb.className='pm-breadcrumb'; crumb.setAttribute('aria-label','Breadcrumb');
                const group=document.createElement('span'); group.textContent=active.closest('details')?.querySelector('summary span')?.textContent.trim() || '';
                const separator=document.createElement('span'); separator.textContent='›'; separator.setAttribute('aria-hidden','true');
                const link=document.createElement('a'); link.href=active.href; link.textContent=active.querySelector('.ng-sidebar__text')?.textContent.trim() || active.textContent.trim();
                if (group.textContent) crumb.append(group,separator); crumb.append(link); anchor.before(crumb);
            }
            const power=main.querySelector('#gameserver-overview-mount');
            if (power && !power.classList.contains('pm-game-power')) { power.classList.add('pm-game-power'); header.append(power); }
            const moduleNav=main.querySelector(':scope > .pm-module-nav');
            if (moduleNav) anchor.after(moduleNav);
        }
        const guide=main.querySelector(':scope > .pm-workspace-help');
        if (guide) main.append(guide);
    }
    function filterList(scope) {
        if (!scope) return;
        const query=scope.querySelector('[data-panel-filter]')?.value.trim().toLocaleLowerCase() || '';
        const facets=[...scope.querySelectorAll('[data-panel-facet]')];
        scope.querySelectorAll('[data-panel-row]').forEach(row=>{ row.hidden=!row.textContent.toLocaleLowerCase().includes(query) || facets.some(select=>select.value && row.dataset[select.dataset.panelFacet]!==select.value); });
        const rows = [...scope.querySelectorAll('[data-panel-row]')];
        const empty = scope.querySelector('[data-panel-empty]');
        if (empty) empty.hidden = !rows.length || rows.some(row => !row.hidden);
    }
    function search(input) {
        const root=input.closest('[data-panel-search]'), results=root.querySelector('[data-panel-search-results]');
        const query=input.value.trim().toLocaleLowerCase(); results.replaceChildren();
        if (!query) { results.hidden=true; input.setAttribute('aria-expanded','false'); return; }
        const seen=new Set();
        const matches=[...document.querySelectorAll('.ng-sidebar a[href]')].filter(a => {
            const match=a.textContent.toLocaleLowerCase().includes(query) && !seen.has(a.href); seen.add(a.href); return match;
        }).slice(0,10);
        for (const source of matches) { const a=document.createElement('a'); a.href=source.href; a.textContent=source.textContent.trim(); results.append(a); }
        if (!matches.length) { const p=document.createElement('p'); p.textContent=input.dataset.emptyLabel; results.append(p); }
        results.hidden=false; input.setAttribute('aria-expanded','true');
    }
    function mount() {
        frame();
        document.querySelectorAll('[data-panel-tabs]').forEach(root => {
            const prefix = `#pm-${root.dataset.panelTabs}-`;
            const targetId = location.hash.slice(1);
            const target = targetId ? document.getElementById(targetId) : null;
            const key = location.hash.startsWith(prefix) ? location.hash.slice(prefix.length) : (target && root.contains(target) ? target.closest('[data-panel-section]')?.dataset.panelSection : null);
            const validKey = [...root.querySelectorAll('[data-panel-tab]')].some(tab => tab.dataset.panelTab === key);
            activate(root,(validKey ? key : null) || root.dataset.panelActive || root.querySelector('[data-panel-tab]')?.dataset.panelTab);
        });
    }
    document.addEventListener('click', event => {
        const reveal = event.target.closest('[data-panel-reveal]');
        if (reveal) {
            const root = reveal.closest('[data-panel-tabs]');
            const tab = root?.querySelector(`[data-panel-tab="${reveal.dataset.panelReveal}"]`);
            if (tab) { activate(root, tab.dataset.panelTab); history.replaceState(null, '', tab.hash); }
        }
        const opener = event.target.closest('[data-panel-open-details]');
        if (opener?.dataset.panelOpenDetails) { const details = document.getElementById(opener.dataset.panelOpenDetails); if (details) details.open = true; }
        const tab = event.target.closest('[data-panel-tab]');
        if (!tab || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const root = tab.closest('[data-panel-tabs]');
        if (!root) return;
        event.preventDefault(); activate(root,tab.dataset.panelTab); history.replaceState(null,'',tab.hash);
    });
    document.addEventListener('keydown', event => {
        const tab = event.target.closest('[data-panel-tab]');
        if (!tab || !['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return;
        const root = tab.closest('[data-panel-tabs]');
        if (!root) return;
        const tabs = [...root.querySelectorAll('[data-panel-tab]')];
        const n = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length-1 : (tabs.indexOf(tab)+(event.key === 'ArrowRight' ? 1 : -1)+tabs.length)%tabs.length;
        event.preventDefault(); activate(root,tabs[n].dataset.panelTab,true); history.replaceState(null,'',tabs[n].hash);
    });
    document.addEventListener('invalid', event => { const panel=event.target.closest('[data-panel-section]'),root=panel?.closest('[data-panel-tabs]'); if(root) activate(root,panel.dataset.panelSection); },true);
    document.addEventListener('input', event => {
        if (event.target.matches('[data-panel-search-input]')) { search(event.target); return; }
        if (event.target.matches('[data-panel-filter]')) filterList(event.target.closest('[data-panel-list]'));
    });
    document.addEventListener('click',event => {
        if (event.target.closest('[data-panel-search]')) return;
        document.querySelectorAll('[data-panel-search-results]').forEach(el=>el.hidden=true);
        document.querySelectorAll('[data-panel-search-input]').forEach(el=>el.setAttribute('aria-expanded','false'));
    });
    document.addEventListener('keydown',event => {
        const root=event.target.closest('[data-panel-search]'); if(!root) return;
        const input=root.querySelector('input'), results=root.querySelector('[data-panel-search-results]');
        const links=[...results.querySelectorAll('a')];
        if (event.key==='Escape') { results.hidden=true; input.setAttribute('aria-expanded','false'); input.focus(); }
        if (['ArrowDown','ArrowUp'].includes(event.key) && links.length) { event.preventDefault(); const i=links.indexOf(document.activeElement), delta=event.key==='ArrowDown'?1:-1; links[(i+delta+links.length)%links.length].focus(); }
        if(event.key==='Enter' && event.target===input && links.length) { event.preventDefault(); links[0].click(); }
    });
    document.addEventListener('change',event=>{if(event.target.matches('[data-panel-facet]')) filterList(event.target.closest('[data-panel-list]'));});
    // The reseller uses the shared shell; existing admin/customer shell handlers remain in place.
    if (document.body?.classList.contains('reseller-interface')) {
        const closeSidebar = () => document.body.classList.remove('is-sidebar-open');
        document.addEventListener('click',event => {
            if (event.target.closest('.ng-sidebar-toggle')) {
                document.body.classList.toggle(matchMedia('(max-width:768px)').matches ? 'is-sidebar-open' : 'is-sidebar-collapsed');
            }
            if (event.target.closest('[data-sidebar-backdrop]')) closeSidebar();
            const toggle = event.target.closest('[data-user-menu-toggle]'), menu = document.querySelector('[data-user-menu]');
            if (toggle && menu) { menu.open = !menu.open; toggle.setAttribute('aria-expanded',String(menu.open)); }
            else if (menu && !menu.contains(event.target)) menu.open = false;
        });
        window.addEventListener('keydown',event => { if (event.key === 'Escape') { closeSidebar(); const menu=document.querySelector('[data-user-menu]'); if(menu) menu.open=false; } });
        window.addEventListener('resize',() => { if (!matchMedia('(max-width:768px)').matches) closeSidebar(); });
    }
    document.addEventListener('htmx:beforeSwap', event => {
        const detail = event.detail;
        if (detail.target?.matches('[data-panel-validation]') && [400, 422].includes(detail.xhr.status)) {
            detail.shouldSwap = true;
            detail.isError = false;
        }
    });
    document.addEventListener('DOMContentLoaded',mount); document.addEventListener('htmx:afterSwap',mount); window.addEventListener('hashchange',mount);
    window.EasyWiModulePanels=Object.freeze({mount,activate});
})();
