/* Event-driven console. No polling, no persistent PHP stream, no Relay worker. */
(() => {
    'use strict';
    const ns = window.EasyWiGameserver;
    const root = document.getElementById('gameserver-console');
    if (!root || root.dataset.liveMounted || !ns?.apiClient || !ns?.errors) return;
    root.dataset.liveMounted = '1';
    const byId = name => document.getElementById('gs-console-' + name);
    const log = byId('log'), command = byId('command'), send = byId('send'), health = byId('health'), error = byId('error');
    if (!log || !command || !send || !health) return;
    const de = document.documentElement.lang.startsWith('de');
    const messages = {connected: de ? 'Live verbunden' : 'Live connected', reconnect: de ? 'Live-Verbindung wird hergestellt …' : 'Connecting live console …', unavailable: de ? 'Live-Konsole nicht erreichbar. Bei älteren Instanzen den Gameserver nach dem Agent-Update neu starten und das WebSocket-Routing prüfen.' : 'Live console unavailable. Restart older game instances after updating the agent and check WebSocket routing.', gap: de ? '\n[Ein Teil des Verlaufs ist nicht mehr verfügbar.]\n' : '\n[Some previous output is no longer available.]\n', truncated: de ? '\n[Ältere Ausgabe ausgeblendet.]\n' : '\n[Earlier output hidden.]\n'};
    let socket = null, retry = null, frame = null, attempts = 0, stopped = false, paused = false, autoScroll = true;
    let session = '', seq = 0, text = '', dirty = false;
    let decoder = new TextDecoder('utf-8');
    const MAX_TEXT = 128 * 1024;
    let ansiState = 'text', carriage = false;
    function plain(value) {
        if (ansiState === 'text' && !value.includes('\x1b')) return value.replace(/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/g, '');
        const output = [];
        for (const char of value) {
            if (ansiState === 'esc') { ansiState = char === '[' ? 'csi' : char === ']' ? 'osc' : ['(', ')', '*', '+'].includes(char) ? 'charset' : 'text'; continue; }
            if (ansiState === 'csi') { if (char >= '@' && char <= '~') ansiState = 'text'; continue; }
            if (ansiState === 'osc') { if (char === '\x07') ansiState = 'text'; else if (char === '\x1b') ansiState = 'oscEnd'; continue; }
            if (ansiState === 'oscEnd') { ansiState = char === '\\' ? 'text' : 'osc'; continue; }
            if (ansiState === 'charset') { ansiState = 'text'; continue; }
            if (char === '\x1b') { ansiState = 'esc'; continue; }
            if (char >= ' ' || ['\n', '\r', '\t'].includes(char)) output.push(char);
        }
        return output.join('');
    }
    function render() {
        frame = null;
        if (!dirty) return;
        const stick = autoScroll && log.scrollTop + log.clientHeight + 32 >= log.scrollHeight;
        log.textContent = text; dirty = false;
        if (stick) log.scrollTop = log.scrollHeight;
    }
    function append(value) {
        if (paused) return;
        const cleaned = plain(value);
        if (!carriage && !cleaned.includes('\r')) text += cleaned;
        else for (const char of cleaned) {
            if (carriage) { if (char !== '\n') text = text.slice(0, text.lastIndexOf('\n') + 1); carriage = false; }
            if (char === '\r') carriage = true;
            else text += char;
        }
        if (text.length > MAX_TEXT) text = messages.truncated + text.slice(-MAX_TEXT + messages.truncated.length);
        dirty = true;
        // Drawing follows browser frames; data reception is never timer-polled.
        if (frame === null) frame = requestAnimationFrame(render);
    }
    function connect() {
        if (stopped || document.hidden || !root.isConnected) return;
        const url = new URL(root.dataset.liveUrl, window.location.origin);
        if (url.origin !== window.location.origin) return;
        url.protocol = location.protocol === 'https:' ? 'wss:' : 'ws:';
        if (session) { url.searchParams.set('session', session); url.searchParams.set('cursor', String(seq)); }
        health.textContent = messages.reconnect;
        const current = new WebSocket(url.toString()); socket = current;
        current.onmessage = event => {
            if (socket !== current) return;
            let data; try { data = JSON.parse(event.data); } catch (_) { return; }
            if (data.session_id && data.session_id !== session) { session = data.session_id; seq = 0; decoder = new TextDecoder('utf-8'); ansiState = 'text'; carriage = false; }
            if (data.type === 'ping' || data.status === 'connected') { attempts = 0; health.textContent = messages.connected; ns.errors.clearInline(error); }
            if (data.status === 'history_gap') { decoder = new TextDecoder('utf-8'); ansiState = 'text'; carriage = false; append(messages.gap); }
            if (data.type === 'chunk' && Number.isSafeInteger(data.seq) && data.seq > seq && typeof data.chunk_base64 === 'string' && data.chunk_base64.length <= 5500) {
                if (seq && data.seq !== seq + 1) append(messages.gap);
                seq = data.seq;
                try { append(decoder.decode(Uint8Array.from(atob(data.chunk_base64), c => c.charCodeAt(0)), {stream: true})); } catch (_) {}
                attempts = 0; health.textContent = messages.connected; ns.errors.clearInline(error);
            }
        };
        current.onclose = () => {
            if (socket !== current) return;
            socket = null;
            if (stopped || !root.isConnected) return;
            health.textContent = messages.reconnect;
            attempts++;
            if (attempts >= 3) ns.errors.showInline(error, {message: messages.unavailable, error_code: 'LIVE_UNAVAILABLE'});
            retry = setTimeout(() => { retry = null; connect(); }, Math.min(10000, 250 * 2 ** Math.min(attempts - 1, 6)));
        };
        current.onerror = () => { /* onclose performs bounded reconnect. */ };
    }
    send.addEventListener('click', async () => {
        const value = command.value.trim(); if (!value || send.disabled) return;
        send.disabled = true;
        try {
            await ns.apiClient.request(root.dataset.urlCommand, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': root.dataset.csrfToken || ''}, body: JSON.stringify({command: value, idempotency_key: ns.apiClient.buildRequestId(), csrf_token: root.dataset.csrfToken || ''})});
            command.value = ''; ns.errors.clearInline(error);
        } catch (e) { ns.errors.showAll(error, e); }
        finally { send.disabled = false; }
    });
    command.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); send.click(); } });
    byId('pause')?.addEventListener('click', e => { paused = !paused; e.currentTarget.textContent = paused ? (de ? 'Fortsetzen' : 'Resume') : (de ? 'Pause' : 'Pause'); });
    byId('autoscroll')?.addEventListener('click', e => { autoScroll = !autoScroll; e.currentTarget.textContent = (de ? 'Automatisch scrollen: ' : 'Auto-scroll: ') + (autoScroll ? (de ? 'An' : 'On') : (de ? 'Aus' : 'Off')); });
    byId('clear')?.addEventListener('click', () => { text = ''; dirty = true; render(); });
    function stop() { stopped = true; if (retry) clearTimeout(retry); retry = null; if (frame !== null) cancelAnimationFrame(frame); frame = null; const old = socket; socket = null; old?.close(); }
    document.addEventListener('visibilitychange', () => { if (document.hidden) stop(); else if (root.isConnected) { stopped = false; connect(); } });
    window.addEventListener('pagehide', stop);
    window.addEventListener('pageshow', e => { if (e.persisted) { stopped = false; connect(); } });
    document.addEventListener('htmx:beforeCleanupElement', e => { if (e.target === root || e.target.contains?.(root)) stop(); });
    connect();
})();
