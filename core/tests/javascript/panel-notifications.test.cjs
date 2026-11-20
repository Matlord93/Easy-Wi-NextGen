'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../public/js/panel-notifications.js'), 'utf8');

function list(rows) {
    const root = {rows: rows.map(([state, text]) => ({dataset: {notificationState: state}, textContent: text, hidden: false}))};
    root.search = {value: '', matches: selector => selector === '[data-notification-search]', closest: () => root};
    root.filter = {value: '', matches: selector => selector === '[data-notification-filter]', closest: () => root};
    root.counts = {total: {textContent: ''}, unread: {textContent: ''}, read: {textContent: ''}};
    root.empty = {hidden: true};
    root.querySelectorAll = selector => {
        assert.equal(selector, '[data-notification-row]');
        return root.rows;
    };
    root.querySelector = selector => {
        if (selector === '[data-notification-search]') return root.search;
        if (selector === '[data-notification-filter]') return root.filter;
        if (selector === '[data-notification-empty]') return root.empty;
        const count = selector.match(/^\[data-notification-count="(total|unread|read)"\] strong$/);
        assert.ok(count, selector);
        return root.counts[count[1]];
    };
    return root;
}

function mount(...roots) {
    const listeners = new Map();
    const document = {
        querySelectorAll: selector => {
            assert.equal(selector, '[data-notifications]');
            return roots;
        },
        addEventListener: (name, listener) => listeners.set(name, listener),
    };
    vm.runInNewContext(source, {document});
    const fire = (name, target) => listeners.get(name)({target});
    fire('DOMContentLoaded');
    return fire;
}

test('counts describe loaded rows independently of the selected filter', () => {
    const root = list([['read', 'Invoice paid'], ['unread', 'Server ready']]);
    const fire = mount(root);
    root.filter.value = 'unread';
    fire('change', root.filter);
    assert.deepEqual(Object.values(root.counts).map(node => node.textContent), ['2', '1', '1']);
    assert.deepEqual(root.rows.map(row => row.hidden), [true, false]);
});

test('marking a filtered unread row read updates counts and the empty state after a swap', () => {
    const root = list([['unread', 'Server ready']]);
    const fire = mount(root);
    root.filter.value = 'unread';
    fire('change', root.filter);
    root.rows = [{dataset: {notificationState: 'read'}, textContent: 'Server ready', hidden: false}];
    fire('htmx:afterSwap');
    assert.equal(root.counts.unread.textContent, '0');
    assert.equal(root.counts.read.textContent, '1');
    assert.equal(root.rows[0].hidden, true);
    assert.equal(root.empty.hidden, false);
});

test('search is case insensitive and combines with the read filter', () => {
    const root = list([['read', 'Server ready'], ['unread', 'SERVER rebooted'], ['unread', 'Invoice paid']]);
    const fire = mount(root);
    root.search.value = '  server  ';
    root.filter.value = 'unread';
    fire('input', root.search);
    assert.deepEqual(root.rows.map(row => row.hidden), [true, false, true]);
});

test('clearing a search restores messages and hides the empty state', () => {
    const root = list([['read', 'Invoice paid']]);
    const fire = mount(root);
    root.search.value = 'unmatched';
    fire('input', root.search);
    assert.equal(root.empty.hidden, false);
    root.search.value = '';
    fire('input', root.search);
    assert.equal(root.rows[0].hidden, false);
    assert.equal(root.empty.hidden, true);
});

test('an empty list has zero counts and remains valid after unrelated swaps', () => {
    const root = list([]);
    const fire = mount(root);
    fire('htmx:afterSwap');
    assert.deepEqual(Object.values(root.counts).map(node => node.textContent), ['0', '0', '0']);
    assert.equal(root.empty.hidden, false);
});

test('searching one list leaves another list unchanged', () => {
    const first = list([['unread', 'One']]);
    const second = list([['unread', 'Two']]);
    const fire = mount(first, second);
    first.search.value = 'missing';
    fire('input', first.search);
    assert.equal(first.rows[0].hidden, true);
    assert.equal(second.rows[0].hidden, false);
    assert.equal(second.counts.unread.textContent, '1');
});
