const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync(require('node:path').join(__dirname, '../../public/js/core-update-activation.js'), 'utf8');
for (const blocked of [false, true]) {
    test(`reloads completed updates once, storage blocked=${blocked}`, () => {
        const handlers = {};
        const data = new Map();
        let reloads = 0;
        const card = { dataset: { updateKind: 'update', updateJob: '123', updateStatus: 'running' } };
        const context = {
            document: { readyState: 'complete', getElementById: () => card, addEventListener: (event, fn) => handlers[event] = fn },
            window: { location: { reload: () => reloads++ }, sessionStorage: {
                getItem: key => { if (blocked) throw Error('blocked'); return data.get(key); },
                setItem: (key, value) => { if (blocked) throw Error('blocked'); data.set(key, value); }
            } }
        };
        vm.runInNewContext(source, context);
        card.dataset.updateStatus = 'failed'; handlers['htmx:afterSwap']();
        assert.equal(reloads, 0);
        card.dataset.updateKind = 'migrate'; card.dataset.updateStatus = 'success'; handlers['htmx:afterSwap']();
        assert.equal(reloads, 0);
        card.dataset.updateKind = 'update'; handlers['htmx:afterSwap'](); handlers['htmx:afterSwap']();
        assert.equal(reloads, 1);
    });
}
