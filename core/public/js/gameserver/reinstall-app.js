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
    const sharedStorageInput = document.getElementById('gs-reinstall-use-shared-storage');
    const sharedStorageHint = document.getElementById('gs-reinstall-shared-storage-hint');
    const sharedStorageStatus = document.getElementById('gs-reinstall-shared-storage-status');
    const label = (key, fallback) => root.dataset[key] || fallback;
    const escHtml = (str) => String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

    if (!required.ok) {
        errors.showAll(inlineError, {
            message: `Reinstall mount misconfigured: missing ${required.missing.join(', ')}`,
            error_code: 'MOUNT_CONFIG_MISSING',
            request_id: '',
        });
        return;
    }

    let submitting = false;
    let minecraftChannel = null;
    let optionsLoaded = false;
    const setBusy = (busy) => {
        submitting = busy;
        submitBtn.disabled = busy || !optionSelect.value;
        submitBtn.textContent = busy ? label('labelStarting', 'Starting…') : label('labelStart', 'Start reinstall');
    };

    const loadOptions = async () => {
        try {
            const payload = await apiClient.request(root.dataset.urlOptions);
            const previous = optionSelect.value;
            minecraftChannel = payload.data?.minecraft_channel || null;
            const options = payload.data?.options || [];
            const sharedStorage = payload.data?.shared_storage || {};
            if (!Array.isArray(options) || options.length === 0) {
                optionSelect.innerHTML = `<option value="">${label('labelNoOptions', 'No options available')}</option>`;
            } else {
                optionSelect.innerHTML = options.map((entry) => `<option value="${escHtml(entry.id)}">${escHtml(entry.label || entry.id)}</option>`).join('');
            }

            if (options.some((entry) => entry.id === previous)) optionSelect.value = previous;
            setBusy(submitting);
            const supported = !!sharedStorage.supported;
            const alreadyEnabled = !!sharedStorage.already_enabled;
            if (sharedStorageInput && !optionsLoaded) {
                sharedStorageInput.disabled = !supported;
                sharedStorageInput.checked = supported && alreadyEnabled;
            }
            if (sharedStorageHint) {
                if (!supported) {
                    sharedStorageHint.textContent = label('labelSharedStorageHintUnsupported', 'Shared storage is not supported for this template.');
                } else if (alreadyEnabled) {
                    sharedStorageHint.textContent = label('labelSharedStorageHintSupported', 'Large immutable game assets are shared; config, saves and logs remain per instance.');
                } else {
                    sharedStorageHint.textContent = label('labelSharedStorageHintMigrate', 'Reinstall will replace large asset folders with links to the shared directory and rename existing local folders as backup.');
                }
            }
            if (sharedStorageStatus) {
                sharedStorageStatus.classList.toggle('hidden', !alreadyEnabled);
            }
            optionsLoaded = true;
            errors.clearInline(inlineError);
        } catch (error) {
            errors.showAll(inlineError, error);
            if (!optionsLoaded) {
                optionSelect.innerHTML = `<option value="">${label('labelLoadingFailed', 'Loading failed')}</option>`;
                setBusy(submitting);
            }
        }
    };

    const submit = async () => {
        if (submitting || !optionSelect.value) return;
        if (!confirmInput.checked) {
            errors.showAll(inlineError, {
                message: label('labelConfirmRequired', 'Please confirm the reinstall.'),
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
                    use_shared_storage: !!(sharedStorageInput && sharedStorageInput.checked),
                }),
            });
            const jobId = payload.data?.job_id || '';
            jobLabel.textContent = jobId ? label('labelJobQueued', 'Job #%id% queued').replace('%id%', jobId) : label('labelReinstallQueued', 'Reinstall queued');
            errors.clearInline(inlineError);
            errors.showToast({
                message: label('labelScheduled', 'Reinstall has been scheduled.'),
                error_code: 'OK',
                request_id: payload.request_id || '',
            }, 2500);
        } catch (error) {
            errors.showAll(inlineError, error);
        } finally {
            setBusy(false);
        }
    };

    optionSelect?.addEventListener('change', () => setBusy(submitting));
    const receiveCatalog = (event) => {
        if (!root.isConnected) { document.removeEventListener('minecraft:catalog', receiveCatalog); return; }
        const catalog = minecraftChannel && event.detail?.[minecraftChannel];
        if (!catalog) return;
        const previous = optionSelect.value;
        const versions = (catalog.versions || []).filter((version) => (catalog.release_types?.[version] || 'release') === 'release');
        if (!versions.length) return;
        optionSelect.innerHTML = versions.map((version) => `<option value="${escHtml(version)}">${escHtml(version)}</option>`).join('');
        if (versions.includes(previous)) optionSelect.value = previous;
        setBusy(submitting);
    };
    document.addEventListener('minecraft:catalog', receiveCatalog);
    setBusy(false);
    submitBtn?.addEventListener('click', submit);
    loadOptions();
})();
