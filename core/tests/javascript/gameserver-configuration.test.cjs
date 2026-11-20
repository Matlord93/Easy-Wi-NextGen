'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../public/js/gameserver/configuration-app.js'), 'utf8');

// Constraint validation is a browser dependency here. These tests inject its
// result and verify filtering, focus, retained edits and request behaviour.
async function setup({invalid = () => false, options = [], optionLabels = {}, profile = null, missing = false, createConflict = false, createError = false, available = [], nativeStartup = false, customFiles = false, manualInitialization = false, withCollections = false, withRoles = false, serverTags = null, banLists = null, refreshFields = null} = {}) {
    const writes = [], creates = [], probes = [], events = new Map();
    let exists = !missing;
    let active = null, conflict = false, listDraft = {}, listValid = true, listChanged = () => {}, roleDraft = {}, roleValid = true, roleChanged = () => {};
    class Element {
        constructor(tag) {
            this.tagName = tag; this.children = []; this.dataset = {}; this.attributes = {};
            this.listeners = new Map(); this.value = ''; this.textContent = ''; this.customError = '';
            this.classList = {toggle() {}};
        }
        append(...nodes) { for (const node of nodes) { node.parent = this; this.children.push(node); } }
        replaceChildren(...nodes) { this.children = []; this.append(...nodes); }
        setAttribute(key, value) { this.attributes[key] = value; }
        addEventListener(name, callback) { this.listeners.set(name, callback); }
        click() { return this.fire('click'); }
        fire(name) { return this.listeners.get(name)?.({preventDefault() {}}); }
        closest() { return this.parent; }
        querySelectorAll(selector) {
            const tags = selector.split(',').map(tag => tag.trim());
            return this.children.flatMap(child => [...(tags.includes(child.tagName) ? [child] : []), ...child.querySelectorAll(selector)]);
        }
        setCustomValidity(message) { this.customError = message; }
        focus() { active = this; }
        reportValidity() { return !this.customError; }
        checkValidity() {
            probes.push({value: this.value, required: this.required, step: this.step, min: this.min, max: this.max});
            this.validationMessage = invalid(this) ? 'Browser rejected this number' : '';
            return !this.validationMessage;
        }
    }
    const names = ['files', 'categories', 'fields', 'search', 'form', 'reload', 'save', 'message', 'summary', 'dirty', 'heading', 'count', 'title', 'profile', 'initialize', 'available', 'available-grid', 'lists', 'roles', 'tags', 'bans'];
    const ui = Object.fromEntries(names.map(name => [name, new Element('div')]));
    const fields = [
        {key: 'slots', label: 'Players', category: 'Gameplay', type: 'integer', value: '20', min: 1, max: 20, readonly: false, options: []},
        {key: 'rate', label: 'Rate', category: 'Performance', type: 'decimal', value: '1.0', min: 0.25, max: 1.75, readonly: false, options, option_labels: optionLabels},
        {key: 'legacy', label: 'Legacy', category: 'Other', type: 'integer', value: '-1', min: 1, max: 10, readonly: false, options: []},
        {key: 'server-ip', label: 'Address', category: 'Network', type: 'string', value: '203.0.113.10', min: null, max: null, readonly: true, options: []},
        {key: 'motd', label: 'Title', category: 'Other', type: 'string', value: 'Welcome', min: null, max: null, readonly: false, options: []},
    ].map(field => ({description: '', ...field}));
    const mount = {dataset: {url: '/configuration', csrf: 'synthetic-test-token', nativeStartup: String(nativeStartup)}, querySelector: selector => ui[selector.slice(13, -1)]};
    const startup = new Element('details');
    const context = {
        URL, document: {getElementById: id => id === 'gameserver-configuration' ? mount : id === 'gameserver-startup-settings' ? startup : null, createElement: tag => new Element(tag)},
        Option: class extends Element {constructor(label, value) {super('option'); this.textContent = label; this.value = value;}},
        window: {location: {origin: 'https://panel.test'}, confirm: () => true, addEventListener: (name, handler) => events.set(name, handler)},
        fetch: async (url, request) => {
            let data;
            if (request.method === 'POST') {
                creates.push(JSON.parse(request.body));
                assert.equal(request.headers['X-CSRF-TOKEN'], 'synthetic-test-token');
                if (createError) return {ok:false,status:404,json:async()=>({message:'Update agent'})};
                exists = true;
                if (createConflict) return {ok:false,status:409,json:async()=>({message:'Already created'})};
                return {ok:true,status:201,json:async()=>({saved:true,etag:'created'})};
            }
            if (request.method === 'PUT') {
                writes.push({body: JSON.parse(request.body), headers: request.headers});
                if (conflict) return {ok: false, status: 409, json: async () => ({message: 'Conflict; keep edits'})};
                data = {server_tags: serverTags, ban_lists: banLists, saved: true, etag: 'new-etag', message: 'Saved', role_manager: withRoles ? {entries:[{index:0,name:'Admin'}]} : null, ...(refreshFields ? {fields:refreshFields(fields)} : {})};
            } else {
                if (String(url).includes('?') && !exists) return {ok:true,status:200,json:async()=>({can_initialize:true,initialize_automatically:!manualInitialization,message:'Missing configuration'})};
                data = String(url).includes('?') ? {fields, server_tags: serverTags, ban_lists: banLists, role_manager: withRoles ? {entries:[{index:0,name:'Admin'}]} : null, available_fields: available, etag: 'old-etag', analysed_at: '2026-11-19T12:00:00Z'} : {profile, files: profile?.startup_only && !customFiles ? [] : [{path: 'server.properties'}]};
            }
            return {ok: true, json: async () => data};
        },
    };
    if (withCollections) context.window.EasyWIGameConfigurationLists = (mount, changed) => {
        listChanged = changed;
        return {load: () => {listDraft = {};}, changes: () => listDraft, dirtyCount: () => Object.keys(listDraft).length, setBusy() {}, validate: () => listValid};
    };
    if (withRoles) context.window.EasyWIGameConfigurationRoles = (mount, changed) => {
        roleChanged = changed;
        return {load: () => {roleDraft = {};}, changes: () => roleDraft, dirtyCount: () => Object.keys(roleDraft).length, setBusy() {}, validate: () => roleValid};
    };
    vm.runInNewContext(source, context);
    await new Promise(resolve => setImmediate(resolve));
    const input = label => ui.fields.querySelectorAll('input, select, textarea').find(control => control.parent.children[0].textContent === label);
    return {
        ui, writes, creates, probes, events, input, startup, mount, changeRoles: value => {roleDraft = value; roleChanged();}, validRoles: value => {roleValid = value;}, changeLists: value => {listDraft = value; listChanged();}, validLists: value => {listValid = value;}, active: () => active, conflict: value => {conflict = value;},
        change(label, value) {const control = input(label); assert.ok(control, label); control.value = value; control.fire('input');},
        filter(value) {ui.search.value = value; ui.search.fire('input');},
        category(name) {const button = ui.categories.querySelectorAll('button').find(node => node.children[0].textContent === name); assert.ok(button); button.fire('click');},
        submit: () => ui.form.fire('submit'),
    };
}

