const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

(async () => {
    const source = fs.readFileSync(require.resolve('../../public/js/gameserver/console-app.js'), 'utf8');

    const elements = new Map();
    const createElement = (id, initial = {}) => ({
        id,
        textContent: initial.textContent || '',
        value: initial.value || '',
        dataset: initial.dataset || {},
        disabled: false,
        scrollTop: 0,
        clientHeight: 200,
        scrollHeight: 200,
        addEventListener: () => {},
    });

    elements.set('gs-console-error', createElement('gs-console-error'));
    elements.set('gs-console-log', createElement('gs-console-log'));
    elements.set('gs-console-command', createElement('gs-console-command'));
    elements.set('gs-console-send', createElement('gs-console-send', { textContent: 'Send' }));
    elements.set('gs-console-pause', createElement('gs-console-pause'));
    elements.set('gs-console-autoscroll', createElement('gs-console-autoscroll'));
    elements.set('gs-console-clear', createElement('gs-console-clear'));

    const root = {
        dataset: {
            urlCommand: '/api/instances/7/console/command',
            urlLogs: '/api/instances/7/console/logs',
            urlHealth: '/api/instances/7/console/health',
        },
    };

    let pollCount = 0;
    let timerFn = null;

    const context = {
        window: {
            EasyWiGameserver: {
                domMount: {
                    mount: () => root,
                    requiredDataset: () => ({ ok: true, missing: [] }),
                },
                apiClient: {
                    request: async (url) => {
                        if (url.startsWith('/api/instances/7/console/logs')) {
                            pollCount += 1;
                            return {
                                data: {
                                    cursor: pollCount,
                                    lines: [
                                        { created_at: '2026-01-01T00:00:00Z', message: '--- journalctl gs-7 (live) ---' },
                                        { created_at: '2026-01-01T00:00:00Z', message: `tick-${pollCount}` },
                                    ],
                                },
                            };
                        }
                        return { data: { can_send_command: true } };
                    },
                },
                errors: {
                    showAll: () => {},
                    clearInline: () => {},
                    showToast: () => {},
                },
            },
            setInterval: (fn) => {
                timerFn = fn;
                return 1;
            },
            clearInterval: () => {},
        },
        document: {
            getElementById: (id) => elements.get(id) || null,
        },
        console,
    };

    vm.runInNewContext(source, context, { filename: 'console-app.js' });
    await new Promise((resolve) => setImmediate(resolve));
    await timerFn();

    const output = elements.get('gs-console-log').textContent;
    assert.ok(output.includes('tick-1'), 'first poll output missing');
    assert.ok(output.includes('tick-2'), 'second poll output missing');
    assert.ok(!output.includes('--- journalctl '), 'marker line leaked into console output');
})();

console.log('gameserver-console marker filter test passed');
