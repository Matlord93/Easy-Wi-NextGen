'use strict';

const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../../public/js/gameserver/console-live.js'), 'utf8');
const heading = '[UnityMemory] Configuration Parameters - Can be set up in boot.config';

function consoleFor(gameKey) {
    const root = {dataset: {gameKey, liveUrl: '/console/live/1'}, isConnected: true};
    const log = {};
    const elements = new Map([['gameserver-console', root], ['gs-console-log', log]]);
    const frames = [];
    let socket;
    let seq = 0;
    for (const id of ['command', 'send', 'health']) elements.set(`gs-console-${id}`, {addEventListener() {}});
    const origin = 'https://panel.test';
    vm.runInNewContext(source, {
        URL, TextDecoder, Uint8Array, atob,
        location: {protocol: 'https:'},
        window: {
            location: {origin}, addEventListener() {},
            EasyWiGameserver: {apiClient: {}, errors: {clearInline() {}}},
        },
        document: {documentElement: {lang: 'de'}, getElementById: id => elements.get(id), addEventListener() {}},
        WebSocket: class {constructor() { socket = this; }},
        requestAnimationFrame: callback => frames.push(callback),
    });
    return {
        receive(text) {
            socket.onmessage({data: JSON.stringify({type: 'chunk', session_id: 'instance', seq: ++seq, chunk_base64: Buffer.from(text).toString('base64')})});
            frames.splice(0).forEach(frame => frame());
            return log.textContent;
        },
    };
}

for (const gameKey of ['seven_days_to_die', 'seven_days_to_die_windows']) {
    test(`${gameKey} suppresses the Unity memory block across WebSocket chunks`, () => {
        const live = consoleFor(gameKey);
        const prefix = 'Using config file: serverconfig.xml\n';
        assert.equal(live.receive(`${prefix.replace('\n', '\r\n')}${heading.slice(0, 12)}`), prefix);
        assert.equal(live.receive(`${heading.slice(12)}\r\n    "memorysetup-bucket-`), prefix);
        assert.equal(live.receive('allocator-block-size=4194304"\r'), prefix);
        assert.equal(live.receive('\n    "memorysetup-temp-allocator-size-main=4194304"\r\n'), prefix);
        assert.equal(live.receive('[UnityMemory] Warning: allocator initialization failed\r\n'), `${prefix}[UnityMemory] Warning: allocator initialization failed\n`);
        assert.match(live.receive('Error setting "memorysetup-temp-allocator-size-main=4194304"\nINF GameServer started\n'), /Error setting.*\nINF GameServer started\n$/);
    });
}

test('other game consoles retain Unity output and normal game logs', () => {
    const live = consoleFor('rust');
    const output = `${heading}\n    "memorysetup-bucket-allocator-block-size=4194304"\n`;
    assert.equal(live.receive(output), output);
});
