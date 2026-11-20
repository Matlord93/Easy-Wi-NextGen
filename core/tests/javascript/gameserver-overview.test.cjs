const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../public/js/gameserver/overview-app.js'), 'utf8');

async function overview() {
    const handlers = new Map(), requests = [], navigation = [], toasts = [], failures = [];
    const state = {textContent: 'stopped'};
    const buttons = ['start', 'stop', 'restart'].map(action => ({
        dataset: {powerAction: action}, disabled: false, textContent: action,
        classList: {toggle() {}},
        addEventListener(event, callback) {handlers.set(action, callback);},
    }));
    const root = {
        isConnected: true,
        // Old cached markup must also stay on the current page.
        dataset: {powerUrl: '/power', powerRedirectUrl: '/instances/1/tasks', urlQuery: '/query'},
        querySelectorAll: () => buttons,
    };
    let resolvePower, rejectPower;
    const window = {
        location: {assign: url => navigation.push(url)},
        setInterval: () => 1, clearInterval() {}, addEventListener() {},
        EasyWiGameserver: {
            domMount: {mount: () => root},
            errors: {clearInline() {}, showToast: notice => toasts.push(notice), showAll: (element, error) => failures.push(error)},
            apiClient: {request: (url, options) => {
                if (url === '/query') return Promise.resolve({data: {query: {online: false}}});
                requests.push({url, action: JSON.parse(options.body).action, method: options.method});
                return new Promise((resolve, reject) => {resolvePower = resolve; rejectPower = reject;});
            }},
        },
    };
    vm.runInNewContext(source, {
        window,
        document: {hidden: false, querySelector: () => null, getElementById: id => id === 'gameserver-overview-state' ? state : {}, addEventListener() {}},
    });
    await Promise.resolve();
    return {buttons, state, requests, navigation, toasts, failures, click: action => handlers.get(action)(), succeed: data => resolvePower({data, request_id: 'power-request'}), fail: error => rejectPower(error)};
}

for (const action of ['start', 'stop', 'restart']) {
    test(`${action} keeps the overview open and shows the accepted power transition`, async () => {
        const app = await overview();
        const pending = app.click(action);
        assert.equal(app.buttons.every(button => button.disabled), true);
        assert.deepEqual(app.requests, [{url: '/power', action, method: 'POST'}]);
        const current = action === 'start' ? 'stopped' : 'running';
        const desired = action === 'stop' ? 'stopped' : 'running';
        app.succeed({current_state: current, desired_state: desired, transition: true, job_id: 'queued-job'});
        await pending;
        assert.equal(app.state.textContent, `${current} → ${desired}`);
        assert.deepEqual(app.navigation, []);
        assert.equal(app.toasts.length, 1);
        assert.match(app.toasts[0].message, new RegExp(action));
        assert.equal(app.toasts[0].request_id, 'power-request');
        assert.equal(app.buttons.every(button => !button.disabled), true);
        assert.equal(app.buttons.find(button => button.dataset.powerAction === action).textContent, action);
        assert.deepEqual(app.failures, []);
    });
}

test('a rejected power action stays visible on the overview without a success notice', async () => {
    const app = await overview();
    const pending = app.click('start');
    const error = new Error('Another power action is already running.');
    app.fail(error);
    await pending;
    assert.deepEqual(app.navigation, []);
    assert.deepEqual(app.failures, [error]);
    assert.equal(app.toasts.length, 0);
    assert.equal(app.state.textContent, 'stopped');
    assert.equal(app.buttons.every(button => !button.disabled), true);
});
