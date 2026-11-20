(() => {
    'use strict';
    const mount = document.getElementById('gameserver-configuration');
    if (!mount || mount.dataset.mounted) return;
    mount.dataset.mounted = 'true';
    const get = (selector) => mount.querySelector(selector);
    const ui = {files: get('[data-config-files]'), categories: get('[data-config-categories]'), fields: get('[data-config-fields]'), search: get('[data-config-search]'), form: get('[data-config-form]'), reload: get('[data-config-reload]'), save: get('[data-config-save]'), message: get('[data-config-message]'), summary: get('[data-config-summary]'), dirty: get('[data-config-dirty]'), heading: get('[data-config-heading]'), count: get('[data-config-count]')};
    let fields = [], path = '', etag = '', category = '', busy = false;
    const pending = new Map();
    let lists = null, roles = null, roleData = null;
    let listGroups = [];
    const listCategory = 'Fortschritt und V-Blut';
    const roleCategory = 'Rollen verwalten';
    const rolesMount = get('[data-config-roles]');
    const collectionsMount = get('[data-config-lists]');
    const tagsMount = get('[data-config-tags]');
    const tagsCategory = Symbol('Server-Tags');
    const bansMount = get('[data-config-bans]');
    const bansCategory = Symbol('Spielersperren');
    let banLists = null;
    const pendingTags = new Map();
    let serverTags = null;
    const dirtyCount = () => pending.size + pendingTags.size + (lists?.dirtyCount() || 0) + (roles?.dirtyCount() || 0);
    const initialize = get('[data-config-initialize]');
    const availablePanel = get('[data-config-available]');
    const availableGrid = get('[data-config-available-grid]');
    let availableFields = [];
    let initializablePath = '', initializationNeedsConfirmation = false;
    const initializationAttempts = new Set();
    // Filtering removes controls from the form. Validate every changed value
    // explicitly, including hidden fields, without rejecting untouched old values.
    ui.form.noValidate = true;
    const node = (tag, text, className) => { const el = document.createElement(tag); if (text !== undefined) el.textContent = text; if (className) el.className = className; return el; };
    const announce = (message, error = false) => { ui.message.textContent = message; ui.message.className = error ? 'ng-alert ng-alert--danger' : (message ? 'ng-alert ng-alert--success' : ''); ui.message.setAttribute('role', error ? 'alert' : 'status'); };
    const controls = () => {
        ui.save.disabled = busy || dirtyCount() === 0 || !etag;
        ui.reload.disabled = busy;
        availableGrid?.querySelectorAll('button').forEach(button => { button.disabled = busy; });
        ui.fields.querySelectorAll('button').forEach(button => { button.disabled = busy; });
        if (initialize) { initialize.hidden = !initializablePath; initialize.disabled = busy || !initializablePath; }
        ui.dirty.textContent = dirtyCount() ? `${dirtyCount()} ungespeicherte Änderung${dirtyCount() === 1 ? '' : 'en'}` : 'Keine ungespeicherten Änderungen';
        ui.form.setAttribute('aria-busy', String(busy));
        lists?.setBusy(busy);
        roles?.setBusy(busy);
        tagsMount?.querySelectorAll('input').forEach(input => { input.disabled = busy; });
        ui.files.querySelectorAll('button').forEach(button => { button.disabled = busy; });
        ui.fields.querySelectorAll('input, select, textarea').forEach(input => { input.disabled = busy || input.dataset.readonly === 'true'; });
    };
    const request = async (url, options = {}) => {
        const response = await fetch(url, {credentials: 'same-origin', headers: {'Accept': 'application/json', ...(options.headers || {})}, ...options});
        const data = await response.json().catch(() => ({}));
        if (!response.ok) { const error = new Error(data.message || `Die Konfiguration ist gerade nicht erreichbar (${response.status}).`); error.canInitialize = data.can_initialize === true; error.status = response.status; throw error; }
        return data;
    };
    const dirtyConfirm = () => !dirtyCount() || window.confirm('Ungespeicherte Änderungen verwerfen und die Datei neu laden?');
    const updateValue = (field, input) => {
        input.setCustomValidity('');
        const value = field.type === 'boolean' ? String(input.checked) : input.value;
        if (!field.isNew && value === String(field.value)) pending.delete(field.key); else pending.set(field.key, value);
        input.closest('.gs-config__field').classList.toggle('is-dirty', pending.has(field.key));
        controls();
    };
    const loadTags = (data) => {
        serverTags = data || null;
        pendingTags.clear();
        if (!tagsMount) return;
        tagsMount.replaceChildren();
        if (!serverTags) return;
        if (serverTags.message) {
            tagsMount.append(node('p', serverTags.message, 'ng-alert ng-alert--warning'));
            return;
        }
        tagsMount.append(node('p', 'Spieler können die Serverliste im Spiel nach diesen Tags filtern.', 'gs-config__description'));
        if (serverTags.unknown_count > 0) {
            tagsMount.append(node('p', 'Vorhandene Tags außerhalb dieser Auswahl bleiben gespeichert.', 'gs-config__description'));
        }
        for (const [name, tags] of Object.entries(serverTags.groups)) {
            const group = node('fieldset', undefined, 'gs-config__tag-group');
            group.append(node('legend', name));
            for (const tag of tags) {
                const label = node('label', undefined, 'gs-config__tag');
                const input = node('input');
                input.type = 'checkbox';
                input.checked = tag.selected;
                input.addEventListener('change', () => {
                    if (input.checked === tag.selected) pendingTags.delete(tag.key);
                    else pendingTags.set(tag.key, input.checked);
                    controls();
                });
                label.append(input, node('span', tag.label));
                group.append(label);
            }
            tagsMount.append(group);
        }
    };
    const renderBans = query => {
        if (!bansMount) return;
        bansMount.hidden = category !== bansCategory;
        bansMount.replaceChildren();
        if (bansMount.hidden) return;

        bansMount.append(node('p', 'Stand der gespeicherten Serverdatei. Du kannst Sperren im Spiel unter „Social“ verwalten und die Anzeige hier neu analysieren.', 'gs-config__description'));
        if (!banLists.length) {
            bansMount.append(node('p', 'Die Datei enthält keine Sperrliste.', 'gs-config__description'));
        }
        for (const group of banLists) {
            if (banLists.length > 1) bansMount.append(node('h4', group.key));
            if (!group.list_valid) {
                bansMount.append(node('p', 'Diese Sperrliste hat ein unbekanntes Format. Details findest du im Dateimanager.', 'ng-alert ng-alert--warning'));
                continue;
            }
            if (group.unreadable) {
                bansMount.append(node('p', `${group.unreadable} Einträge lassen sich im Setup nicht anzeigen.`, 'gs-config__description'));
            }
            const matches = group.entries.filter(entry => `${entry.display_name} ${entry.character_name}`.toLocaleLowerCase().includes(query));
            if (!matches.length) {
                if (group.entries.length || !group.unreadable) {
                    bansMount.append(node('p', group.entries.length ? 'Keine passenden Sperren gefunden.' : 'Keine Sperren in dieser Liste.', 'gs-config__description'));
                }
                continue;
            }

            const table = node('table', undefined, 'cust-table gs-config__ban-table');
            const head = node('thead');
            const heading = node('tr');
            for (const label of ['Spieler', 'Charakter', 'Gesperrt am']) {
                const cell = node('th', label);
                cell.scope = 'col';
                heading.append(cell);
            }
            head.append(heading);
            const rows = node('tbody');
            table.append(head, rows);
            const wrapper = node('div', undefined, 'cust-ws-table-wrap');
            wrapper.append(table);
            bansMount.append(wrapper);

            const pageSize = 50;
            let start = 0;
            const pagination = node('div', undefined, 'gs-config__ban-pages');
            const previous = node('button', 'Zurück', 'ui-button ui-button--ghost');
            const next = node('button', 'Weiter', 'ui-button ui-button--ghost');
            previous.type = next.type = 'button';
            const count = node('span');
            count.setAttribute('aria-live', 'polite');
            const renderPage = () => {
                rows.replaceChildren();
                for (const entry of matches.slice(start, start + pageSize)) {
                    const date = entry.ban_date ? new Date(entry.ban_date * 1000).toLocaleString() : '—';
                    const row = node('tr');
                    row.append(node('td', entry.display_name || '—'), node('td', entry.character_name || '—'), node('td', date));
                    rows.append(row);
                }
                count.textContent = `${start + 1}–${Math.min(start + pageSize, matches.length)} von ${matches.length}`;
                previous.disabled = start === 0;
                next.disabled = start + pageSize >= matches.length;
            };
            previous.addEventListener('click', () => { start -= pageSize; renderPage(); });
            next.addEventListener('click', () => { start += pageSize; renderPage(); });
            pagination.append(previous, count, next);
            if (matches.length > pageSize) bansMount.append(pagination);
            renderPage();
        }
    };
    const render = () => {
        const fieldKeys = new Set(fields.map(field => field.key));
        const available = availableFields.filter(field => !fieldKeys.has(field.key));
        const choices = [...fields, ...available];
        const categories = [...new Set(choices.map(field => field.category))];
        if (listGroups.length) categories.push(listCategory);
        if (roleData) categories.push(roleCategory);
        if (serverTags) categories.push(tagsCategory);
        if (banLists) categories.push(bansCategory);
        if (category && !categories.includes(category)) category = '';
        ui.categories.replaceChildren();
        for (const name of ['', ...categories]) {
            const count = name === bansCategory ? banLists.reduce((sum, list) => sum + list.entries.length + list.unreadable, 0) : name === tagsCategory ? Object.values(serverTags.groups).flat().length : name === roleCategory ? roleData.entries.length : name === listCategory ? listGroups.length : choices.filter(field => !name || field.category === name).length;
            const button = node('button', '', 'gs-config__category');
            button.type = 'button'; button.setAttribute('aria-pressed', String(category === name));
            button.append(node('span', name === bansCategory ? 'Spielersperren' : name === tagsCategory ? 'Server-Tags' : name || 'Alle Einstellungen'), node('span', String(count), 'gs-config__badge'));
            button.addEventListener('click', () => { category = name; render(); });
            ui.categories.append(button);
        }
        const query = ui.search.value.trim().toLocaleLowerCase();
        const matches = field => (!category || category === field.category) && (!query || `${field.key} ${field.label} ${field.description}`.toLocaleLowerCase().includes(query));
        const visible = fields.filter(matches);
        const additions = available.filter(matches);
        ui.fields.hidden = category === listCategory || category === roleCategory || category === tagsCategory || category === bansCategory;
        if (tagsMount) tagsMount.hidden = !serverTags || category !== tagsCategory;
        if (rolesMount) rolesMount.hidden = !roleData || Boolean(category && category !== roleCategory);
        if (collectionsMount) { collectionsMount.hidden = !listGroups.length || Boolean(category && category !== listCategory); collectionsMount.dataset.categoryOnly = String(category === listCategory); }
        ui.heading.textContent = category === bansCategory ? 'Spielersperren' : category === tagsCategory ? 'Server-Tags' : category || 'Alle Einstellungen'; ui.count.textContent = category === bansCategory || category === tagsCategory ? '' : category === roleCategory ? `${roleData.entries.length} Rollen` : category === listCategory ? `${listGroups.length} Listen` : `${visible.length + additions.length} / ${choices.filter(field => !category || field.category === category).length}`;
        ui.fields.replaceChildren();
        if (!visible.length && !additions.length) ui.fields.append(node('p', choices.length ? 'Keine passenden Einstellungen gefunden.' : 'Keine unterstützten Einstellungen vorhanden. Nach der Erstinstallation erneut analysieren oder eine andere Datei wählen.', 'gs-config__empty'));
        for (const [index, field] of visible.entries()) {
            const card = node('div', undefined, 'gs-config__field'); card.classList.toggle('is-dirty', pending.has(field.key));
            const id = `game-setting-${index}`;
            const label = node('label', field.label, 'form-label'); label.htmlFor = id;
            const help = node('p', field.description, 'gs-config__description'); help.id = `${id}-help`;
            let input;
            if (field.options?.length && field.type !== 'boolean') {
                input = node('select');
                const options = field.options.includes(String(field.value)) ? field.options : [String(field.value), ...field.options];
                for (const value of options) input.append(new Option(field.option_labels?.[value] ?? (value === '' ? 'Wert auswählen …' : value), value));
            } else {
                input = node('input');
                input.type = field.type === 'boolean' ? 'checkbox' : ['integer','decimal'].includes(field.type) ? 'number' : field.type === 'password' ? 'password' : 'text';
                if (field.type === 'boolean') { input.setAttribute('role', 'switch'); input.className = 'gs-config__switch'; }
                if (['integer','decimal'].includes(field.type)) { input.step = field.type === 'integer' ? '1' : 'any'; if (field.min !== null) input.min = String(field.min); if (field.max !== null) input.max = String(field.max); }
                if (field.type === 'password') input.autocomplete = 'new-password';
            }
            input.id = id; input.setAttribute('aria-describedby', help.id); input.dataset.key = field.key; input.dataset.readonly = String(field.readonly); input.disabled = field.readonly || busy;
            const value = pending.get(field.key) ?? String(field.value);
            if (field.type === 'boolean') input.checked = value === 'true'; else { input.className = 'form-input'; input.value = value; }
            input.addEventListener('input', () => updateValue(field, input));
            card.append(label, help, input, node('code', `${path}:${field.key}`, 'gs-config__source'));
            if (field.isNew) {
                const cancel = node('button', 'Entwurf entfernen', 'ui-button ui-button--ghost gs-config__field-action');
                cancel.type = 'button';
                cancel.setAttribute('aria-label', `Entwurf für ${field.label} entfernen`);
                cancel.addEventListener('click', () => {
                    if (busy) return;
                    fields = fields.filter(item => item.key !== field.key);
                    pending.delete(field.key);
                    render();
                    availableGrid?.querySelectorAll('button').forEach(button => { if (button.dataset.key === field.key) button.focus(); });
                    announce('Entwurf entfernt.');
                });
                card.append(cancel);
            }
            if (field.readonly) card.append(node('span', 'Vom Panel verwaltet', 'gs-config__managed'));
            else if (field.max !== null) card.append(node('span', `Erlaubter Bereich: ${field.min ?? '—'} bis ${field.max}`, 'gs-config__managed'));
            ui.fields.append(card);
        }
        renderAvailable(additions);
        renderBans(query);
        controls();
    };
    const validatePending = () => {
        for (const field of fields) {
            if (!pending.has(field.key) || field.readonly) continue;
            const value = pending.get(field.key);
            let message = '';
            if (field.isNew && field.options?.length && !field.options.includes(value)) {
                message = 'Bitte einen Wert auswählen.';
            } else if (['integer', 'decimal'].includes(field.type)) {
                // Use browser number rules for inputs and numeric selects alike.
                const probe = node('input');
                probe.type = 'number'; probe.required = true; probe.step = field.type === 'integer' ? '1' : 'any';
                if (field.min !== null) probe.min = String(field.min);
                if (field.max !== null) probe.max = String(field.max);
                probe.value = value;
                if (!probe.checkValidity()) message = probe.validationMessage || 'Bitte einen gültigen Zahlenwert innerhalb des erlaubten Bereichs eingeben.';
            }
            if (!message) continue;
            category = ''; ui.search.value = ''; render();
            const input = Array.from(ui.fields.querySelectorAll('input, select, textarea')).find(control => control.dataset.key === field.key);
            announce(`Bitte prüfe „${field.label}“: ${message}`, true);
            if (input) { input.setCustomValidity(message); input.focus(); input.reportValidity(); }
            return false;
        }
        return true;
    };
    const load = async (selectedPath) => {
        if (busy || !dirtyConfirm()) return;
        initializablePath = ''; initializationNeedsConfirmation = false; busy = true; controls(); announce('');
        try {
            const url = new URL(mount.dataset.url, window.location.origin); url.searchParams.set('file', selectedPath);
            const data = await request(url);
            if (data.can_initialize) { const missing = new Error(data.message || 'Standardkonfiguration wird vorbereitet.'); missing.canInitialize = true; initializationNeedsConfirmation = data.initialize_automatically === false; throw missing; }
            if (!Array.isArray(data.fields) || !data.etag) throw new Error('Die Datei konnte nicht vollständig gelesen werden. Bitte erneut versuchen.');
            const profile = get('[data-config-profile]');
            if (profile && data.description) profile.textContent = data.description;
            fields = data.fields; path = selectedPath; etag = data.etag; pending.clear();
            listGroups = Array.isArray(data.collections) ? data.collections : [];
            lists?.load(listGroups);
            roleData = data.role_manager || null; roles?.load(roleData);
            loadTags(data.server_tags);
            banLists = data.ban_lists ?? null;
            availableFields = Array.isArray(data.available_fields) ? data.available_fields : [];
            ui.summary.textContent = `${path} · ${fields.length} in der Datei${availableFields.length ? ` · ${availableFields.length} ergänzbar` : ''} · analysiert ${new Date(data.analysed_at).toLocaleString()}`;
            ui.files.querySelectorAll('button').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.file === path)));
            render();
        } catch (error) {
            if (error.canInitialize) {
                initializablePath = selectedPath;
                if (!dirtyCount()) {
                    listGroups = [];
                    lists?.load([]);
                    roleData = null;
                    roles?.load(null);
                    loadTags(null);
                    banLists = null;
                    fields = [];
                    availableFields = [];
                    path = selectedPath;
                    etag = '';
                    render();
                    ui.summary.textContent = `${path} ist noch nicht vorhanden.`;
                }
            }
            announce(error.message, !error.canInitialize);
        }
        finally {
            busy = false; controls();
            if (initialize && initializablePath && !initializationNeedsConfirmation && !dirtyCount() && !initializationAttempts.has(initializablePath)) {
                initializationAttempts.add(initializablePath); initialize.click();
            }
        }
    };
    const renderAvailable = (additions) => {
        if (!availablePanel || !availableGrid) return;
        availablePanel.hidden = additions.length === 0;
        availableGrid.replaceChildren();
        for (const field of additions) {
            const card = node('div', undefined, 'gs-config__field gs-config__field--available');
            const add = node('button', 'Hinzufügen', 'ui-button ui-button--ghost gs-config__field-action');
            add.type = 'button';
            add.dataset.key = field.key;
            add.setAttribute('aria-label', `${field.label} hinzufügen`);
            add.addEventListener('click', () => {
                if (busy || fields.some(item => item.key === field.key)) return;
                const boolean = field.type === 'boolean';
                fields.push({...field, value: '', isNew: true, type: boolean ? 'string' : field.type, options: boolean ? ['true', 'false'] : field.options});
                pending.set(field.key, '');
                render();
                const control = Array.from(ui.fields.querySelectorAll('input, select')).find(input => input.dataset.key === field.key);
                control?.focus();
                announce('Entwurf hinzugefügt. Wähle den Wert und speichere die Änderungen.');
            });
            card.append(node('h5', field.label, 'form-label'), node('p', field.description, 'gs-config__description'), node('code', field.key, 'gs-config__source'), add);
            availableGrid.append(card);
        }
    };
    initialize?.addEventListener('click', async () => {
        if (busy || !initializablePath || !dirtyConfirm()) return;
        const selected = initializablePath; busy = true; controls(); announce('');
        try {
            const data = await request(mount.dataset.url, {method:'POST',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':mount.dataset.csrf},body:JSON.stringify({file:selected,...initializationNeedsConfirmation ? {initialize_confirmed:true} : {}})});
            if (!data.saved || !data.etag) throw new Error('Der Agent hat das Anlegen nicht bestätigt. Bitte den Agenten aktualisieren.');
            pending.clear(); initializablePath = ''; busy = false; await load(selected);
        } catch(error) {
            if (error.status === 409) { initializablePath = ''; busy = false; await load(selected); }
            else announce(error.message, true);
        }
        finally { busy = false; controls(); }
    });
    ui.search.addEventListener('input', render);
    ui.reload.addEventListener('click', () => { if (path) void load(path); else void init(); });
    ui.form.addEventListener('submit', async event => {
        event.preventDefault(); if (busy || !etag || !dirtyCount() || !validatePending()) return;
        if (lists && !lists.validate()) { category = listCategory; ui.search.value = ''; render(); lists.validate(); announce('Bitte die Boss-Stufe prüfen: erlaubt sind ganze Werte zwischen 0 und 255.', true); return; }
        if (roles && !roles.validate()) { category = roleCategory; ui.search.value = ''; render(); roles.validate(); announce('Bitte die Rollenverwaltung prüfen.', true); return; }
        busy = true; controls(); announce('');
        try {
            const payload = {file: path, etag, values: Object.fromEntries(pending)};
            if (lists?.dirtyCount()) payload.list_changes = lists.changes();
            if (roles?.dirtyCount()) payload.role_changes = roles.changes();
            if (pendingTags.size) payload.tag_changes = Object.fromEntries(pendingTags);
            const data = await request(mount.dataset.url, {
                method: 'PUT',
                headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': mount.dataset.csrf},
                body: JSON.stringify(payload),
            });
            if (!data.saved || !data.etag) throw new Error('Der Agent hat das Speichern nicht bestätigt. Deine Eingaben bleiben erhalten.');
            if (Array.isArray(data.fields)) {
                const previousKeys = fields.filter(field => field.category === category).map(field => field.key);
                const replacement = data.fields.find(field => previousKeys.includes(field.key));
                if (replacement) category = replacement.category;
                fields = data.fields;
            } else fields = fields.map(field => pending.has(field.key) ? {...field, value: pending.get(field.key), isNew: false} : field);
            availableFields = availableFields.filter(field => !pending.has(field.key));
            ui.summary.textContent = `${path} · ${fields.length} Parameter${availableFields.length ? ` · ${availableFields.length} ergänzbar` : ''} · gespeichert ${new Date().toLocaleString()}`;
            pending.clear();
            listGroups = Array.isArray(data.collections) ? data.collections : [];
            lists?.load(listGroups, true);
            roleData = data.role_manager || null;
            roles?.load(roleData);
            loadTags(data.server_tags);
            banLists = data.ban_lists ?? null;
            etag = data.etag;
            render();
            announce(data.message || 'Gespeichert. Bitte den Server neu starten.');
        } catch (error) { announce(error.message, true); }
        finally { busy = false; controls(); }
    });
    window.addEventListener('beforeunload', event => { if (dirtyCount()) { event.preventDefault(); event.returnValue = ''; } });
    const init = async () => {
        busy = true; controls();
        try {
            const data = await request(mount.dataset.url);
            const title = get('[data-config-title]'), profile = get('[data-config-profile]');
            if (title && data.profile?.title) title.textContent = `${data.profile.title} · Konfiguration`;
            if (profile) profile.textContent = data.profile?.description || '';
            const nativeStartup = mount.dataset.nativeStartup === 'true';
            mount.hidden = Boolean(nativeStartup && data.profile?.startup_only && !data.files?.length);
            if (data.profile?.startup_only && !nativeStartup) {
                const startup = document.getElementById('gameserver-startup-settings');
                if (startup) startup.open = true;
            }
            ui.files.replaceChildren();
            for (const file of data.files || []) {
                const button = node('button', file.label || file.path, 'gs-config__file'); button.type = 'button'; button.dataset.file = file.path; button.addEventListener('click', () => void load(file.path)); ui.files.append(button);
            }
            busy = false;
            if (data.files?.length) await load(data.files[0].path); else { ui.summary.textContent = data.profile?.startup_only ? 'Dieses Spiel verwendet die unten aufgeführten Startparameter.' : 'Für dieses Spiel sind noch keine Konfigurationsdateien hinterlegt.'; render(); }
        } catch (error) { announce(error.message, true); }
        finally { busy = false; controls(); }
    };
    lists = window.EasyWIGameConfigurationLists?.(collectionsMount, controls) || null;
    roles = window.EasyWIGameConfigurationRoles?.(rolesMount, controls, () => pending) || null;
    void init();
})();
