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

    const required = domMount.requiredDataset(root, ['urlCommand', 'urlLogs', 'urlHealth']);
    const inlineError = document.getElementById('gs-console-error');
    if (!required.ok) {
        errors.showAll(inlineError, {
            message: `Console mount misconfigured: missing ${required.missing.join(', ')}`,
            error_code: 'MOUNT_CONFIG_MISSING',
            request_id: '',
        });
        return;
    }

    const logEl = document.getElementById('gs-console-log');
    const commandEl = document.getElementById('gs-console-command');
    const sendEl = document.getElementById('gs-console-send');
    const pauseEl = document.getElementById('gs-console-pause');
    const autoScrollEl = document.getElementById('gs-console-autoscroll');
    const clearEl = document.getElementById('gs-console-clear');
    const healthEl = document.getElementById('gs-console-health');

    let polling = true;
    let autoScroll = true;
    let cursor = '';
    let timer = null;
    let reconnectShown = false;

    const appendLine = (line, kind = 'journal') => {
        if (!line) return;
        const shouldStick = autoScroll && (logEl.scrollTop + logEl.clientHeight + 32 >= logEl.scrollHeight);
        const prefix = kind === 'meta' ? '[meta] ' : '';
        logEl.textContent += (logEl.textContent ? '\n' : '') + prefix + line;
        if (shouldStick) {
            logEl.scrollTop = logEl.scrollHeight;
        }
    };

    logEl.addEventListener('scroll', () => {
        if (logEl.scrollTop + logEl.clientHeight + 24 < logEl.scrollHeight && autoScroll) {
            autoScroll = false;
            autoScrollEl.textContent = 'Auto-scroll: Off';
        }
    });

    const setSendLoading = (loading) => {
        sendEl.disabled = loading;
        if (loading) {
            sendEl.dataset.original = sendEl.textContent;
            sendEl.textContent = 'Sending…';
        } else if (sendEl.dataset.original) {
            sendEl.textContent = sendEl.dataset.original;
        }
    };

    const pollLogs = async () => {
        if (!polling) return;
        try {
            const payload = await apiClient.request(`${root.dataset.urlLogs}?cursor=${encodeURIComponent(cursor)}`);
            const data = payload.data || {};
            const lines = Array.isArray(data.lines) ? data.lines : [];
            lines.forEach((entry) => {
                appendLine(entry.text || entry.message || '', entry.stream || 'journal');
            });
            if (typeof data.cursor === 'string' || typeof data.cursor === 'number') {
                cursor = String(data.cursor);
            }
            if (data.meta && data.meta.restarted && !reconnectShown) {
                reconnectShown = true;
                appendLine('Reconnected', 'meta');
            }
            errors.clearInline(inlineError);
        } catch (error) {
            errors.showAll(inlineError, error);
        }
    };

    const schedulePoll = () => {
        if (timer) window.clearInterval(timer);
        timer = window.setInterval(pollLogs, 1500);
    };

    sendEl.addEventListener('click', async () => {
        const command = (commandEl.value || '').trim();
        if (!command) {
            errors.showAll(inlineError, { message: 'Command is required.', error_code: 'INVALID_INPUT', request_id: '' });
            return;
        }

        setSendLoading(true);
        errors.clearInline(inlineError);
        appendLine(`> ${command}`, 'meta');
        try {
            await apiClient.request(root.dataset.urlCommand, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ command }),
            });
            commandEl.value = '';
            errors.showToast({ message: 'Command sent.', error_code: 'OK', request_id: '' }, 1800);
        } catch (error) {
            errors.showAll(inlineError, error);
        } finally {
            setSendLoading(false);
        }
    });

    pauseEl.addEventListener('click', () => {
        polling = !polling;
        pauseEl.textContent = polling ? 'Pause' : 'Resume';
    });

    autoScrollEl.addEventListener('click', () => {
        autoScroll = !autoScroll;
        autoScrollEl.textContent = `Auto-scroll: ${autoScroll ? 'On' : 'Off'}`;
    });

    clearEl.addEventListener('click', () => {
        logEl.textContent = '';
    });

    apiClient.request(root.dataset.urlHealth)
        .then((payload) => {
            const data = payload.data || {};
            if (healthEl) {
                const state = data.unit_active_state || data.running_state || data.instance_status || 'unknown';
                const unit = data.unit_name || 'n/a';
                healthEl.textContent = `Unit: ${unit} · State: ${state}`;
            }
        })
        .catch((error) => errors.showAll(inlineError, error));

    pollLogs();
    schedulePoll();
})();
