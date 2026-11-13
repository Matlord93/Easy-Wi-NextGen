const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../public/js/proxmox.js'), 'utf8');

function setup({resource = false, task = false} = {}) {
    const handlers = {};
    const state = {textContent: 'cached state'};
    const label = {textContent: 'fresh'};
    const row = {dataset: {pveTaskUrl: '/tasks/1'}, querySelector: () => state};
    const panel = {dataset: {pveResourcesUrl: '/resources'}, innerHTML: '<p>cached VM</p>', closest: () => ({querySelector: () => label})};
    const timeout = [];
    const forms = [];
    const context = {
        document: {hidden: false, documentElement: {lang: 'de'}, activeElement: null,
            addEventListener: (name, fn) => handlers[name] = fn,
            querySelectorAll: (selector) => {
                if (selector === '[data-pve-labels]') return [{textContent: JSON.stringify({running: 'Running', succeeded: 'Completed', updated: 'Updated {time}', refresh_failed: 'Unavailable'})}];
                if (selector === '[data-pve-task-url]') return task && row.dataset.pveTaskUrl ? [row] : [];
                if (selector === '[data-pve-resources-url]') return resource ? [panel] : [];
                if (selector === 'form') return forms;
                return [];
            }},
        window: {addEventListener: () => {}, setInterval: () => {}, setTimeout: fn => timeout.push(fn), location: {reload: () => {throw Error('unexpected reload');}}},
        fetch: async () => {throw Error('offline');},
    };
    vm.runInNewContext(source, context);
    return {context, panel, label, row, state, timeout, handlers, forms, refresh: context.window.EasyWiProxmox.refresh};
}

test('resource outage preserves loaded VMs and marks the data as stale', async () => {
    const {refresh, panel, label} = setup({resource: true});
    await refresh();
    assert.equal(panel.innerHTML, '<p>cached VM</p>');
    assert.equal(label.textContent, 'Unavailable');
});

test('successful resource refresh uses authenticated GET and updates the view', async () => {
    const {context, refresh, panel, label} = setup({resource: true});
    context.fetch = async (url, options) => {
        assert.equal(url, '/resources'); assert.equal(options.credentials, 'same-origin'); assert.equal(options.redirect, 'error');
        return {ok: true, status: 200, json: async () => ({html: '<p>new VM</p>'})};
    };
    await refresh();
    assert.equal(panel.innerHTML, '<p>new VM</p>'); assert.match(label.textContent, /^Updated /);
});

test('task completion does not reload while the user edits a form', async () => {
    const {context, refresh, handlers, forms, state, timeout} = setup({task: true});
    const form = {}; forms.push(form); handlers.input({target: {form}});
    context.fetch = async () => ({ok: true, status: 200, json: async () => ({state: 'succeeded', active: false, result: 'OK'})});
    await refresh(); assert.equal(state.textContent, 'Completed'); assert.equal(timeout.length, 0);
});

test('unconfirmed task response never reports completion or reloads the page', async () => {
    const {context, refresh, state, timeout} = setup({task: true});
    context.fetch = async () => ({ok: true, status: 200, json: async () => ({})});
    await refresh(); assert.equal(state.textContent, 'cached state'); assert.equal(timeout.length, 0);
});

test('authentication loss stops further polling', async () => {
    const {context, refresh} = setup({task: true}); let calls = 0;
    context.fetch = async () => {calls++; return {ok: false, status: 401};};
    await refresh(); await refresh(); assert.equal(calls, 1);
});

test('an uncertain locked task stops polling without reporting successful completion', async () => {
    const {context, refresh, state, timeout, row} = setup({task: true}); let calls = 0;
    context.fetch = async () => { calls++; return {ok: true, status: 200, json: async () => ({state: 'uncertain', active: true, poll: false, result: 'dispatch_unknown'})}; };
    await refresh(); await refresh();
    assert.equal(calls, 1); assert.equal(row.dataset.pveTaskUrl, undefined);
    assert.match(state.textContent, /uncertain/); assert.doesNotMatch(state.textContent, /Completed/);
    assert.equal(timeout.length, 1);
});
