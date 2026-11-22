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
            document: { readyState: 'complete', getElementById: id => id === 'core-update-card' ? card : null, addEventListener: (event, fn) => handlers[event] = fn },
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

test('copies updated card values and availability to the overview after a fragment swap', () => {
    const handlers = {};
    const target = () => {
        const classes = new Set();
        return {
            value: '',
            classes,
            replaceChildren(node) { this.value = node.textContent; },
            closest: () => ({classList: {toggle: (name, enabled) => enabled ? classes.add(name) : classes.delete(name)}}),
        };
    };
    const version = target();
    const availability = target();
    const agentCount = target();
    const targets = {
        '[data-update-overview="core-latest"]': version,
        '[data-update-overview="core-status"]': availability,
        '[data-update-overview="agent-updates"]': agentCount,
    };
    const value = (field, textContent, state) => ({
        dataset: {updateOverviewValue: field, updateAvailability: state},
        childNodes: [{cloneNode: () => ({textContent})}],
    });
    const cards = {
        'updates-overview': {querySelector: selector => targets[selector]},
        'core-update-card': {dataset: {}, querySelectorAll: () => [
            value('core-latest', 'v1.0.0-dev.20261121.1', 'current'),
            value('core-status', 'Aktuell'),
        ]},
        'agent-update-card': {querySelectorAll: () => [value('agent-updates', '0', 'current')]},
    };
    vm.runInNewContext(source, {
        document: {readyState: 'complete', getElementById: id => cards[id], addEventListener: (event, fn) => handlers[event] = fn},
        window: {},
    });
    assert.equal(version.value, 'v1.0.0-dev.20261121.1');
    assert.ok(version.classes.has('tkt-kpi--resolved'));

    cards['core-update-card'].querySelectorAll = () => [
        value('core-latest', 'v1.0.0-dev.20261122.1', 'available'),
        value('core-status', 'Update verfügbar'),
    ];
    cards['agent-update-card'].querySelectorAll = () => [value('agent-updates', '—', 'unknown')];
    handlers['htmx:afterSwap']();
    assert.equal(version.value, 'v1.0.0-dev.20261122.1');
    assert.equal(availability.value, 'Update verfügbar');
    assert.ok(version.classes.has('tkt-kpi--open'));
    assert.ok(!version.classes.has('tkt-kpi--resolved'));
    assert.equal(agentCount.value, '—');
    assert.ok(!agentCount.classes.has('tkt-kpi--resolved'));
});
