const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
class Events {
    constructor() { this.handlers = new Map(); }
    addEventListener(name, callback) { this.handlers.set(name, callback); }
    emit(name, value = {}) { return this.handlers.get(name)?.(value); }
}
(async () => {
    const elements = {};
    for (const name of ['log', 'command', 'send', 'health', 'error', 'pause', 'autoscroll', 'clear']) {
        elements[name] = Object.assign(new Events(), {textContent: '', value: '', disabled: false, scrollTop: 0, clientHeight: 100, scrollHeight: 100});
    }
    const root = {dataset: {liveUrl: '/console/live/7', urlCommand: '/instances/7/console/command', csrfToken: 'synthetic-csrf'}, isConnected: true};
    const document = Object.assign(new Events(), {hidden: false, documentElement: {lang: 'de'}, getElementById: id => id === 'gameserver-console' ? root : elements[id.replace('gs-console-', '')]});
    const sockets = [], requests = [], frames = new Map(), timers = new Map();
    let id = 0;
    class Socket {
        constructor(url) { this.url = url; this.closed = false; sockets.push(this); }
        close() { this.closed = true; this.onclose?.(); }
        message(data) { this.onmessage({data: JSON.stringify(data)}); }
    }
    const window = Object.assign(new Events(), {location: {origin: 'https://panel.example.test'}, EasyWiGameserver: {apiClient: {buildRequestId: () => 'synthetic-request', request: async (url, options) => requests.push({url, ...options})}, errors: {clearInline() {}, showInline() {}, showAll() {}}}});
    const sandbox = {window, document, location: {protocol: 'https:'}, WebSocket: Socket, URL, TextDecoder, Uint8Array, atob, console, requestAnimationFrame: callback => {frames.set(++id, callback); return id;}, cancelAnimationFrame: key => frames.delete(key), setTimeout: (callback, delay) => {timers.set(++id, {callback, delay}); return id;}, clearTimeout: key => timers.delete(key)};
    vm.runInNewContext(fs.readFileSync(require.resolve('../../public/js/gameserver/console-live.js'), 'utf8'), sandbox);
    assert.equal(sockets.length, 1); assert.equal(sockets[0].url, 'wss://panel.example.test/console/live/7');
    const render = () => {for (const [key, callback] of [...frames]) {frames.delete(key); callback();}};
    let seq = 0;
    const output = bytes => sockets.at(-1).message({type: 'chunk', session_id: 'a'.repeat(32), seq: ++seq, chunk_base64: Buffer.from(bytes).toString('base64')});
    output('partial-without-newline'); render(); assert.equal(elements.log.textContent, 'partial-without-newline');
    const unicode = Buffer.from('Grüße 🌍'); output(unicode.subarray(0, 3)); output(unicode.subarray(3)); render(); assert.ok(elements.log.textContent.endsWith('Grüße 🌍'));
    output('\x1b[31'); output('mRED\x1b[0m'); render(); assert.ok(elements.log.textContent.endsWith('RED')); assert.ok(!elements.log.textContent.includes('\x1b'));
    for (let i = 0; i < 1000; i++) output('x'.repeat(4096)); render(); assert.ok(elements.log.textContent.length <= 128 * 1024);
    document.hidden = true; document.emit('visibilitychange'); assert.ok(sockets[0].closed); assert.equal(timers.size, 0);
    document.hidden = false; document.emit('visibilitychange'); assert.equal(sockets.length, 2); assert.equal(new URL(sockets[1].url).searchParams.get('cursor'), String(seq));
    elements.command.value = 'status'; await elements.send.emit('click'); assert.equal(requests.length, 1); assert.equal(requests[0].method, 'POST'); assert.equal(requests[0].headers['X-CSRF-Token'], 'synthetic-csrf'); assert.equal(JSON.parse(requests[0].body).idempotency_key, 'synthetic-request'); assert.equal(elements.command.value, ''); assert.equal(elements.send.disabled, false);
    window.emit('pagehide'); assert.ok(sockets[1].closed); window.emit('pageshow', {persisted: true}); assert.equal(sockets.length, 3);
    assert.equal(requests.filter(r => /logs|stream/.test(r.url)).length, 0);
    console.log('Live console: immediate chunks, split UTF-8/ANSI, bounded history, visibility cleanup, resume and protected commands passed');
})().catch(error => {console.error(error); process.exitCode = 1;});
