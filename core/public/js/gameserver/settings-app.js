(function () {
    const ns = window.EasyWiGameserver || {};
    const domMount = ns.domMount;
    const apiClient = ns.apiClient;
    const errors = ns.errors;

    const mount = domMount?.mount?.('#gameserver-settings');
    if (!mount) {
        return;
    }

    const required = domMount.requiredDataset(mount, [
        'urlSettings',
        'urlConfigs',
        'urlConfigTemplate',
        'urlApplyTemplate',
        'urlSlots',
        'urlHealth',
    ]);

    const errorPanel = document.getElementById('gs-settings-error');
    if (!required.ok) {
        errors.showAll(errorPanel, {
            error_code: 'MOUNT_CONFIG_MISSING',
            message: `Missing mount data: ${required.missing.join(', ')}`,
        });
        return;
    }

    const configList = document.getElementById('gs-settings-config-list');
    const editor = document.getElementById('gs-settings-content');
    const saveBtn = document.getElementById('gs-settings-save');
    const applyBtn = document.getElementById('gs-settings-apply');
    const slotInput = document.getElementById('gs-settings-slots');
    const slotBtn = document.getElementById('gs-settings-slots-save');
    const meta = document.getElementById('gs-settings-meta');

    let activeConfigId = '';

    const configUrl = (id) => mount.dataset.urlConfigTemplate.replace('__CONFIG_ID__', encodeURIComponent(id));
    const applyUrl = (id) => mount.dataset.urlApplyTemplate.replace('__CONFIG_ID__', encodeURIComponent(id));

    const selectConfig = async (configId) => {
        activeConfigId = configId;
        try {
            const payload = await apiClient.request(configUrl(configId));
            errors.clearInline(errorPanel);
            editor.value = payload.data?.raw || '';
            meta.textContent = `Loaded config ${configId}`;
        } catch (error) {
            errors.showAll(errorPanel, error);
        }
    };

    const loadConfigs = async () => {
        try {
            const payload = await apiClient.request(mount.dataset.urlConfigs);
            const configs = payload.data?.configs || [];
            errors.clearInline(errorPanel);
            if (!configs.length) {
                configList.innerHTML = '<li class="text-sm text-slate-500">No editable configs available.</li>';
                return;
            }
            configList.innerHTML = '';
            configs.forEach((cfg) => {
                const li = document.createElement('li');
                li.innerHTML = `<button type="button" class="ui-button ui-button--ghost" data-config-id="${cfg.id}">${cfg.label || cfg.config_key || cfg.id}</button>`;
                configList.appendChild(li);
            });
            if (!activeConfigId) {
                await selectConfig(configs[0].id);
            }
        } catch (error) {
            errors.showAll(errorPanel, error);
        }
    };

    mount.addEventListener('click', async (event) => {
        const configBtn = event.target.closest('[data-config-id]');
        if (configBtn) {
            await selectConfig(configBtn.dataset.configId);
            return;
        }

        if (event.target.id === 'gs-settings-save') {
            if (!activeConfigId) {
                errors.showAll(errorPanel, { error_code: 'INVALID_INPUT', message: 'Select a config first.' });
                return;
            }
            saveBtn.disabled = true;
            try {
                await apiClient.request(configUrl(activeConfigId), {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ content: editor.value }),
                });
                errors.clearInline(errorPanel);
            } catch (error) {
                errors.showAll(errorPanel, error);
            } finally {
                saveBtn.disabled = false;
            }
            return;
        }

        if (event.target.id === 'gs-settings-apply') {
            if (!activeConfigId) {
                errors.showAll(errorPanel, { error_code: 'INVALID_INPUT', message: 'Select a config first.' });
                return;
            }
            applyBtn.disabled = true;
            try {
                await apiClient.request(applyUrl(activeConfigId), { method: 'POST' });
                errors.clearInline(errorPanel);
            } catch (error) {
                errors.showAll(errorPanel, error);
            } finally {
                applyBtn.disabled = false;
            }
            return;
        }

        if (event.target.id === 'gs-settings-slots-save') {
            slotBtn.disabled = true;
            try {
                await apiClient.request(mount.dataset.urlSlots, {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ slots: slotInput.value }),
                });
                errors.clearInline(errorPanel);
            } catch (error) {
                errors.showAll(errorPanel, error);
            } finally {
                slotBtn.disabled = false;
            }
        }
    });

    (async () => {
        try {
            const health = await apiClient.request(mount.dataset.urlHealth);
            const summary = await apiClient.request(mount.dataset.urlSettings);
            errors.clearInline(errorPanel);
            slotInput.value = summary.data?.slots?.current_slots ?? '';
            meta.textContent = `Settings healthy · request_id=${health.request_id || ''}`;
            await loadConfigs();
        } catch (error) {
            errors.showAll(errorPanel, error);
        }
    })();
})();