test('invalid pending fields hidden by a category are revealed without sending a write', async () => {
    const app = await setup({invalid: probe => probe.value === '21'});
    app.change('Players', '21'); app.change('Rate', '1.5'); app.category('Performance');
    assert.equal(app.input('Players'), undefined);
    await app.submit();
    assert.equal(app.writes.length, 0);
    assert.equal(app.input('Players').value, '21');
    assert.equal(app.input('Rate').value, '1.5');
    assert.equal(app.active(), app.input('Players'));
    assert.match(app.ui.message.textContent, /Players/);
});

test('empty hidden numbers use required validation and clear the search to focus the error', async () => {
    const app = await setup({invalid: probe => probe.value === ''});
    app.change('Players', ''); app.filter('Rate');
    await app.submit();
    assert.equal(app.writes.length, 0);
    assert.equal(app.ui.search.value, '');
    assert.equal(app.active(), app.input('Players'));
    assert.deepEqual(app.probes, [{value: '', required: true, step: '1', min: '1', max: '20'}]);
});

test('numeric select values use decimal limits as well', async () => {
    const app = await setup({options: ['1.0', '2.5'], invalid: probe => probe.value === '2.5'});
    assert.equal(app.input('Rate').tagName, 'select');
    app.change('Rate', '2.5'); await app.submit();
    assert.equal(app.writes.length, 0);
    assert.equal(app.active(), app.input('Rate'));
    assert.deepEqual(app.probes, [{value: '2.5', required: true, step: 'any', min: '0.25', max: '1.75'}]);
});

