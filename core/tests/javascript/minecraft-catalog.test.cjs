const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../public/js/minecraft-catalog.js'), 'utf8');

function mount(initialData) {
    const events = [];
    const timers = [];
    const initial = {dataset: {}, textContent: JSON.stringify(initialData)};
    const context = {
        document: {
            currentScript: {dataset: {catalogUrl: '/api/v1/minecraft/catalog'}},
            querySelectorAll: selector => selector.startsWith('[data-minecraft-catalog-initial]') && !initial.dataset.catalogRead ? [initial] : [],
            dispatchEvent: event => events.push(event),
        },
        CustomEvent: class { constructor(type, options) {this.type = type; this.detail = options.detail;} },
        window: {setTimeout: (fn, delay) => {timers.push({fn, delay}); return timers.length;}, clearTimeout: () => {}},
        fetch: async () => {throw Error('temporarily offline');},
    };
    vm.runInNewContext(source, context);
    return {context, events, timers, refresh: context.window.EasyWiMinecraftCatalog.refresh};
}

test('keeps cached versions after a failed request, then publishes new versions automatically', async () => {
    const cached = {catalog: {vanilla: {versions: ['1.21.11']}}, sync: {}};
    const {context, events, timers, refresh} = mount(cached);
    assert.equal(events.length, 1);
    await refresh();
    assert.equal(events.length, 1, 'a failed request must not clear cached options');
    assert.deepEqual(Array.from(events[0].detail.vanilla.versions), ['1.21.11']);
    const next = {catalog: {vanilla: {versions: ['26.1.2', '1.21.11']}}, sync: {updating: true}};
    context.fetch = async (url, options) => {
        assert.equal(url, '/api/v1/minecraft/catalog');
        assert.equal(options.credentials, 'same-origin');
        return {ok: true, status: 200, json: async () => ({data: next})};
    };
    await refresh();
    assert.equal(events.length, 2);
    assert.deepEqual(Array.from(events[1].detail.vanilla.versions), ['26.1.2', '1.21.11']);
    assert.equal(timers.at(-1).delay, 5000);
});

for (const status of [401, 403]) {
    test(`stops polling after authentication is lost (${status})`, async () => {
        const {context, timers, refresh} = mount({catalog: {}, sync: {}});
        let requests = 0;
        context.fetch = async () => {requests++; return {ok: false, status};};
        const count = timers.length;
        await refresh();
        await refresh();
        assert.equal(requests, 1);
        assert.equal(timers.length, count);
    });
}
