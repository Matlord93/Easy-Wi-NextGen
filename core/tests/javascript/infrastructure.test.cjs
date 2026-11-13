const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../public/js/infrastructure.js'), 'utf8');

function setup() {
    let now = 100000;
    const error = {hidden: true, setAttribute() {this.hidden = true;}, removeAttribute() {this.hidden = false;}};
    const panel = {querySelectorAll: () => [], contains: () => false, dataset: {infraUsageUrl: '/owned-server/usage'}, innerHTML: 'last confirmed usage', parentElement: {querySelector: () => error}};
    const context = {URL, AbortController, WeakMap, WeakSet,
        Date: class extends Date {static now() {return now;}},
        location: {href: 'https://panel.test/server', origin: 'https://panel.test', hash: ''}, history: {},
        setTimeout: () => 1, clearTimeout() {}, setInterval() {},
        window: {addEventListener() {}},
        document: {hidden: false, addEventListener() {}, querySelectorAll: selector => selector === '[data-infra-usage-url]' ? [panel] : []},
        fetch: async () => {throw Error('unavailable');},
    };
    vm.runInNewContext(source, context);
    return {context, panel, error, advance: ms => {now += ms;}, refresh: context.window.EasyWiInfrastructure.refresh};
}

test('metrics outage preserves confirmed readings, backs off and recovers', async () => {
    const {context, panel, error, advance, refresh} = setup(); let calls = 0;
    context.fetch = async (url, options) => {
        calls++; assert.equal(options.credentials, 'same-origin'); assert.equal(options.redirect, 'error'); assert.equal(options.cache, 'no-store');
        return {ok: false, status: 503};
    };
    await refresh(); assert.equal(panel.innerHTML, 'last confirmed usage'); assert.equal(error.hidden, false);
    advance(5000); await refresh(); assert.equal(calls, 1);
    advance(15000); await refresh(); assert.equal(calls, 2);
    advance(20000); await refresh(); assert.equal(calls, 2);
    context.fetch = async () => {calls++; return {ok: true, status: 200, json: async () => ({html: 'new confirmed usage'})};};
    advance(20000); await refresh(); assert.equal(calls, 3); assert.equal(panel.innerHTML, 'new confirmed usage'); assert.equal(error.hidden, true);
});

test('expired authorization stops metric requests and hidden pages stay idle', async () => {
    const {context, advance, refresh} = setup(); let calls = 0;
    context.fetch = async () => {calls++; return {ok: false, status: 403};};
    context.document.hidden = true; await refresh(); assert.equal(calls, 0);
    context.document.hidden = false; await refresh(); advance(600000); await refresh(); assert.equal(calls, 1);
});

test('metrics endpoint cannot send the panel session to another origin', async () => {
    const {context, panel, refresh} = setup(); let calls = 0;
    panel.dataset.infraUsageUrl = 'https://external.test/usage';
    context.fetch = async () => {calls++;};
    await refresh(); assert.equal(calls, 0); assert.equal(panel.innerHTML, 'last confirmed usage');
});
