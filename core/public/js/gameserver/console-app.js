(function () {
    const ns = window.EasyWiGameserver || {};
    const domMount = ns.domMount;
    const apiClient = ns.apiClient;
    const errors = ns.errors;
    if (!domMount || !apiClient || !errors) {
        return;
    }

    const root = domMount.mount('#gameserver-console');
    if (!root) {
        return;
    }

    const required = domMount.requiredDataset(root, ['urlCommand']);
    const inlineError = document.getElementById('gs-console-error');
    if (!required.ok) {
        errors.showAll(inlineError, {
            message: `Console mount misconfigured: missing ${required.missing.join(', ')}`,
            error_code: 'MOUNT_CONFIG_MISSING',
            request_id: '',
        });
        return;
    }

    const streamUrl = (root.dataset.streamUrl || '').trim();
    const streamUnavailableMessage = (root.dataset.streamUnavailableMessage || 'Live stream unavailable.').trim();

    const SCROLLBACK_LIMIT = 1500;
    const logEl = document.getElementById('gs-console-log');
    const commandEl = document.getElementById('gs-console-command');
    const sendEl = document.getElementById('gs-console-send');
    const pauseEl = document.getElementById('gs-console-pause');
    const autoScrollEl = document.getElementById('gs-console-autoscroll');
    const clearEl = document.getElementById('gs-console-clear');
    const healthEl = document.getElementById('gs-console-health');

    let paused = false;
    let autoScroll = true;
    let source = null;
    let reconnectTimer = null;
    let reconnectAttempt = 0;
    let pollTimer = null;
    let logsCursor = null;
    let lines = [];

    const renderLines = () => {
        logEl.textContent = lines.join('\n');
    };

    const appendLine = (line, kind = 'journal') => {
        if (!line || paused) return;
        const shouldStick = autoScroll && (logEl.scrollTop + logEl.clientHeight + 32 >= logEl.scrollHeight);
        const prefix = kind === 'meta' ? '[meta] ' : '';
        lines.push(prefix + line);
        if (lines.length > SCROLLBACK_LIMIT) {
            lines = lines.slice(-SCROLLBACK_LIMIT);
        }
        renderLines();
        if (shouldStick) {
            logEl.scrollTop = logEl.scrollHeight;
        }
    };

    const decodeBase64 = (base64) => {
        try {
            return new TextDecoder('utf-8').decode(Uint8Array.from(atob(base64), (c) => c.charCodeAt(0)));
        } catch (e) {
            return '';
        }
    };

    const scheduleReconnect = () => {
        if (reconnectTimer) {
            return;
        }
        reconnectAttempt += 1;
        const delay = Math.min(10000, 500 * Math.pow(2, Math.min(reconnectAttempt, 6)));
        if (healthEl) {
            healthEl.textContent = `Live stream reconnecting… attempt ${reconnectAttempt}`;
        }
        reconnectTimer = window.setTimeout(() => {
            reconnectTimer = null;
            connect();
        }, delay);
    };

    const loadHealth = async () => {
        if (!root.dataset.urlHealth) {
            return null;
        }

        const payload = await apiClient.request(root.dataset.urlHealth);
        return payload.data || {};
    };

    const loadLogs = async () => {
        if (!root.dataset.urlLogs) {
            return;
        }

        const query = logsCursor ? `?cursor=${encodeURIComponent(logsCursor)}` : '';
        const payload = await apiClient.request(`${root.dataset.urlLogs}${query}`);
        const data = payload.data || {};
        const entries = Array.isArray(data.lines) ? data.lines : [];
        entries.forEach((entry) => appendLine(entry.message || ''));
        logsCursor = data.cursor || logsCursor;
    };

    const connect = () => {
        const url = new URL(streamUrl, window.location.origin);

        if (source) {
            source.close();
        }
        source = new EventSource(url.toString(), { withCredentials: true });
        source.onopen = () => {
            reconnectAttempt = 0;
            errors.clearInline(inlineError);
            if (healthEl) {
                healthEl.textContent = 'Live stream connected';
            }
        };
        source.onmessage = (event) => {
            const payload = JSON.parse(event.data || '{}');
            if (payload.chunk_base64) {
                appendLine(decodeBase64(payload.chunk_base64));
            }
            if (payload.status && healthEl) {
                healthEl.textContent = `State: ${payload.status}`;
            }
            if (payload.cpu !== undefined && healthEl) {
                healthEl.textContent = `CPU: ${payload.cpu}% · RAM: ${payload.ram_mb || 0} MB`;
            }
            errors.clearInline(inlineError);
        };

        source.onerror = () => {
            scheduleReconnect();
            if (reconnectAttempt >= 3) {
                errors.showInline(inlineError, { message: 'Live stream disconnected, reconnecting…', error_code: 'STREAM_RECONNECT', request_id: '' });
            } else {
                errors.clearInline(inlineError);
            }
        };
    };

    const startPollingFallback = async () => {
        if (healthEl) {
            healthEl.textContent = streamUnavailableMessage;
        }

        const poll = async () => {
            try {
                const health = await loadHealth();
                await loadLogs();
                errors.clearInline(inlineError);
                if (healthEl && health && health.running_state) {
                    healthEl.textContent = health.running_state === 'running'
                        ? streamUnavailableMessage
                        : 'Server is offline.';
                }
            } catch (error) {
                if (healthEl) {
                    healthEl.textContent = streamUnavailableMessage;
                }
            }
        };

        await poll();
        pollTimer = window.setInterval(poll, 2500);
    };

    const setSendLoading = (loading) => {
        sendEl.disabled = loading;
        if (loading) {
            sendEl.dataset.original = sendEl.textContent;
            sendEl.textContent = 'Sending…';
        } else if (sendEl.dataset.original) {
            sendEl.textContent = sendEl.dataset.original;
        }
    };

    sendEl.addEventListener('click', async () => {
        const command = (commandEl.value || '').trim();
        if (!command) {
            errors.showAll(inlineError, { message: 'Command is required.', error_code: 'INVALID_INPUT', request_id: '' });
            return;
        }

        setSendLoading(true);
        try {
            await apiClient.request(root.dataset.urlCommand, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': root.dataset.csrfToken || '' },
                body: JSON.stringify({ command, idempotency_key: crypto.randomUUID(), csrf_token: root.dataset.csrfToken || '' }),
            });
            appendLine(`> ${command}`, 'meta');
            commandEl.value = '';
        } catch (error) {
            errors.showAll(inlineError, error);
        } finally {
            setSendLoading(false);
        }
    });

    commandEl.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            sendEl.click();
        }
    });

    pauseEl.addEventListener('click', () => {
        paused = !paused;
        pauseEl.textContent = paused ? 'Resume' : 'Pause';
    });

    autoScrollEl.addEventListener('click', () => {
        autoScroll = !autoScroll;
        autoScrollEl.textContent = `Auto-scroll: ${autoScroll ? 'On' : 'Off'}`;
    });

    clearEl.addEventListener('click', () => {
        lines = [];
        renderLines();
    });

    if (streamUrl) {
        connect();
    } else {
        startPollingFallback();
    }

    window.addEventListener('beforeunload', () => {
        if (source) {
            source.close();
        }
        if (pollTimer) {
            window.clearInterval(pollTimer);
        }
    });
})();
