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
        'urlHealth',
        'urlAutomation',
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
    const meta = document.getElementById('gs-settings-meta');
    const autoBackupEnabled = document.getElementById('gs-auto-backup-enabled');
    const autoBackupMode = document.getElementById('gs-auto-backup-mode');
    const autoBackupTime = document.getElementById('gs-auto-backup-time');
    const autoBackupTimeWrap = document.getElementById('gs-auto-backup-time-wrap');
    const autoRestartEnabled = document.getElementById('gs-auto-restart-enabled');
    const autoRestartTime = document.getElementById('gs-auto-restart-time');
    const autoRestartTimeWrap = document.getElementById('gs-auto-restart-time-wrap');
    const autoUpdateEnabled = document.getElementById('gs-auto-update-enabled');
    const autoUpdateTime = document.getElementById('gs-auto-update-time');
    const autoUpdateTimeWrap = document.getElementById('gs-auto-update-time-wrap');
    const versionLockEnabled = document.getElementById('gs-version-lock-enabled');
    const versionLockVersion = document.getElementById('gs-version-lock-version');
    const automationSaveBtn = document.getElementById('gs-automation-save');

    const state = {
        activeConfigId: '',
        configs: [],
        loadedConfig: null,
        loadingConfig: false,
    };


    const DEFAULT_BACKUP_TIME = '03:00';
    const DEFAULT_RESTART_TIME = '04:00';
    const DEFAULT_UPDATE_TIME = '05:00';

    const buildTimeOptions = () => {
        const options = [];
        for (let hour = 0; hour < 24; hour += 1) {
            for (let minute = 0; minute < 60; minute += 15) {
                const hh = String(hour).padStart(2, '0');
                const mm = String(minute).padStart(2, '0');
                options.push(`${hh}:${mm}`);
            }
        }
        return options;
    };

    const timeOptions = buildTimeOptions();

    const populateTimeSelect = (select, selected, fallback) => {
        if (!select) {
            return;
        }
        const requested = selected || fallback;
        const values = timeOptions.includes(requested) ? timeOptions : [...timeOptions, requested].sort();
        select.innerHTML = values.map((value) => `<option value="${value}">${value}</option>`).join('');
        select.value = requested;
    };

    const updateAutomationTimeVisibility = () => {
        if (autoBackupTimeWrap) {
            autoBackupTimeWrap.classList.toggle('hidden', !Boolean(autoBackupEnabled?.checked));
        }
        if (autoRestartTimeWrap) {
            autoRestartTimeWrap.classList.toggle('hidden', !Boolean(autoRestartEnabled?.checked));
        }
        if (autoUpdateTimeWrap) {
            autoUpdateTimeWrap.classList.toggle('hidden', !Boolean(autoUpdateEnabled?.checked));
        }
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


    const populateVersionOptions = (versions, selected) => {
        if (!versionLockVersion) {
            return;
        }
        const values = Array.isArray(versions) ? versions : [];
        if (values.length === 0) {
            versionLockVersion.innerHTML = '<option value="">default</option>';
        } else {
            versionLockVersion.innerHTML = values.map((version) => `<option value="${version}">${version}</option>`).join('');
        }
        if (selected) {
            versionLockVersion.value = selected;
        }
    };

    const applyAutomationUi = (automation) => {
        const data = automation || {};
        if (autoBackupEnabled) {
            autoBackupEnabled.checked = Boolean(data.auto_backup?.enabled);
        }
        if (autoBackupMode) {
            autoBackupMode.value = data.auto_backup?.mode || 'manual';
        }
        populateTimeSelect(autoBackupTime, data.auto_backup?.time || '', DEFAULT_BACKUP_TIME);
        if (autoRestartEnabled) {
            autoRestartEnabled.checked = Boolean(data.auto_restart?.enabled);
        }
        populateTimeSelect(autoRestartTime, data.auto_restart?.time || '', DEFAULT_RESTART_TIME);
        if (autoUpdateEnabled) {
            autoUpdateEnabled.checked = Boolean(data.auto_update?.enabled);
        }
        populateTimeSelect(autoUpdateTime, data.auto_update?.time || '', DEFAULT_UPDATE_TIME);
        if (versionLockEnabled) {
            versionLockEnabled.checked = Boolean(data.version_lock?.enabled);
        }
        populateVersionOptions(data.version_lock?.available_versions || [], data.version_lock?.version || '');
        updateAutomationTimeVisibility();
    };

    const saveAutomation = async () => {
        setBusy(automationSaveBtn, true, 'Saving…', 'Automation speichern');
        try {
            const payload = await apiClient.request(mount.dataset.urlAutomation, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    automation: {
                        auto_backup: {
                            enabled: Boolean(autoBackupEnabled?.checked),
                            mode: autoBackupMode?.value || 'manual',
                            time: autoBackupTime?.value || DEFAULT_BACKUP_TIME,
                        },
                        auto_restart: {
                            enabled: Boolean(autoRestartEnabled?.checked),
                            time: autoRestartTime?.value || DEFAULT_RESTART_TIME,
                        },
                        auto_update: {
                            enabled: Boolean(autoUpdateEnabled?.checked),
                            time: autoUpdateTime?.value || DEFAULT_UPDATE_TIME,
                        },
                        version_lock: {
                            enabled: Boolean(versionLockEnabled?.checked),
                            version: versionLockVersion?.value || null,
                        },
                    },
                }),
            });
            applyAutomationUi(payload.data?.automation || {});
            errors.clearInline(errorPanel);
            errors.showToast({
                message: 'Automation settings updated.',
                error_code: 'OK',
                request_id: payload.request_id || '',
            }, 2000);
        } catch (error) {
            errors.showAll(errorPanel, error);
        } finally {
            setBusy(automationSaveBtn, false, 'Saving…', 'Automation speichern');
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

        if (event.target.id === 'gs-automation-save') {
            saveAutomation();
            return;
        }
    });

    configSearch?.addEventListener('input', renderConfigList);
    configScope?.addEventListener('change', renderConfigList);
    autoBackupEnabled?.addEventListener('change', updateAutomationTimeVisibility);
    autoRestartEnabled?.addEventListener('change', updateAutomationTimeVisibility);
    autoUpdateEnabled?.addEventListener('change', updateAutomationTimeVisibility);

    (async () => {
        try {
            const health = await apiClient.request(mount.dataset.urlHealth);
            const summary = await apiClient.request(mount.dataset.urlSettings);
            errors.clearInline(errorPanel);
            applyAutomationUi(summary.data?.automation || {});
            meta.textContent = `Settings healthy · request_id=${health.request_id || ''}`;
            await loadConfigs(summary.data || {});
        } catch (error) {
            errors.showAll(errorPanel, error);
        }
    })();
})();
