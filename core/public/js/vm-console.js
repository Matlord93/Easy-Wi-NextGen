import RFB from '../lib/novnc/rfb-1.7.0.js';

for (const root of document.querySelectorAll('[data-vm-console]')) {
    const connect = root.querySelector('[data-console-connect]');
    const disconnect = root.querySelector('[data-console-disconnect]');
    const cad = root.querySelector('[data-console-cad]');
    const fullscreen = root.querySelector('[data-console-fullscreen]');
    const status = root.querySelector('[data-console-status]');
    const screen = root.querySelector('[data-console-screen]');
    let rfb = null;
    let connecting = false;
    function controls(active) { disconnect.disabled = cad.disabled = fullscreen.disabled = !active; connect.disabled = active || connecting; }
    connect.addEventListener('click', async () => {
        if (rfb || connecting) return;
        connecting = true; controls(false); status.textContent = root.dataset.connecting;
        try {
            const response = await fetch(root.dataset.ticketUrl, {method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: {'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json'}, body: new URLSearchParams({_token: root.dataset.csrf})});
            if (!response.ok || response.redirected) throw new Error('Console unavailable');
            const grant = await response.json();
            if (grant.websocket_path !== '/vm-console/ws' || !/^[a-f0-9]{64}$/.test(grant.ticket)) throw new Error('Invalid console grant');
            const url = new URL(grant.websocket_path, location.origin); url.protocol = location.protocol === 'https:' ? 'wss:' : 'ws:';
            const current = new RFB(screen, url.href, {wsProtocols: ['binary', 'easywi-console.' + grant.ticket], shared: true});
            grant.ticket = ''; rfb = current; current.scaleViewport = true; current.resizeSession = false; current.focusOnClick = true;
            current.addEventListener('connect', () => {if (rfb !== current) return; connecting = false; status.textContent = root.dataset.connected; controls(true); current.focus();});
            current.addEventListener('disconnect', () => {if (rfb !== current) return; rfb = null; connecting = false; status.textContent = root.dataset.disconnected; controls(false);});
            current.addEventListener('credentialsrequired', () => {current.disconnect(); status.textContent = root.dataset.unavailable;});
            current.addEventListener('securityfailure', () => {status.textContent = root.dataset.unavailable;});
            // A broken proxy must not leave the controls permanently disabled.
            window.setTimeout(() => {if (rfb === current && connecting) current.disconnect();}, 20000);
        } catch (_) { rfb?.disconnect(); rfb = null; connecting = false; status.textContent = root.dataset.unavailable; controls(false); }
    });
    disconnect.addEventListener('click', () => rfb?.disconnect());
    cad.addEventListener('click', () => rfb?.sendCtrlAltDel());
    fullscreen.addEventListener('click', () => { screen.requestFullscreen?.().catch(() => {}); });
    window.addEventListener('pagehide', () => rfb?.disconnect());
}
