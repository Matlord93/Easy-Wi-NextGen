(function () {
    const ns = window.EasyWiGameserver || {};
    const domMount = ns.domMount;
    const apiClient = ns.apiClient;
    const errors = ns.errors;
    if (!domMount || !apiClient || !errors) {
        return;
    }

    const root = domMount.mount('#gameserver-instances-list');
    if (!root) {
        return;
    }

    const inlineError = document.getElementById('gs-instances-list-error');
    const refreshButton = document.getElementById('gs-instances-refresh');
    const baseInterval = Number(root.dataset.pollBaseMs || 12000);
    const backoffSteps = [baseInterval, 30000, 60000];

    const labels = {
        queryUnsupported: root.dataset.labelQueryUnsupported || 'Unsupported',
        queryStatus: {
            online: root.dataset.labelQueryOnline || 'Online',
            running: root.dataset.labelQueryRunning || 'Running',
            starting: root.dataset.labelQueryStarting || 'Starting',
            offline: root.dataset.labelQueryOffline || 'Offline',
            queued: root.dataset.labelQueryQueued || 'Queued',
            unknown: root.dataset.labelQueryUnknown || 'Unknown',
            error: root.dataset.labelQueryError || 'Error',
            crashed: root.dataset.labelQueryCrashed || 'Crashed',
            stopped: root.dataset.labelQueryStopped || 'Stopped',
            hibernating: root.dataset.labelQueryHibernating || 'Hibernating',
            idle: root.dataset.labelQueryIdle || 'Idle',
        },
        queryUnknown: root.dataset.labelQueryUnknown || 'Unknown',
        queryPlayerUnknown: root.dataset.labelQueryPlayerUnknown || 'Unknown',
        powerWorking: root.dataset.labelPowerWorking || 'Working…',
        powerQueued: root.dataset.labelPowerQueued || 'Power action queued',
    };

    const cards = Array.from(root.querySelectorAll('[data-instance-card]')).map((card) => ({
        el: card,
        id: card.dataset.instanceId,
        queryUrl: card.dataset.queryUrl,
        queryHealthUrl: card.dataset.queryHealthUrl,
        powerUrl: card.dataset.powerUrl,
        failCount: 0,
        nextDelay: backoffSteps[0],
        running: false,
        timer: null,
        healthLoaded: false,
    }));

    if (cards.length === 0) {
        return;
    }

    const fmtDate = (value) => {
        if (!value) return '—';
        const parsed = new Date(value);
        return Number.isNaN(parsed.getTime()) ? '—' : parsed.toISOString().slice(0, 16).replace('T', ' ');
    };

    const statusClass = (status) => {
        const normalized = String(status || '').toLowerCase();
        if (['running', 'online'].includes(normalized)) return 'bg-emerald-100 text-emerald-700';
        if (['starting', 'queued'].includes(normalized)) return 'bg-sky-100 text-sky-700';
        if (['stopped', 'offline'].includes(normalized)) return 'bg-amber-100 text-amber-700';
        if (['crashed', 'error'].includes(normalized)) return 'bg-rose-100 text-rose-700';
        if (normalized === 'unsupported') return 'bg-slate-200 text-slate-700';
        return 'bg-slate-100 text-slate-700';
    };

    const setStatusBadge = (cardState, text, stateClass) => {
        const badge = cardState.el.querySelector('[data-query-status]');
        if (!badge) return;
        badge.textContent = text;
        badge.className = `text-xs font-semibold rounded-full px-2 py-1 ${stateClass}`;
    };

    const updateDebug = (cardState, debug = {}) => {
        const map = {
            '[data-debug-host]': debug.resolved_host,
            '[data-debug-port]': debug.resolved_port,
            '[data-debug-protocol]': debug.resolved_protocol,
            '[data-debug-host-source]': debug.resolved_host_source,
            '[data-debug-port-source]': debug.port_source,
            '[data-debug-timeout]': debug.timeout_ms,
            '[data-debug-error]': debug.last_error_code,
        };
        Object.entries(map).forEach(([selector, value]) => {
            const target = cardState.el.querySelector(selector);
            if (target) target.textContent = value ?? '—';
        });
    };

    const applyQuery = (cardState, query = {}, clientLatencyMs = null) => {
        const supported = query.supported !== false;
        if (!supported) {
            setStatusBadge(cardState, labels.queryUnsupported, statusClass('unsupported'));
            const unsupported = cardState.el.querySelector('[data-query-unsupported]');
            if (unsupported) unsupported.classList.remove('hidden');
            cardState.failCount = 0;
            cardState.nextDelay = backoffSteps[0];
            return;
        }

        const statusRaw = String(query.status || '').toLowerCase();
        const status = (statusRaw === '' || statusRaw === 'unknown')
            ? (query.online === true ? 'online' : (query.online === false ? 'offline' : 'unknown'))
            : statusRaw;
        const players = Number.isFinite(Number(query.players?.online)) ? Number(query.players.online) : Number(query.players ?? NaN);
        const maxPlayers = Number.isFinite(Number(query.players?.max)) ? Number(query.players.max) : Number(query.max_players ?? NaN);
        const lastQuery = query.last_query_at || query.checked_at || query.debug?.last_query_at;

        const statusLabel = labels.queryStatus[status] || status;
        setStatusBadge(cardState, statusLabel, statusClass(status));
        cardState.el.querySelector('[data-query-players]')?.replaceChildren(document.createTextNode(Number.isFinite(players) && Number.isFinite(maxPlayers) && maxPlayers > 0 ? `${players} / ${maxPlayers}` : labels.queryPlayerUnknown));
        cardState.el.querySelector('[data-query-map]')?.replaceChildren(document.createTextNode(query.map || '—'));
        const latencyDisplay = Number.isFinite(clientLatencyMs) ? `${Math.round(clientLatencyMs)} ms` : (query.latency_ms != null ? `${query.latency_ms} ms` : '—');
        cardState.el.querySelector('[data-query-latency]')?.replaceChildren(document.createTextNode(latencyDisplay));
        cardState.el.querySelector('[data-query-checked]')?.replaceChildren(document.createTextNode(fmtDate(lastQuery)));

        updateDebug(cardState, query.debug || {});

        const error = cardState.el.querySelector('[data-query-error]');
        if (error) {
            const message = query.debug?.last_error_message || query.error || '';
            error.textContent = message ? `[${query.debug?.last_error_code || 'QUERY_ERROR'}] ${message}` : '';
            error.classList.toggle('hidden', !message);
        }

        if (['online', 'running', 'starting', 'queued'].includes(status)) {
            cardState.failCount = 0;
            cardState.nextDelay = backoffSteps[0];
        }
    };

    const applyFailureBackoff = (cardState, error) => {
        cardState.failCount += 1;
        if (cardState.failCount >= 3) {
            setStatusBadge(cardState, labels.queryUnknown, statusClass('unknown'));
            cardState.nextDelay = cardState.failCount >= 5 ? backoffSteps[2] : backoffSteps[1];
        }
        errors.showAll(inlineError, error);
    };

    const pollCard = async (cardState) => {
        if (cardState.running) return;
        cardState.running = true;
        try {
            const startedAt = window.performance?.now ? window.performance.now() : Date.now();
            const payload = await apiClient.request(cardState.queryUrl);
            const finishedAt = window.performance?.now ? window.performance.now() : Date.now();
            const clientLatencyMs = Number(finishedAt - startedAt);
            applyQuery(cardState, payload.data?.query || {}, clientLatencyMs);
            errors.clearInline(inlineError);
        } catch (error) {
            applyFailureBackoff(cardState, error);
        } finally {
            cardState.running = false;
            cardState.timer = window.setTimeout(() => pollCard(cardState), cardState.nextDelay);
        }
    };

    const loadHealthDebug = async (cardState) => {
        if (!cardState.queryHealthUrl || cardState.healthLoaded) return;
        try {
            const payload = await apiClient.request(cardState.queryHealthUrl);
            updateDebug(cardState, payload.data?.debug || payload.data || {});
            cardState.healthLoaded = true;
        } catch (error) {
            errors.showAll(inlineError, error);
        }
    };

    const sendPower = async (cardState, action, button) => {
        const oldLabel = button.textContent;
        button.disabled = true;
        button.textContent = labels.powerWorking;
        try {
            const payload = await apiClient.request(cardState.powerUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action }),
            });
            errors.showToast({ message: `${labels.powerQueued}: ${action}`, error_code: 'OK', request_id: payload.request_id || '' }, 2000);
            cardState.failCount = 0;
            cardState.nextDelay = backoffSteps[0];
            window.clearTimeout(cardState.timer);
            pollCard(cardState);
        } catch (error) {
            errors.showAll(inlineError, error);
        } finally {
            button.disabled = false;
            button.textContent = oldLabel;
        }
    };

    cards.forEach((cardState, index) => {
        cardState.el.querySelectorAll('[data-power-action]').forEach((button) => {
            button.addEventListener('click', () => sendPower(cardState, button.dataset.powerAction, button));
        });

        const details = cardState.el.querySelector('[data-query-details]');
        if (details) {
            details.addEventListener('toggle', () => {
                if (details.open) {
                    loadHealthDebug(cardState);
                }
            });
        }

        const jitter = Math.floor(Math.random() * 1200) + (index * 150);
        cardState.timer = window.setTimeout(() => pollCard(cardState), jitter);
    });

    if (refreshButton) {
        refreshButton.addEventListener('click', () => {
            cards.forEach((cardState) => {
                cardState.failCount = 0;
                cardState.nextDelay = backoffSteps[0];
                window.clearTimeout(cardState.timer);
                pollCard(cardState);
            });
        });
    }
})();
