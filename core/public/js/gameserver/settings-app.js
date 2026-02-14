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
        'urlConfigShow',
        'urlConfigSave',
        'urlConfigApply',
        'urlConfigCreate',
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
    const configSearch = document.getElementById('gs-settings-config-search');
    const configScope = document.getElementById('gs-settings-config-scope');
    const createBtn = document.getElementById('gs-settings-create');
    const editor = document.getElementById('gs-settings-content');
    const selectedMeta = document.getElementById('gs-settings-selected');
    const saveBtn = document.getElementById('gs-settings-save');
    const applyBtn = document.getElementById('gs-settings-apply');
    const slotInput = document.getElementById('gs-settings-slots');
    const slotBtn = document.getElementById('gs-settings-slots-save');
    const meta = document.getElementById('gs-settings-meta');

    const state = {
        activeConfigId: '',
        configs: [],
        loadedConfig: null,
        loadingConfig: false,
    };

    const endpointFromTemplate = (templateKey, configId) => mount.dataset[templateKey].replace('__CONFIG_ID__', encodeURIComponent(configId));
    const configShowUrl = (id) => endpointFromTemplate('urlConfigShow', id);
    const configSaveUrl = (id) => endpointFromTemplate('urlConfigSave', id);
    const configApplyUrl = (id) => endpointFromTemplate('urlConfigApply', id);

    const renderConfigList = () => {
        const search = (configSearch?.value || '').trim().toLowerCase();
        const scope = (configScope?.value || '').trim().toLowerCase();
        const visible = state.configs.filter((cfg) => {
            const inScope = !scope || String(cfg.scope || '').toLowerCase() === scope;
            const haystack = `${cfg.name || ''} ${cfg.file_path || ''} ${cfg.config_key || ''}`.toLowerCase();
            const inSearch = search === '' || haystack.includes(search);
            return inScope && inSearch;
        });

        if (!visible.length) {
            configList.innerHTML = '<li class="text-sm text-slate-500">No matching configs.</li>';
            return;
        }

        configList.innerHTML = visible.map((cfg) => {
            const isActive = String(cfg.id) === String(state.activeConfigId);
            const sourceBadge = cfg.source === 'instance' ? 'Instance' : 'Template';
            const existsBadge = cfg.exists ? 'override' : 'derived';
            return `<li>
                <button type="button" class="w-full rounded-lg border px-3 py-2 text-left text-xs ${isActive ? 'border-indigo-500 bg-indigo-500/20 text-slate-100' : 'border-slate-700 bg-slate-900 text-slate-200 hover:border-slate-500'}" data-config-id="${cfg.id}">
                    <div class="font-semibold">${cfg.name || cfg.id}</div>
                    <div class="mt-1 text-[11px] text-slate-400">${cfg.file_path || ''}</div>
                    <div class="mt-1 flex gap-1 text-[10px] uppercase tracking-wide text-slate-400">
                        <span>${sourceBadge}</span><span>•</span><span>${existsBadge}</span>
                    </div>
                </button>
            </li>`;
        }).join('');
    };

    const setBusy = (button, busy, labelBusy, labelIdle) => {
        if (!button) {
            return;
        }
        button.disabled = busy;
        button.textContent = busy ? labelBusy : labelIdle;
    };

    const selectConfig = async (configId) => {
        state.activeConfigId = configId;
        state.loadingConfig = true;
        renderConfigList();

        try {
            const payload = await apiClient.request(configShowUrl(configId));
            const config = payload?.data || {};
            state.loadedConfig = config;
            errors.clearInline(errorPanel);
            editor.value = config.content || '';
            selectedMeta.textContent = `${config.name || config.id} · ${config.format || 'text'} · ${config.source || 'template'}`;
        } catch (error) {
            errors.showAll(errorPanel, error);
        } finally {
            state.loadingConfig = false;
        }
    };

    const loadConfigs = async (fromSettingsPayload) => {
        try {
            let configs = fromSettingsPayload?.configs;
            if (!Array.isArray(configs)) {
                const payload = await apiClient.request(mount.dataset.urlConfigs);
                configs = payload.data?.configs;
            }

            state.configs = Array.isArray(configs) ? configs : [];
            errors.clearInline(errorPanel);
            renderConfigList();

            if (state.configs.length && !state.activeConfigId) {
                await selectConfig(state.configs[0].id);
            }
        } catch (error) {
            errors.showAll(errorPanel, error);
        }
    };

    const createConfig = async () => {
        const name = window.prompt('Config name');
        if (!name) {
            return;
        }

        const format = window.prompt('Format (txt,cfg,ini,json,yaml,yml,xml,properties,conf,env,log)', 'txt') || 'txt';
        setBusy(createBtn, true, 'Creating…', 'Create');

        try {
            const payload = await apiClient.request(mount.dataset.urlConfigCreate, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name, format }),
            });

            const created = payload?.data?.created || null;
            errors.clearInline(errorPanel);
            errors.showToast({ message: 'Config created.', error_code: 'OK', request_id: payload.request_id || '' });
            state.activeConfigId = '';
            await loadConfigs();
            if (created?.id) {
                await selectConfig(created.id);
            }
        } catch (error) {
            errors.showAll(errorPanel, error);
        } finally {
            setBusy(createBtn, false, 'Creating…', 'Create');
        }
    };

    mount.addEventListener('click', async (event) => {
        const configBtn = event.target.closest('[data-config-id]');
        if (configBtn) {
            await selectConfig(configBtn.dataset.configId);
            return;
        }

        if (event.target.id === 'gs-settings-create') {
            await createConfig();
            return;
        }

        if (event.target.id === 'gs-settings-save') {
            if (!state.activeConfigId) {
                errors.showAll(errorPanel, { error_code: 'INVALID_INPUT', message: 'Select a config first.' });
                return;
            }

            setBusy(saveBtn, true, 'Saving…', 'Save');
            try {
                const payload = await apiClient.request(configSaveUrl(state.activeConfigId), {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ content: editor.value }),
                });
                errors.clearInline(errorPanel);
                errors.showToast({ message: 'Config saved.', error_code: 'OK', request_id: payload.request_id || '' });
                await loadConfigs();
            } catch (error) {
                errors.showAll(errorPanel, error);
            } finally {
                setBusy(saveBtn, false, 'Saving…', 'Save');
            }
            return;
        }

        if (event.target.id === 'gs-settings-apply') {
            if (!state.activeConfigId) {
                errors.showAll(errorPanel, { error_code: 'INVALID_INPUT', message: 'Select a config first.' });
                return;
            }

            setBusy(applyBtn, true, 'Applying…', 'Apply');
            try {
                const payload = await apiClient.request(configApplyUrl(state.activeConfigId), { method: 'POST' });
                errors.clearInline(errorPanel);
                errors.showToast({ message: 'Config apply queued.', error_code: 'OK', request_id: payload.request_id || '' });
            } catch (error) {
                errors.showAll(errorPanel, error);
            } finally {
                setBusy(applyBtn, false, 'Applying…', 'Apply');
            }
            return;
        }

        if (event.target.id === 'gs-settings-slots-save') {
            setBusy(slotBtn, true, 'Saving…', 'Update slots');
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
                setBusy(slotBtn, false, 'Saving…', 'Update slots');
            }
        }
    });

    configSearch?.addEventListener('input', renderConfigList);
    configScope?.addEventListener('change', renderConfigList);

    (async () => {
        try {
            const health = await apiClient.request(mount.dataset.urlHealth);
            const summary = await apiClient.request(mount.dataset.urlSettings);
            errors.clearInline(errorPanel);
            slotInput.value = summary.data?.slots?.current_slots ?? '';
            meta.textContent = `Settings healthy · request_id=${health.request_id || ''}`;
            await loadConfigs(summary.data || {});
        } catch (error) {
            errors.showAll(errorPanel, error);
        }
    })();
})();
