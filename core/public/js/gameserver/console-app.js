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

    const defaultI18n = {
        streamUnavailable: 'Live stream unavailable.',
        pollingActive: 'Live stream unavailable. Polling mode active.',
        streamConnected: 'Live stream connected',
        streamReconnecting: 'Live stream reconnecting… attempt %attempt%',
        relayLost: 'Connection to relay lost. Polling mode active.',
        streamDisconnected: 'Live stream disconnected, reconnecting…',
        serverOffline: 'Server is offline.',
        sending: 'Sending…',
        commandRequired: 'Command is required.',
        commandSent: 'Command sent.',
        installMode: 'Installation log mode active.',
        installSuccess: 'Installation completed successfully.',
        installFailed: 'Installation failed.',
        pause: 'Pause',
        resume: 'Resume',
        autoscrollOn: 'Auto-scroll: On',
        autoscrollOff: 'Auto-scroll: Off',
    };
    let i18n = defaultI18n;
    try {
        i18n = { ...defaultI18n, ...(root.dataset.i18n ? JSON.parse(root.dataset.i18n) : {}) };
    } catch (_) {
        i18n = defaultI18n;
    }
    const tr = (key, replacements = {}) => Object.entries(replacements).reduce(
        (msg, [k, v]) => msg.replaceAll(`%${k}%`, String(v)),
        i18n[key] || defaultI18n[key] || key,
    );

    const SCROLLBACK_LIMIT = 1500;
    const logEl = document.getElementById('gs-console-log');
    const commandEl = document.getElementById('gs-console-command');
    const sendEl = document.getElementById('gs-console-send');
    const pauseEl = document.getElementById('gs-console-pause');
    const autoScrollEl = document.getElementById('gs-console-autoscroll');
    const clearEl = document.getElementById('gs-console-clear');
    const healthEl = document.getElementById('gs-console-health');

    if (!logEl || !commandEl || !sendEl || !pauseEl || !autoScrollEl || !clearEl) {
        errors.showAll(inlineError, {
            message: 'Console UI is not fully available. Please reload the page.',
            error_code: 'MOUNT_DOM_MISSING',
            request_id: '',
        });
        return;
    }

    let paused = false;
    let autoScroll = true;
    let source = null;
    let reconnectTimer = null;
    let reconnectAttempt = 0;
    let reconnectFailures = 0;
    let pollTimer = null;
    let fallbackRetryTimer = null;
    let logsCursor = null;
    let fallbackActive = false;
    let lines = [];
    let relayDisconnectNoticeShown = false;
    let lastHealthyEventAt = 0;
    let lastSeqSeen = -1;
    let lastInstallStatusNotice = null;
    const seenChunkFingerprints = new Set();

    const MAX_STREAM_FAILURES = 5;
    const HEALTHY_EVENT_GRACE_MS = 30000;

    const markStreamHealthy = () => {
        lastHealthyEventAt = Date.now();
        fallbackActive = false;
        if (pollTimer) {
            window.clearInterval(pollTimer);
            pollTimer = null;
        }
        errors.clearInline(inlineError);
        if (healthEl) {
            healthEl.textContent = tr('streamConnected');
        }
    };

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
        if (reconnectTimer || fallbackActive) {
            return;
        }
        reconnectAttempt += 1;
        const delay = Math.min(10000, 500 * Math.pow(2, Math.min(reconnectAttempt, 6)));
        if (healthEl) {
            healthEl.textContent = tr('streamReconnecting', { attempt: reconnectAttempt });
        }
        reconnectTimer = window.setTimeout(() => {
            reconnectTimer = null;
            connect();
        }, delay);
    };

    const activatePollingFallback = async (reason = tr('streamUnavailable'), softFallback = false) => {
        if (fallbackActive) {
            return;
        }

        fallbackActive = true;
        if (reconnectTimer) {
            window.clearTimeout(reconnectTimer);
            reconnectTimer = null;
        }
        if (source) {
            source.close();
            source = null;
        }
        if (softFallback) {
            errors.clearInline(inlineError);
        } else {
            errors.showInline(inlineError, { message: reason, error_code: 'STREAM_FALLBACK', request_id: '' });
        }
        await startPollingFallback();

        if (fallbackRetryTimer) {
            window.clearInterval(fallbackRetryTimer);
        }
        fallbackRetryTimer = window.setInterval(() => {
            if (!streamUrl || !fallbackActive) {
                return;
            }
            fallbackActive = false;
            if (pollTimer) {
                window.clearInterval(pollTimer);
                pollTimer = null;
            }
            connect();
        }, 45000);
    };

    const loadHealth = async () => {
        if (!root.dataset.urlHealth) {
            return null;
        }

        const payload = await apiClient.request(root.dataset.urlHealth);
        return payload.data || {};
    };

    const relayStatusLinePattern = /^status\s+"relay_stale"$/i;
    const relayTypeLinePattern = /^type\s+"status"$/i;
    const relayMessageLinePattern = /^message\s+"console relay heartbeat stale"$/i;
    const relayTimestampLinePattern = /^ts\s+"\d{4}-\d{2}-\d{2}t\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:z|[+-]\d{2}:\d{2})"$/i;

    const normalizeRelayLine = (line) => String(line || '').replace(/\s+/g, ' ').trim();
    const isConsoleAvailableFromHealth = (health) => {
        if (!health || typeof health !== 'object') {
            return false;
        }
        if (health.running_state === 'running') return true;
        if (health.runtime_status === 'running') return true;
        if (health.query_status === 'running') return true;
        if (health.session_active === true) return true;
        if (health.command_session_active === true) return true;
        if (health.can_send_command === true) return true;
        return health.supports_live_output === true && health.live_output_status === 'ok';
    };

    const loadLogs = async () => {
        if (!root.dataset.urlLogs) {
            return;
        }

        const query = logsCursor ? `?cursor=${encodeURIComponent(logsCursor)}` : '';
        const payload = await apiClient.request(`${root.dataset.urlLogs}${query}`);
        const data = payload.data || {};
        const entries = Array.isArray(data.lines) ? data.lines : [];
        if (data.job_type && String(data.job_type).match(/install|reinstall/i) && healthEl) {
            healthEl.textContent = tr('installMode');
        }
        if (data.status && data.job_type && String(data.job_type).match(/install|reinstall/i)) {
            const status = String(data.status).toLowerCase();
            const noticeKey = `${data.job_id || data.job_type}:${status}`;
            if (noticeKey !== lastInstallStatusNotice && (status === 'success' || status === 'completed')) {
                lastInstallStatusNotice = noticeKey;
                appendLine(tr('installSuccess'), 'meta');
            } else if (noticeKey !== lastInstallStatusNotice && (status === 'failed' || status === 'error')) {
                lastInstallStatusNotice = noticeKey;
                appendLine(tr('installFailed'), 'meta');
            }
        }
        entries.forEach((entry) => {
            const message = String(entry.message || '').trim();
            if (!message) {
                return;
            }

            const normalized = normalizeRelayLine(message);
            if (relayTypeLinePattern.test(normalized)
                || relayStatusLinePattern.test(normalized)
                || relayMessageLinePattern.test(normalized)
                || relayTimestampLinePattern.test(normalized)) {
                return;
            }

            if (/^(verbindung geschlossen|connection closed)$/i.test(normalized)) {
                if (!relayDisconnectNoticeShown) {
                    appendLine(tr('relayLost'), 'meta');
                    relayDisconnectNoticeShown = true;
                }
                return;
            }

            appendLine(message);
        });
        logsCursor = data.cursor || logsCursor;
    };

    const connect = () => {
        if (fallbackActive) {
            return;
        }

        let url;
        try {
            url = new URL(streamUrl, window.location.origin);
        } catch (error) {
            void activatePollingFallback(tr('streamUnavailable'));
            return;
        }

        if (source) {
            source.close();
        }
        source = new EventSource(url.toString(), { withCredentials: true });
        source.onopen = () => {
            reconnectAttempt = 0;
            reconnectFailures = 0;
            relayDisconnectNoticeShown = false;
            if (fallbackRetryTimer) {
                window.clearInterval(fallbackRetryTimer);
                fallbackRetryTimer = null;
            }
            markStreamHealthy();
        };
        const handleStreamEvent = (event) => {
            let payload = {};
            try {
                payload = JSON.parse((event && event.data) || '{}');
            } catch (e) {
                return;
            }

            if (payload.type === 'ping' || payload.type === 'status' || payload.type === 'chunk' || payload.chunk_base64) {
                markStreamHealthy();
            }

            if (payload.chunk_base64) {
                const seq = Number(payload.seq ?? -1);
                if (Number.isFinite(seq) && seq >= 0) {
                    if (seq < lastSeqSeen) {
                        return;
                    }
                    lastSeqSeen = Math.max(lastSeqSeen, seq);
                }
                const fp = `${payload.instance_id || ''}:${payload.seq || ''}:${payload.ts || ''}:${payload.chunk_base64}`;
                if (seenChunkFingerprints.has(fp)) {
                    return;
                }
                seenChunkFingerprints.add(fp);
                if (seenChunkFingerprints.size > 4096) {
                    seenChunkFingerprints.clear();
                }
                const decoded = decodeBase64(payload.chunk_base64);
                if (decoded) {
                    appendLine(decoded);
                }
            }
            if (payload.type === 'status' && payload.status) {
                const degradedStatuses = ['backend_not_configured', 'redis_unavailable', 'relay_stale', 'stream_unavailable', 'node_endpoint_missing'];
                if (degradedStatuses.includes(payload.status)) {
                    const message = payload.message || tr('streamUnavailable');
                    void activatePollingFallback(message, payload.status === 'relay_stale');
                    return;
                }
            }
            if (payload.status && healthEl) {
                healthEl.textContent = `State: ${payload.status}`;
            }
            if (payload.cpu !== undefined && healthEl) {
                healthEl.textContent = `CPU: ${payload.cpu}% · RAM: ${payload.ram_mb || 0} MB`;
            }
            if ((payload.type || '') !== 'ping') {
                errors.clearInline(inlineError);
            }
        };
        source.onmessage = handleStreamEvent;
        source.addEventListener('chunk', handleStreamEvent);
        source.addEventListener('status', handleStreamEvent);
        source.addEventListener('ping', handleStreamEvent);

        source.onerror = async () => {
            reconnectFailures += 1;
            const healthyRecently = (Date.now() - lastHealthyEventAt) <= HEALTHY_EVENT_GRACE_MS;
            const health = await loadHealth().catch(() => null);
            const healthLiveOk = !!(health && health.supports_live_output === true && health.live_output_status === 'ok');
            const shouldFallback = !healthyRecently && !healthLiveOk;
            if (source && source.readyState === EventSource.CLOSED) {
                if (shouldFallback) {
                    void activatePollingFallback(tr('streamUnavailable'));
                }
                return;
            }
            if (reconnectFailures >= MAX_STREAM_FAILURES) {
                if (shouldFallback) {
                    void activatePollingFallback(tr('streamUnavailable'));
                }
                return;
            }
            scheduleReconnect();
            if (reconnectAttempt >= 3) {
                errors.showInline(inlineError, { message: tr('streamDisconnected'), error_code: 'STREAM_RECONNECT', request_id: '' });
            } else {
                errors.clearInline(inlineError);
            }
        };
    };

    const startPollingFallback = async () => {
        if (healthEl) {
            healthEl.textContent = tr('pollingActive');
        }

        const poll = async () => {
            try {
                const health = await loadHealth();
                await loadLogs();
                errors.clearInline(inlineError);
                if (healthEl) {
                    if (source && source.readyState === EventSource.OPEN) {
                        healthEl.textContent = tr('streamConnected');
                    } else if (isConsoleAvailableFromHealth(health)) {
                        healthEl.textContent = tr('pollingActive');
                    } else {
                        healthEl.textContent = tr('serverOffline');
                    }
                }
            } catch (error) {
                if (healthEl) {
                    healthEl.textContent = tr('pollingActive');
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
            sendEl.textContent = tr('sending');
        } else if (sendEl.dataset.original) {
            sendEl.textContent = sendEl.dataset.original;
        }
    };

    sendEl.addEventListener('click', async () => {
        const command = (commandEl.value || '').trim();
        if (!command) {
            errors.showAll(inlineError, { message: tr('commandRequired'), error_code: 'INVALID_INPUT', request_id: '' });
            return;
        }

        setSendLoading(true);
        try {
            await apiClient.request(root.dataset.urlCommand, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': root.dataset.csrfToken || '' },
                body: JSON.stringify({ command, idempotency_key: apiClient.buildRequestId(), csrf_token: root.dataset.csrfToken || '' }),
            });
            commandEl.value = '';
            appendLine(tr('commandSent'), 'meta');
            if (fallbackActive) {
                void loadLogs().catch(() => {});
            }
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
        pauseEl.textContent = paused ? tr('resume') : tr('pause');
    });

    autoScrollEl.addEventListener('click', () => {
        autoScroll = !autoScroll;
        autoScrollEl.textContent = autoScroll ? tr('autoscrollOn') : tr('autoscrollOff');
    });

    clearEl.addEventListener('click', () => {
        lines = [];
        renderLines();
    });

    const initializeConsole = async () => {
        if (!streamUrl) {
            await startPollingFallback();
            return;
        }

        try {
            const health = await loadHealth();
            if (health && health.supports_live_output === false) {
                const reason = (health.live_output_message || '').trim() || tr('streamUnavailable');
                await activatePollingFallback(reason);
                return;
            }
        } catch (error) {
            // Ignore health probe errors and try live stream directly.
        }

        connect();
    };

    void initializeConsole();

    window.addEventListener('beforeunload', () => {
        if (source) {
            source.close();
        }
        if (pollTimer) {
            window.clearInterval(pollTimer);
        }
        if (fallbackRetryTimer) {
            window.clearInterval(fallbackRetryTimer);
        }
    });
})();
