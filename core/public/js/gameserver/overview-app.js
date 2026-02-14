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
    const buttons = Array.from(root.querySelectorAll('[data-power-action]'));

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
})();
