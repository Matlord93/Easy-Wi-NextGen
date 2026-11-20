(() => {
    'use strict';
    window.EasyWIGameConfigurationRoles = (mount, changed, getValues) => {
        if (!mount) return null;
        const permissions = [
            ['canKickBan', 'Spieler entfernen und sperren'],
            ['canAccessInventories', 'Inventare benutzen'],
            ['canEditWorld', 'Welt verändern'],
            ['canEditBase', 'Basis verändern'],
            ['canExtendBase', 'Flammenaltäre erweitern'],
        ];
        let definition = null, drafts = [], removed = new Set(), busy = false, nextId = 0;
        const node = (tag, text, className) => { const el = document.createElement(tag); if (text !== undefined) el.textContent = text; if (className) el.className = className; return el; };
        const button = (text, action) => { const el = node('button', text, 'ui-button ui-button--ghost'); el.type = 'button'; el.dataset.roleControl = 'button'; el.addEventListener('click', () => { if (!busy) action(); }); return el; };
        const dirtyCount = () => drafts.length + removed.size;
        const changes = () => ({add: drafts.map(({id, ...role}) => ({...role, reservedSlots: Number(role.reservedSlots)})), remove: [...removed]});
        const slotCount = () => {
            const pending = getValues();
            const value = pending.has('slotCount') ? pending.get('slotCount') : definition?.slot_count;
            return /^(?:[1-9][0-9]*)$/.test(String(value)) && Number(value) <= definition.max_slots ? Number(value) : null;
        };
        const setBusy = value => {
            busy = value;
            mount.querySelectorAll('[data-role-control]').forEach(control => {
                control.disabled = busy || !definition?.editable || control.dataset.roleControl === 'add' && (drafts.length >= 32 || definition.entries.length - removed.size + drafts.length >= definition.max_roles) || control.dataset.roleControl === 'generate' && !window.crypto?.getRandomValues;
            });
            for (const label of mount.querySelectorAll('[data-role-name]')) {
                const entry = definition?.entries.find(item => String(item.index) === label.dataset.roleName);
                if (entry) label.textContent = getValues().get(`userGroups.${entry.index}.name`) ?? entry.name;
            }
            for (const input of mount.querySelectorAll('[data-role-field="reservedSlots"]')) input.max = String(slotCount() ?? definition.max_slots);
        };
        const field = (card, draft, key, text, type = 'text') => {
            const label = node('label', undefined, 'gs-config__collection-control');
            const input = node('input'); input.type = type; input.dataset.roleControl = 'field'; input.dataset.roleDraft = String(draft.id); input.dataset.roleField = key; input.setAttribute('aria-label', text);
            if (type === 'checkbox') { input.checked = draft[key]; input.className = 'gs-config__switch'; input.setAttribute('role', 'switch'); }
            else { input.className = 'form-input'; input.value = draft[key]; }
            if (type === 'password') input.autocomplete = 'new-password';
            if (key === 'name') input.maxLength = 64;
            if (key === 'reservedSlots') { input.min = '0'; input.max = String(slotCount() ?? definition.max_slots); input.step = '1'; }
            input.addEventListener('input', () => { if (busy) return; input.setCustomValidity(''); draft[key] = type === 'checkbox' ? input.checked : input.value; changed(); });
            label.append(node('span', text), input); card.append(label); return input;
        };
        const render = () => {
            mount.replaceChildren(); mount.hidden = !definition;
            if (!definition) return;
            mount.append(node('h3', 'Rollen verwalten'), node('p', 'Das Rollenpasswort bestimmt beim Beitritt die Rechte. Ein leeres Passwort erlaubt öffentlichen Zugang mit diesen Rechten. Änderungen werden erst mit „Änderungen speichern“ geschrieben und benötigen einen Serverneustart.', 'gs-config__description'));
            if (!definition.editable) { mount.append(node('p', definition.message, 'ng-alert ng-alert--warning')); return; }
            const message = node('p', '', 'ng-alert ng-alert--danger'); message.dataset.roleError = ''; message.hidden = true; message.tabIndex = -1; message.setAttribute('role', 'alert'); mount.append(message);
            const existing = node('div', undefined, 'gs-config__role-entries');
            for (const entry of definition.entries) {
                const label = node('label', undefined, 'gs-config__role-entry');
                const name = node('strong', entry.name); name.dataset.roleName = String(entry.index);
                const input = node('input'); input.type = 'checkbox'; input.dataset.roleControl = 'remove'; input.dataset.roleIndex = String(entry.index); input.checked = removed.has(entry.index); input.setAttribute('aria-label', `${entry.name}: beim Speichern entfernen`);
                input.addEventListener('input', () => { if (busy) return; if (input.checked) removed.add(entry.index); else removed.delete(entry.index); changed(); });
                label.append(name, node('span', entry.public_access ? 'Zugang ohne Passwort' : 'Passwort hinterlegt', 'gs-config__managed'), node('span', 'Beim Speichern entfernen'), input); existing.append(label);
            }
            mount.append(existing);
            const rows = node('div', undefined, 'gs-config__fields');
            for (const draft of drafts) {
                const card = node('div', undefined, 'gs-config__field is-dirty'); card.dataset.roleDraft = String(draft.id);
                card.append(node('h4', 'Neue Rolle'));
                field(card, draft, 'name', 'Rollenname');
                const password = field(card, draft, 'password', 'Rollenpasswort', 'password');
                const generate = button('Passwort erzeugen', () => { const bytes = new Uint8Array(16); window.crypto.getRandomValues(bytes); draft.password = [...bytes].map(value => value.toString(16).padStart(2, '0')).join(''); password.value = draft.password; password.setCustomValidity(''); changed(); }); generate.dataset.roleControl = 'generate';
                const reveal = button('Passwort anzeigen', () => { password.type = password.type === 'password' ? 'text' : 'password'; reveal.textContent = password.type === 'password' ? 'Passwort anzeigen' : 'Passwort verbergen'; });
                const actions = node('div', undefined, 'gs-config__role-actions'); actions.append(generate, reveal); card.append(actions);
                field(card, draft, 'allowPublicAccess', 'Zugang ohne Passwort ausdrücklich erlauben', 'checkbox');
                field(card, draft, 'reservedSlots', 'Reservierte Plätze innerhalb des Spielerlimits', 'number');
                for (const [key, text] of permissions) field(card, draft, key, text, 'checkbox');
                card.append(button('Entwurf entfernen', () => { drafts = drafts.filter(item => item !== draft); render(); changed(); })); rows.append(card);
            }
            mount.append(rows);
            const add = button('+ Neue Rolle', () => { drafts.push({id: nextId++, name: '', password: '', allowPublicAccess: false, reservedSlots: '0', ...Object.fromEntries(permissions.map(([key]) => [key, false]))}); render(); changed(); mount.querySelector(`[data-role-draft="${nextId - 1}"][data-role-field="name"]`)?.focus(); });
            add.dataset.roleControl = 'add'; mount.append(add, node('p', 'Neue Rollen beginnen ohne Rechte. Bestehende Rollen bearbeitest du in ihrer eigenen Kategorie. Mindestens eine Rolle muss erhalten bleiben; maximal 32 neue Rollen je Speichervorgang und 128 Rollen insgesamt.', 'gs-config__description'));
            setBusy(busy);
        };
        const fail = (text, draft = null, key = '') => {
            const message = mount.querySelector('[data-role-error]'); message.textContent = text; message.hidden = false;
            const input = draft ? mount.querySelector(`[data-role-draft="${draft.id}"][data-role-field="${key}"]`) : null;
            if (input) { input.setCustomValidity(text); input.focus(); input.reportValidity(); } else message.focus();
            return false;
        };
        const validate = () => {
            if (!dirtyCount()) return true;
            if (!definition?.editable) return false;
            const count = definition.entries.length - removed.size + drafts.length;
            if (count < 1 || count > definition.max_roles) return fail('Bitte mindestens eine Rolle behalten oder im selben Speichervorgang eine neue Rolle anlegen.');
            const slots = slotCount();
            if (slots === null) return fail('Bitte das Spielerlimit innerhalb der gebuchten Plätze und höchstens 16 ausdrücklich setzen.');
            for (const draft of drafts) {
                if (!draft.name.trim() || Array.from(draft.name.trim()).length > 64 || /[\x00\r\n]/.test(draft.name)) return fail('Bitte einen einzeiligen Rollennamen mit 1 bis 64 Zeichen angeben.', draft, 'name');
                if (!draft.password && !draft.allowPublicAccess) return fail('Bitte ein Rollenpasswort setzen oder öffentlichen Zugang ausdrücklich erlauben.', draft, 'password');
                if (!/^(?:0|[1-9][0-9]*)$/.test(draft.reservedSlots) || Number(draft.reservedSlots) > slots) return fail(`Reservierte Plätze müssen ganze Zahlen zwischen 0 und ${slots} sein.`, draft, 'reservedSlots');
            }
            const message = mount.querySelector('[data-role-error]'); message.hidden = true;
            return true;
        };
        const load = value => { definition = value || null; drafts = []; removed.clear(); render(); };
        return {load, changes, dirtyCount, setBusy, validate};
    };
})();
