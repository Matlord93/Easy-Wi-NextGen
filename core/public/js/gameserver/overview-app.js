(function () {
    const ns = window.EasyWiGameserver || {};
    const domMount = ns.domMount;
    const apiClient = ns.apiClient;
    const errors = ns.errors;
    if (!domMount || !apiClient || !errors) {
        return;
    }

    const root = domMount.mount('#gameserver-overview-mount');
    if (!root) {
        return;
    }

    const inlineError = document.getElementById('gameserver-overview-error');
    const stateEl = document.getElementById('gameserver-overview-state');
    const queryCard = document.querySelector('[data-instance-query]');
    const buttons = Array.from(root.querySelectorAll('[data-power-action]'));
    const redirectUrl = root.dataset.powerRedirectUrl || '';
    const queryUrl = root.dataset.urlQuery || '';

    const setLoading = (loading, activeAction = '') => {
        buttons.forEach((button) => {
            const sameAction = button.dataset.powerAction === activeAction;
            button.disabled = loading;
            button.classList.toggle('opacity-60', loading && !sameAction);
            if (loading && sameAction) {
                button.dataset.originalLabel = button.textContent;
                button.textContent = 'Working…';
            } else if (button.dataset.originalLabel) {
                button.textContent = button.dataset.originalLabel;
            }
        });
    };

    const applyState = (data) => {
        if (!stateEl || !data) {
            return;
        }
        const current = data.current_state || 'unknown';
        const desired = data.desired_state || current;
        stateEl.textContent = data.transition ? `${current} → ${desired}` : current;
    };

    const formatCheckedAt = (value) => {
        if (!value) {
            return '—';
        }
        const parsed = new Date(value);
        if (Number.isNaN(parsed.getTime())) {
            return '—';
        }

        const pad = (num) => String(num).padStart(2, '0');

        return `${parsed.getUTCFullYear()}-${pad(parsed.getUTCMonth() + 1)}-${pad(parsed.getUTCDate())} ${pad(parsed.getUTCHours())}:${pad(parsed.getUTCMinutes())}`;
    };

    const updateQueryUi = (query) => {
        if (!queryCard || !query) {
            return;
        }

        const labels = queryCard.dataset.queryStatusLabels ? JSON.parse(queryCard.dataset.queryStatusLabels) : {};
        const statusEls = queryCard.querySelectorAll('[data-query-status]');
        const playersEls = queryCard.querySelectorAll('[data-query-players]');
        const mapEls = queryCard.querySelectorAll('[data-query-map]');
        const versionEls = queryCard.querySelectorAll('[data-query-version]');
        const latencyEls = queryCard.querySelectorAll('[data-query-latency]');
        const checkedEls = queryCard.querySelectorAll('[data-query-checked]');
        const availableEls = queryCard.querySelectorAll('[data-query-players-available]');
        const unavailableEls = queryCard.querySelectorAll('[data-query-players-unavailable]');

        if (query.supported === false) {
            statusEls.forEach((el) => {
                el.textContent = 'Query not supported';
            });
            return;
        }

        const online = query.online;
        const statusKey = online === true ? 'online' : (online === false ? 'offline' : 'unknown');
        const statusLabel = labels[statusKey] || statusKey;
        statusEls.forEach((el) => {
            el.textContent = statusLabel;
        });

        const players = Number.isFinite(Number(query.players?.online)) ? Number(query.players.online) : null;
        const maxPlayers = Number.isFinite(Number(query.players?.max)) ? Number(query.players.max) : null;
        const playersKnown = players !== null && maxPlayers !== null && maxPlayers > 0;
        playersEls.forEach((el) => {
            el.textContent = playersKnown ? `${players} / ${maxPlayers}` : 'Unknown';
        });
        mapEls.forEach((el) => {
            el.textContent = query.map || '—';
        });
        versionEls.forEach((el) => {
            el.textContent = query.version || '—';
        });
        latencyEls.forEach((el) => {
            el.textContent = query.latency_ms != null ? `${query.latency_ms} ms` : '—';
        });
        checkedEls.forEach((el) => {
            const label = el.dataset.queryCheckedLabel || '';
            el.textContent = `${label}: ${formatCheckedAt(query.checked_at)}`;
        });

        const hasPlayers = playersKnown;
        availableEls.forEach((el) => {
            el.classList.toggle('hidden', !hasPlayers);
        });
        unavailableEls.forEach((el) => {
            el.classList.toggle('hidden', hasPlayers);
        });
    };

    const fetchQuery = async () => {
        if (!queryUrl) {
            return;
        }

        try {
            const payload = await apiClient.request(queryUrl);
            errors.clearInline(inlineError);
            updateQueryUi(payload.data?.query || {});
        } catch (error) {
            if (error?.error_code === 'QUERY_UNSUPPORTED') {
                updateQueryUi({ supported: false });
                return;
            }
            errors.showAll(inlineError, error);
            const ctxQuery = error?.context?.query;
            if (ctxQuery) {
                updateQueryUi(ctxQuery);
            }
        }
    };

    buttons.forEach((button) => {
        button.addEventListener('click', async () => {
            errors.clearInline(inlineError);
            const action = button.dataset.powerAction;
            setLoading(true, action);
            try {
                const payload = await apiClient.request(root.dataset.powerUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action }),
                });
                applyState(payload.data || {});
                if (redirectUrl !== '') {
                    window.location.assign(redirectUrl);
                    return;
                }
                errors.showToast({
                    message: `Power action queued: ${action}`,
                    error_code: 'OK',
                    request_id: payload.request_id || '',
                }, 2000);
            } catch (error) {
                errors.showAll(inlineError, error);
            } finally {
                setLoading(false);
            }
        });
    });

    void fetchQuery();
    window.setInterval(fetchQuery, 10000);
})();