test('corrected edits survive conflicts and save together without changing booked network values', async () => {
    const app = await setup({invalid: probe => probe.value === '21'});
    app.change('Players', '21'); app.filter('Rate'); await app.submit();
    app.change('Players', '12'); app.change('Rate', '1.5');
    assert.equal(app.input('Players').customError, '');
    assert.equal(app.input('Address').disabled, true);
    app.conflict(true); await app.submit();
    assert.equal(app.input('Players').value, '12');
    assert.equal(app.input('Rate').value, '1.5');
    app.conflict(false); await app.submit();
    assert.equal(app.writes.length, 2);
    assert.deepEqual(app.writes[1].body, {file: 'server.properties', etag: 'old-etag', values: {slots: '12', rate: '1.5'}});
    assert.equal(app.writes[1].headers['X-CSRF-TOKEN'], 'synthetic-test-token');
    assert.equal(app.ui.save.disabled, true);
    assert.equal(app.ui.message.textContent, 'Saved');
});

test('unchanged legacy numbers do not block valid edits and empty strings remain supported', async () => {
    const app = await setup({invalid: probe => probe.value === '-1'});
    assert.equal(app.ui.form.noValidate, true);
    app.change('Rate', '1.25'); app.change('Title', ''); await app.submit();
    assert.equal(app.writes.length, 1);
    assert.deepEqual(app.writes[0].body.values, {rate: '1.25', motd: ''});
    assert.deepEqual(app.probes.map(probe => probe.value), ['1.25']);
    assert.equal(app.ui.save.disabled, true);
});


test('game descriptions and titles come from the selected game profile', async () => {
    const app = await setup({profile: {title: 'Palworld', description: 'Einstellungen aus OptionSettings', startup_only: false}});
    assert.equal(app.ui.title.textContent, 'Palworld · Konfiguration');
    assert.equal(app.ui.profile.textContent, 'Einstellungen aus OptionSettings');
    assert.ok(app.input('Players'));
    assert.notEqual(app.startup.open, true);
});

test('startup-only games open their settings without inventing a configuration file', async () => {
    const app = await setup({profile: {title: 'Valheim', description: 'Servername, Welt und Passwort', startup_only: true}});
    assert.equal(app.ui.title.textContent, 'Valheim · Konfiguration');
    assert.equal(app.startup.open, true);
    assert.equal(app.ui.files.querySelectorAll('button').length, 0);
    assert.equal(app.ui.fields.querySelectorAll('input, select').length, 0);
    assert.equal(app.ui.save.disabled, true);
    assert.match(app.ui.summary.textContent, /Startparameter/);
    assert.equal(app.writes.length, 0);
});


test('a native startup form replaces the empty file editor without opening advanced settings', async () => {
    const app = await setup({profile: {title: 'Valheim', startup_only: true}, nativeStartup: true});
    assert.equal(app.mount.hidden, true);
    assert.notEqual(app.startup.open, true);
    assert.equal(app.writes.length, 0);
    assert.equal(app.creates.length, 0);
});

test('registered custom files remain available alongside a native startup form', async () => {
    const app = await setup({profile: {title: 'Valheim', startup_only: true}, nativeStartup: true, customFiles: true});
    assert.equal(app.mount.hidden, false);
    assert.ok(app.input('Players'));
    assert.equal(app.ui.files.querySelectorAll('button').length, 1);
});

test('opening a missing configuration creates it once and loads its settings', async () => {
    const app = await setup({missing:true});
    assert.deepEqual(app.creates,[{file:'server.properties'}]);
    assert.ok(app.input('Players'));
    assert.equal(app.ui.initialize.hidden,true);
    assert.equal(app.writes.length,0);
});

test('manual host initialization waits for an explicit button action', async () => {
    const app = await setup({missing:true,manualInitialization:true});
    assert.equal(app.creates.length,0);
    assert.equal(app.ui.initialize.hidden,false);
    await app.ui.initialize.fire('click');
    assert.deepEqual(app.creates,[{file:'server.properties',initialize_confirmed:true}]);
    assert.ok(app.input('Players'));
    assert.equal(app.ui.initialize.hidden,true);
});

test('a concurrent creator is followed by reading its file, without overwriting it', async () => {
    const app = await setup({missing:true,createConflict:true});
    assert.equal(app.creates.length,1);
    assert.ok(app.input('Players'));
    assert.equal(app.writes.length,0);
    assert.equal(app.ui.initialize.hidden,true);
});

