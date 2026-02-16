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

    let polling = true;
    let autoScroll = true;
    let cursor = 0;
    let timer = null;

    const appendLine = (line) => {
        if (!line) return;
        if (line.includes('--- journalctl ') || line.toLowerCase().includes('console restarted')) {
            return;
        }
        const shouldStick = autoScroll && (logEl.scrollTop + logEl.clientHeight + 32 >= logEl.scrollHeight);
        logEl.textContent += (logEl.textContent ? '\n' : '') + line;
        if (shouldStick) {
            logEl.scrollTop = logEl.scrollHeight;
        }
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

    const pollLogs = async () => {
        if (!polling) {
            return;
        }
        try {
            const payload = await apiClient.request(`${root.dataset.urlLogs}?cursor=${encodeURIComponent(cursor)}`);
            const data = payload.data || {};
            const lines = Array.isArray(data.lines) ? data.lines : [];
            lines.forEach((entry) => appendLine(`${entry.created_at || ''} ${entry.message || ''}`.trim()));
            if (typeof data.cursor === 'number') {
                cursor = data.cursor;
            }
            errors.clearInline(inlineError);
        } catch (error) {
            errors.showAll(inlineError, error);
        }
    };

    const schedulePoll = () => {
        if (timer) {
            window.clearInterval(timer);
        }
        timer = window.setInterval(pollLogs, 2000);
    };

    sendEl.addEventListener('click', async () => {
        const command = (commandEl.value || '').trim();
        if (!command) {
            errors.showAll(inlineError, {
                message: 'Command is required.',
                error_code: 'INVALID_INPUT',
                request_id: '',
            });
            return;
        }

        setSendLoading(true);
        errors.clearInline(inlineError);
        try {
            const payload = await apiClient.request(root.dataset.urlCommand, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ command }),
            });
            appendLine(`> ${command}`);
            commandEl.value = '';
            errors.showToast({
                message: payload.data?.message || 'Command queued.',
                error_code: 'OK',
                request_id: payload.request_id || '',
            }, 2000);
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
            const canSend = payload.data?.can_send_command;
            if (canSend === false) {
                errors.showAll(inlineError, {
                    message: 'Instance is offline for console commands.',
                    error_code: 'INSTANCE_OFFLINE',
                    request_id: payload.request_id || '',
                });
            }
        })
        .catch((error) => {
            errors.showAll(inlineError, error);
        });

    pollLogs();
    schedulePoll();
})();
