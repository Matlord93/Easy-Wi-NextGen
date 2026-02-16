(function () {
    const ns = window.EasyWiGameserver || {};
    const domMount = ns.domMount;
    const apiClient = ns.apiClient;
    const errors = ns.errors;
    if (!domMount || !apiClient || !errors) {
        return;
    }

    const root = domMount.mount('#gameserver-reinstall');
    if (!root) {
        return;
    }

    const required = domMount.requiredDataset(root, ['urlOptions', 'urlReinstall']);
    const inlineError = document.getElementById('gs-reinstall-error');
    const optionSelect = document.getElementById('gs-reinstall-option');
    const confirmInput = document.getElementById('gs-reinstall-confirm');
    const submitBtn = document.getElementById('gs-reinstall-submit');
    const jobLabel = document.getElementById('gs-reinstall-job');

    if (!required.ok) {
        errors.showAll(inlineError, {
            message: `Reinstall mount misconfigured: missing ${required.missing.join(', ')}`,
            error_code: 'MOUNT_CONFIG_MISSING',
            request_id: '',
        });
        return;
    }

    const setBusy = (busy) => {
        submitBtn.disabled = busy;
        submitBtn.textContent = busy ? 'Starte…' : 'Reinstall starten';
    };

    const loadOptions = async () => {
        try {
            const payload = await apiClient.request(root.dataset.urlOptions);
            const options = payload.data?.options || [];
            if (!Array.isArray(options) || options.length === 0) {
                optionSelect.innerHTML = '<option value="">Keine Optionen verfügbar</option>';
                return;
            }

            optionSelect.innerHTML = options.map((entry) => `<option value="${entry.id}">${entry.label || entry.id}</option>`).join('');
            errors.clearInline(inlineError);
        } catch (error) {
            errors.showAll(inlineError, error);
            optionSelect.innerHTML = '<option value="">Laden fehlgeschlagen</option>';
        }
    };

    const submit = async () => {
        if (!confirmInput.checked) {
            errors.showAll(inlineError, {
                message: 'Bitte bestätige die Neuinstallation.',
                error_code: 'INVALID_INPUT',
                request_id: '',
            });
            return;
        }

        setBusy(true);
        try {
            const payload = await apiClient.request(root.dataset.urlReinstall, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    confirm: true,
                    version: optionSelect.value || null,
                }),
            });
            const jobId = payload.data?.job_id || '';
            jobLabel.textContent = jobId ? `Job #${jobId} queued` : 'Reinstall queued';
            errors.clearInline(inlineError);
            errors.showToast({
                message: 'Neuinstallation wurde eingeplant.',
                error_code: 'OK',
                request_id: payload.request_id || '',
            }, 2500);
        } catch (error) {
            errors.showAll(inlineError, error);
        } finally {
            setBusy(false);
        }
    };

    submitBtn?.addEventListener('click', submit);
    loadOptions();
})();
