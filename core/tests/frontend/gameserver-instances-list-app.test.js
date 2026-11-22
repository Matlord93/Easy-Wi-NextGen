const fs = require('node:fs');
const assert = require('node:assert/strict');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(require.resolve('../../public/js/gameserver/instances-list-app.js'), 'utf8');
assert.ok(source.includes('#gameserver-instances-list'), 'instances list must mount root');
assert.ok(source.includes('data-instance-card'), 'instances list must scan cards');
assert.ok(source.includes('backoffSteps = [baseInterval, 30000, 60000]'), 'instances list must use failure backoff');
assert.ok(source.includes('cardState.failCount >= 3'), 'instances list should switch to unknown after repeated failures');
assert.ok(source.includes('gs-instances-refresh'), 'instances list should support refresh now button');
assert.ok(source.includes('errors.showAll(inlineError, error)'), 'instances list should render errors via shared helper');
assert.ok(source.includes('request_id'), 'instances list should surface request id through shared errors helper');

function mountList(servers, rejected = []) {
    const timers = [];
    const restarts = [];
    const errors = [];
    const button = (disabled = false) => ({
        disabled,
        textContent: 'Restart',
        addEventListener(_, click) { this.click = click; },
    });
    const all = button();
    const selected = button();
    const status = {hidden: true, textContent: ''};
    const cards = servers.map(server => {
        const restart = button(!server.running || server.blocked);
        const selection = {checked: server.selected, disabled: restart.disabled};
        return {
            dataset: {
                queryUrl: '/query/' + server.id,
                powerUrl: '/power/' + server.id,
                powerBlocked: server.blocked ? '1' : '0',
            },
            restart,
            selection,
            querySelector(selector) {
                if (selector === '[data-power-action="restart"]') return restart;
                if (selector === '[data-bulk-select-restart]') return selection;
                return null;
            },
            querySelectorAll() { return [restart]; },
        };
    });
    const ids = {
        'gs-instances-restart-all': all,
        'gs-instances-restart-selected': selected,
        'gs-instances-restart-status': status,
    };
    vm.runInNewContext(source, {
        document: {getElementById: id => ids[id]},
        window: {
            EasyWiGameserver: {
                domMount: {mount: () => ({dataset: {}, querySelectorAll: () => cards})},
                apiClient: {
                    async request(url, options) {
                        const id = url.split('/').at(-1);
                        if (options?.method !== 'POST') {
                            const server = servers.find(item => item.id === id);
                            return {data: {query: {
                                status: server.liveStatus,
                                checked_at: new Date(Date.now() - (server.stale ? 120000 : 0)).toISOString(),
                            }}};
                        }
                        assert.equal(JSON.parse(options.body).action, 'restart');
                        restarts.push(id);
                        if (rejected.includes(id)) throw new Error('Unavailable');
                        return {};
                    },
                },
                errors: {
                    showAll(_, error) { errors.push(error.message); },
                    clearInline() {},
                },
            },
            setTimeout(fn) { timers.push(fn); },
            clearTimeout() {},
        },
    });

    return {all, selected, status, cards, timers, restarts, errors};
}

test('bulk restart includes gameservers that came online while keeping blocked and stale entries disabled', async () => {
    const list = mountList([
        {id: '1', liveStatus: 'online'},
        {id: '2', running: true, liveStatus: 'online'},
        {id: '3', blocked: true, liveStatus: 'online'},
        {id: '4', liveStatus: 'online', stale: true},
        {id: '5', liveStatus: 'offline'},
    ], ['2']);

    await Promise.all(list.timers.splice(0).map(poll => poll()));
    assert.equal(list.cards[0].selection.disabled, false);
    assert.equal(list.cards[2].restart.disabled, true);
    assert.equal(list.cards[3].restart.disabled, true);
    const queue = list.all.click();
    assert.equal(list.all.disabled, true);
    assert.equal(list.selected.disabled, true);
    list.selected.click();
    await queue;

    assert.deepEqual(list.restarts, ['1', '2']);
    assert.deepEqual(list.errors, ['Unavailable']);
    assert.equal(list.status.hidden, false);
    assert.equal(list.status.textContent, '1 restarts queued, 1 failed.');
    assert.equal(list.all.disabled, false);
});

test('restart selected leaves a rejected server selected for retry', async () => {
    const list = mountList([
        {id: '1', running: true, selected: true},
        {id: '2', running: true, selected: true},
        {id: '3', running: true, selected: false},
    ], ['2']);
    await list.selected.click();
    assert.deepEqual(list.restarts, ['1', '2']);
    assert.equal(list.cards[0].selection.checked, false);
    assert.equal(list.cards[1].selection.checked, true);
});

test('a temporary online report expires when the game query returns offline again', async () => {
    const server = {id: '1', liveStatus: 'online'};
    const list = mountList([server]);
    await list.timers.shift()();
    assert.equal(list.cards[0].restart.disabled, false);

    server.liveStatus = 'offline';
    await list.timers.shift()();
    assert.equal(list.cards[0].restart.disabled, true);
    await list.all.click();
    assert.deepEqual(list.restarts, []);
});
