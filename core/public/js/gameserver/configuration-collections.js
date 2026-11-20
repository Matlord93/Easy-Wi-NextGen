(() => {
    'use strict';
    window.EasyWIGameConfigurationLists = (mount, changed) => {
        if (!mount) return null;
        const groups = new Map();
        let busy = false;
        const node = (tag, text, className) => { const el = document.createElement(tag); if (text !== undefined) el.textContent = text; if (className) el.className = className; return el; };
        const different = (item, initial) => item.selected !== initial.selected || item.selected && (item.level !== initial.level || item.unlocked !== initial.unlocked);
        const summaryText = group => `${group.definition.label} · ${group.definition.editable ? [...group.items.values()].filter(item => item.selected).length + ' ausgewählt' : 'Dateimanager prüfen'}`;
        const changes = () => {
            const result = {};
            for (const [key, group] of groups) {
                if (!group.definition.editable) continue;
                const add = [], upsert = [], remove = [];
                for (const [id, item] of group.items) {
                    const initial = group.initial.get(id);
                    if (!different(item, initial)) continue;
                    if (!item.selected) { if (initial.selected) remove.push(id); }
                    else if (group.definition.kind === 'bosses') upsert.push({UnitId:id,UnitLevel:Number(item.level),DefaultUnlocked:item.unlocked});
                    else if (!initial.selected) add.push(id);
                }
                if (add.length || upsert.length || remove.length) result[key] = {...group.definition.kind === 'bosses' ? {upsert} : {add},remove};
            }
            return result;
        };
        const dirtyCount = () => Object.values(changes()).reduce((count, operations) => count + (operations.upsert || operations.add || []).length + operations.remove.length, 0);
        const setBusy = value => {
            busy = value;
            mount.querySelectorAll('[data-list-control]').forEach(control => {
                const group = groups.get(control.dataset.list);
                const item = group?.items.get(Number(control.dataset.id));
                control.disabled = busy || !group?.definition.editable || control.dataset.requiresSelection === 'true' && !item?.selected;
            });
        };
        const renderRows = (key, group, container, query) => {
            container.replaceChildren();
            let visible = 0;
            for (const [id, item] of group.items) {
                if (query && !`${item.label} ${id}`.toLocaleLowerCase().includes(query)) continue;
                visible++;
                const card = node('div', undefined, 'gs-config__field');
                const heading = node('h4', item.label); card.append(heading, node('code', String(id), 'gs-config__source'));
                const control = (type, labelText, name, selection = false) => {
                    const label = node('label', undefined, 'gs-config__collection-control');
                    const input = node('input'); input.type = type; input.dataset.listControl = name; input.dataset.list = key; input.dataset.id = String(id); input.dataset.requiresSelection = String(selection); input.setAttribute('aria-label', `${item.label}: ${labelText}`);
                    if (type === 'checkbox') { input.checked = Boolean(item[name]); input.setAttribute('role', 'switch'); input.className = 'gs-config__switch'; }
                    else { input.className = 'form-input'; input.min = '0'; input.max = '255'; input.step = '1'; input.value = item[name]; }
                    input.addEventListener('input', () => { input.setCustomValidity(''); item[name] = type === 'checkbox' ? input.checked : input.value; group.summary.textContent = summaryText(group); changed(); });
                    label.append(node('span', labelText), input); card.append(label);
                };
                control('checkbox', group.definition.kind === 'bosses' ? 'Eigene Boss-Vorgabe verwenden' : 'Für neue Charaktere freischalten', 'selected');
                if (group.definition.kind === 'bosses') {
                    control('number', 'Stufe (0 = Spielvorgabe)', 'level', true);
                    control('checkbox', 'Kräfte und Rezepte bei Charaktererstellung freischalten', 'unlocked', true);
                }
                container.append(card);
            }
            if (!visible) container.append(node('p', 'Keine passenden Einträge gefunden.', 'gs-config__empty'));
            setBusy(busy);
        };
        const load = (definitions, preserveView = false) => {
            const views = new Map();
            if (preserveView) for (const details of mount.querySelectorAll('[data-list-group]')) views.set(details.dataset.listGroup,{open:details.open,query:details.querySelector('[data-list-search]')?.value || ''});
            groups.clear(); mount.replaceChildren();
            mount.hidden = !Array.isArray(definitions) || definitions.length === 0;
            if (mount.hidden) return;
            mount.append(node('h3', 'Fortschritt und V-Blut'), node('p', 'Freischaltungen gelten für neu erstellte Charaktere. Bestehender Fortschritt wird durch Entfernen einer Vorgabe nicht zurückgenommen. Boss-Stufen greifen nach dem erneuten Erscheinen des Bosses; 0 verwendet die Spielvorgabe. Erst „Änderungen speichern“ schreibt die Datei.', 'gs-config__description'));
            for (const definition of definitions) {
                const group = {definition,items:new Map(),initial:new Map()};
                for (const item of definition.items || []) { const value = {...item,level:String(item.level)}; group.items.set(item.id,value); group.initial.set(item.id,{...value}); }
                groups.set(definition.key,group);
                const details = node('details', undefined, 'gs-config__collection'); details.dataset.listGroup = definition.key; details.open = views.get(definition.key)?.open || false;
                const summary = node('summary', summaryText(group)); group.summary = summary; details.append(summary);
                if (!definition.editable) { details.append(node('p', definition.message || 'Diese Liste bitte im Dateimanager bearbeiten.', 'ng-alert ng-alert--warning')); mount.append(details); continue; }
                const label = node('label', undefined, 'gs-config__collection-search'); label.append(node('span', 'Einträge suchen'));
                const search = node('input'); search.type = 'search'; search.className = 'form-input'; search.placeholder = 'Name oder Kennung …'; search.dataset.listSearch = definition.key; search.value = views.get(definition.key)?.query || ''; search.addEventListener('keydown', event => { if (event.key === 'Enter') event.preventDefault(); }); label.append(search); details.append(label);
                const rows = node('div', undefined, 'gs-config__fields'); details.append(rows);
                search.addEventListener('input', () => renderRows(definition.key,group,rows,search.value.trim().toLocaleLowerCase()));
                renderRows(definition.key,group,rows,search.value.trim().toLocaleLowerCase()); mount.append(details);
            }
            setBusy(busy);
        };
        const validate = () => {
            for (const [key, group] of groups) for (const [id, item] of group.items) {
                if (group.definition.kind !== 'bosses' || !item.selected || !different(item,group.initial.get(id))) continue;
                if (/^(?:0|[1-9][0-9]*)$/.test(item.level) && Number(item.level) <= 255) continue;
                const search = mount.querySelector(`[data-list-search="${key}"]`); if (search) { search.value = ''; search.dispatchEvent(new Event('input')); }
                const control = mount.querySelector(`[data-list="${key}"][data-id="${id}"][data-list-control="level"]`);
                if (control) { control.closest('details').open = true; control.setCustomValidity('Bitte eine ganze Boss-Stufe zwischen 0 und 255 eingeben.'); control.focus(); control.reportValidity(); }
                return false;
            }
            return true;
        };
        return {load,changes,dirtyCount,setBusy,validate};
    };
})();