test('failed creation does not loop and leaves a manual retry available', async () => {
    const app = await setup({missing:true,createError:true});
    assert.equal(app.creates.length,1);
    assert.equal(app.ui.initialize.hidden,false);
    assert.equal(app.ui.initialize.disabled,false);
    assert.match(app.ui.message.textContent,/Update agent/);
    assert.equal(app.writes.length,0);
});

for (const [type, value] of [['string', 'new tag'], ['integer', '24'], ['boolean', 'false']]) {
    test(`an explicitly added ${type} setting is written only after Save`, async () => {
        const field = {key:'new-option',label:'New setting',description:'Documented option',category:'Gameplay',type,value:'',min:null,max:null,readonly:false,options:[]};
        const app = await setup({available:[field]});
        assert.equal(app.ui.available.hidden,false);
        assert.equal(app.writes.length,0);
        app.ui['available-grid'].querySelectorAll('button')[0].click();
        assert.equal(app.writes.length,0);
        assert.equal(app.ui.available.hidden,true);
        app.change('New setting',value);
        await app.submit();
        assert.equal(app.writes.length,1);
        assert.deepEqual(app.writes[0].body.values,{'new-option':value});
    });
}

test('options absent from the file are searchable in their own categories and can be discarded', async () => {
    const difficulty = {key:'z_difficulty', label:'Schwierigkeit', description:'Koop-Kampagne', category:'Spielregeln', type:'string', value:'Normal', min:null, max:null, readonly:false, options:['Easy','Normal','Hard']};
    const app = await setup({available:[difficulty]});
    app.category('Spielregeln');
    assert.equal(app.ui.available.hidden, false);
    app.filter('no match');
    assert.equal(app.ui.available.hidden, true);
    app.filter('z_difficulty');
    assert.equal(app.ui.available.hidden, false);
    app.ui['available-grid'].querySelectorAll('button')[0].click();
    assert.equal(app.active(), app.input('Schwierigkeit'));
    app.category('Network');
    await app.submit();
    assert.equal(app.writes.length, 0);
    assert.equal(app.active(), app.input('Schwierigkeit'));
    app.ui.fields.querySelectorAll('button')[0].click();
    assert.equal(app.ui.save.disabled, true);
    assert.equal(app.ui.available.hidden, false);
    assert.equal(app.active(), app.ui['available-grid'].querySelectorAll('button')[0]);
});

test('native enum labels keep the actual option values in save requests', async () => {
    const app = await setup({options: ['0', '1'], optionLabels: {'0': 'PvE', '1': 'PvP'}});
    const control = app.input('Rate');
    assert.equal(control.children.find(option => option.value === '0').textContent, 'PvE');
    assert.equal(control.children.find(option => option.value === '1').textContent, 'PvP');
    app.change('Rate', '1');
    await app.submit();
    assert.equal(app.writes[0].body.values.rate, '1');
});


test('list-only saves use the same CSRF and ETag checks and clear only after success', async () => {
    const app = await setup({withCollections:true});
    app.changeLists({UnlockedResearchs:{add:[-495424062],remove:[]}});
    assert.equal(app.ui.save.disabled,false);
    await app.submit();
    assert.equal(app.writes.length,1);
    assert.equal(app.writes[0].body.etag,'old-etag');
    assert.deepEqual(app.writes[0].body.values,{});
    assert.deepEqual(app.writes[0].body.list_changes,{UnlockedResearchs:{add:[-495424062],remove:[]}});
    assert.equal(app.ui.save.disabled,true);
});

test('a list conflict retains mixed scalar and list changes for retry', async () => {
    const app = await setup({withCollections:true});
    app.changeLists({UnlockedResearchs:{add:[-495424062],remove:[]}});
    app.change('Title','New title');
    app.conflict(true);await app.submit();
    assert.equal(app.ui.save.disabled,false);
    app.conflict(false);await app.submit();
    assert.deepEqual(app.writes[1].body,app.writes[0].body);
    assert.equal(app.ui.save.disabled,true);
});

test('list changes participate in unload protection and validation', async () => {
    const app = await setup({withCollections:true});
    app.changeLists({VBloodUnitSettings:{upsert:[],remove:[123]}});
    let prevented = false;
    app.events.get('beforeunload')({preventDefault(){prevented=true;}});
    assert.equal(prevented,true);
    app.validLists(false);await app.submit();
    assert.equal(app.writes.length,0);
    assert.equal(app.ui.save.disabled,false);
});


