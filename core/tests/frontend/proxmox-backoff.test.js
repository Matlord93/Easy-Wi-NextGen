const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
(async () => {
    let now = 100000, calls = 0, healthy = false;
    const state = {textContent: ''};
    const panel = {dataset: {pveResourcesUrl: '/resources'}, innerHTML: '', closest: () => ({querySelector: () => state})};
    const document = {hidden: false, documentElement: {lang: 'de'}, addEventListener() {}, activeElement: null, querySelectorAll: selector => selector === '[data-pve-resources-url]' ? [panel] : []};
    const sandbox = {document, Date: class extends Date {static now() {return now;}}, Intl, WeakSet, console, window: {addEventListener() {}, setInterval() {}, setTimeout() {}}, fetch: async () => {calls++; return {ok: healthy, status: healthy ? 200 : 503, json: async () => ({html: 'confirmed inventory'})};}};
    vm.runInNewContext(fs.readFileSync(require.resolve('../../public/js/proxmox.js'), 'utf8'), sandbox);
    const refresh = sandbox.window.EasyWiProxmox.refresh;
    await refresh(); assert.equal(calls, 1);
    now += 5000; await refresh(); assert.equal(calls, 1, 'failure must not repeat every five seconds');
    now += 15000; await refresh(); assert.equal(calls, 2);
    now += 20000; await refresh(); assert.equal(calls, 2, 'second failure must back off for forty seconds');
    now += 20000; healthy = true; await refresh(); assert.equal(calls, 3); assert.equal(panel.innerHTML, 'confirmed inventory'); assert.equal(panel.dataset.pveFailures, undefined);
    now += 20000; document.hidden = true; await refresh(); assert.equal(calls, 3);
    console.log('Proxmox failure backoff and recovery passed');
})().catch(e => {console.error(e); process.exitCode = 1;});