test('refreshed field metadata keeps the renamed role selected and shows saved values', async () => {
    const app = await setup({refreshFields: fields => fields.map(field => ({...field,category:field.category === 'Other' ? 'Rolle: Owner' : field.category,value:field.key === 'motd' ? 'Saved title' : field.value}))});
    app.category('Other');app.change('Title','New title');await app.submit();
    assert.equal(app.ui.heading.textContent,'Rolle: Owner');
    assert.equal(app.input('Title').value,'Saved title');
    assert.equal(app.ui.save.disabled,true);
});


test('role drafts save together with scalar edits and survive a write conflict', async () => {
    const app = await setup({withRoles:true,refreshFields: fields => [...fields,{...fields[4],key:'userGroups.0.name',category:'Rolle: Builders',label:'Rollenname',value:'Builders'}]});
    app.changeRoles({add:[{name:'Builders',password:'synthetic-password',canEditBase:true}],remove:[0]});
    app.change('Title','World name');app.category('Rollen verwalten');
    app.conflict(true);await app.submit();
    assert.equal(app.ui.save.disabled,false);assert.equal(app.ui.heading.textContent,'Rollen verwalten');
    app.conflict(false);await app.submit();
    assert.deepEqual(app.writes[1].body,app.writes[0].body);
    assert.deepEqual(app.writes[1].body.values,{motd:'World name'});
    assert.deepEqual(app.writes[1].body.role_changes.remove,[0]);
    assert.match(app.ui.summary.textContent,/6 Parameter/);
    assert.equal(app.writes[1].headers['X-CSRF-TOKEN'],'synthetic-test-token');
    assert.equal(app.ui.save.disabled,true);
});

test('role-only drafts protect navigation and hidden validation reveals the manager', async () => {
    const app = await setup({withRoles:true});
    app.changeRoles({add:[{name:'',password:''}],remove:[]});app.category('Gameplay');
    let prevented=false;app.events.get('beforeunload')({preventDefault(){prevented=true;}});assert.equal(prevented,true);
    app.validRoles(false);await app.submit();assert.equal(app.writes.length,0);assert.equal(app.ui.heading.textContent,'Rollen verwalten');assert.equal(app.ui.roles.hidden,false);assert.equal(app.ui.fields.hidden,true);
    app.validRoles(true);await app.submit();assert.deepEqual(app.writes[0].body.values,{});assert.equal(app.ui.save.disabled,true);
});


test('Enshrouded tag changes survive a hidden category and a conflict', async () => {
    const app = await setup({serverTags: {groups: {Sprachen: [{key:'German', label:'Deutsch', selected:false}]}, unknown_count:0, message:''}});
    assert.equal(app.ui.tags.hidden, true);
    app.category('Server-Tags');
    const checkbox = app.ui.tags.querySelectorAll('input')[0];
    checkbox.checked = true;
    checkbox.fire('change');
    assert.equal(app.ui.save.disabled, false);
    checkbox.checked = false;
    checkbox.fire('change');
    assert.equal(app.ui.save.disabled, true);
    checkbox.checked = true;
    checkbox.fire('change');
    app.category('Other');
    app.change('Title', 'Edited world');
    app.conflict(true);
    await app.submit();
    assert.equal(app.ui.save.disabled, false);
    assert.deepEqual(app.writes[0].body.tag_changes, {German:true});
    assert.deepEqual(app.writes[0].body.values, {motd:'Edited world'});
    app.conflict(false);
    await app.submit();
    assert.deepEqual(app.writes[1].body, app.writes[0].body);
    assert.equal(app.ui.save.disabled, true);
});


test('Enshrouded bans are paginated and searchable without becoming configuration edits', async () => {
    const entries = Array.from({length:51}, (_, index) => ({display_name:`Player ${index+1}`, character_name:`Traveler ${index+1}`, ban_date:null}));
    const app = await setup({banLists:[{key:'bannedAccounts',list_valid:true,entries,unreadable:0}]});
    assert.equal(app.ui.bans.hidden, true);
    app.category('Spielersperren');
    const rows = () => app.ui.bans.querySelectorAll('tbody')[0].children;
    assert.equal(rows().length, 50);
    app.ui.bans.querySelectorAll('button').find(button => button.textContent === 'Weiter').click();
    assert.equal(rows()[0].children[0].textContent, 'Player 51');
    app.filter('traveler 3');
    assert.equal(rows()[0].children[1].textContent, 'Traveler 3');
    assert.equal(app.ui.save.disabled, true);
    await app.submit();
    assert.equal(app.writes.length, 0);
});
