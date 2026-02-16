(function () {
    const ns = window.EasyWiGameserver || {};
    const domMount = ns.domMount;
    const apiClient = ns.apiClient;
    const errors = ns.errors;
    if (!domMount || !apiClient || !errors) {
        return;
    }

    const root = domMount.mount('#gameserver-backups');
    if (!root) {
        return;
    }

    const required = domMount.requiredDataset(root, [
        'urlList',
        'urlCreate',
        'urlRestoreTemplate',
        'urlDeleteTemplate',
        'urlDownloadTemplate',
        'urlMode',
        'urlHealth',
    ]);
    const inlineError = document.getElementById('gs-backups-error');
    const listEl = document.getElementById('gs-backups-list');
    const createButton = document.getElementById('gs-backup-create');
    const labelInput = document.getElementById('gs-backup-label');
    const modeSelect = document.getElementById('gs-backup-mode');

    if (!required.ok) {
        errors.showAll(inlineError, {
            message: `Backups mount misconfigured: missing ${required.missing.join(', ')}`,
            error_code: 'MOUNT_CONFIG_MISSING',
            request_id: '',
        });
        return;
    }

    const renderEmpty = (message) => {
        listEl.innerHTML = `<tr><td colspan="5" class="dashboard-table__empty">${message}</td></tr>`;
    };

    const formatSize = (sizeBytes) => {
        if (typeof sizeBytes !== 'number' || Number.isNaN(sizeBytes) || sizeBytes < 0) {
            return '—';
        }
        const gb = sizeBytes / (1024 * 1024 * 1024);
        if (gb >= 1) {
            return `${gb.toFixed(1)} GB`;
        }
        const mb = sizeBytes / (1024 * 1024);
        return `${mb.toFixed(1)} MB`;
    };

    const backupRow = (backup) => {
        const backupId = backup.id;
        const created = backup.created_at || '—';
        const size = formatSize(backup.size_bytes);

        return `<tr>
            <td>${backupId}</td>
            <td>${backup.status || 'unknown'}</td>
            <td>${created}</td>
            <td>${size}</td>
            <td>
                <div class="flex gap-2">
                    <button class="ui-button ui-button--ghost" data-action="restore" data-backup-id="${backupId}">Restore</button>
                    <button class="ui-button ui-button--ghost" data-action="download" data-backup-id="${backupId}">Download</button>
                    <button class="ui-button ui-button--danger" data-action="delete" data-backup-id="${backupId}">Delete</button>
                </div>
            </td>
        </tr>`;
    };

    const loadList = async () => {
        try {
            errors.clearInline(inlineError);
            const payload = await apiClient.request(root.dataset.urlList);
            const backups = payload.data?.backups || [];
            if (modeSelect && payload.data?.mode) {
                modeSelect.value = payload.data.mode;
            }
            if (!Array.isArray(backups) || backups.length === 0) {
                renderEmpty('No backups yet.');
                return;
            }
            listEl.innerHTML = backups.map(backupRow).join('');
        } catch (error) {
            renderEmpty('Failed to load backups.');
            errors.showAll(inlineError, error);
        }
    };

    const postCreate = async () => {
        createButton.disabled = true;
        createButton.dataset.original = createButton.textContent;
        createButton.textContent = 'Creating…';
        try {
            errors.clearInline(inlineError);
            const label = (labelInput.value || '').trim();
            const payload = await apiClient.request(root.dataset.urlCreate, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(label ? { label } : {}),
            });
            errors.showToast({
                message: payload.data?.message || 'Backup queued.',
                error_code: 'OK',
                request_id: payload.request_id || '',
            }, 2000);
            window.setTimeout(loadList, 1500);
        } catch (error) {
            errors.showAll(inlineError, error);
        } finally {
            createButton.disabled = false;
            createButton.textContent = createButton.dataset.original || 'Create backup';
        }
    };


    const updateMode = async () => {
        if (!modeSelect) {
            return;
        }
        try {
            const payload = await apiClient.request(root.dataset.urlMode, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ mode: modeSelect.value }),
            });
            errors.clearInline(inlineError);
            errors.showToast({
                message: `Backup mode set to ${payload.data?.mode || modeSelect.value}.`,
                error_code: 'OK',
                request_id: payload.request_id || '',
            }, 2000);
        } catch (error) {
            errors.showAll(inlineError, error);
        }
    };

    const handleAction = async (action, backupId) => {
        const id = String(backupId || '').trim();
        if (!id) {
            errors.showAll(inlineError, {
                message: 'Backup id is required.',
                error_code: 'INVALID_INPUT',
                request_id: '',
            });
            return;
        }

        try {
            errors.clearInline(inlineError);
            if (action === 'restore') {
                if (!window.confirm('Restore this backup?')) {
                    return;
                }
                const payload = await apiClient.request(
                    root.dataset.urlRestoreTemplate.replace('__BACKUP_ID__', encodeURIComponent(id)),
                    {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ confirm: true }),
                    },
                );
                errors.showToast({
                    message: payload.data?.message || 'Restore queued.',
                    error_code: 'OK',
                    request_id: payload.request_id || '',
                }, 2000);
                window.setTimeout(loadList, 1500);
                return;
            }

            if (action === 'download') {
                const url = root.dataset.urlDownloadTemplate.replace('__BACKUP_ID__', encodeURIComponent(id));
                window.location.assign(url);
                return;
            }

            if (action === 'delete') {
                if (!window.confirm('Delete this backup?')) {
                    return;
                }
                await apiClient.request(
                    root.dataset.urlDeleteTemplate.replace('__BACKUP_ID__', encodeURIComponent(id)),
                    { method: 'DELETE' },
                );
                window.setTimeout(loadList, 500);
            }
        } catch (error) {
            errors.showAll(inlineError, error);
        }
    };

    createButton.addEventListener('click', postCreate);
    modeSelect?.addEventListener('change', updateMode);
    listEl.addEventListener('click', (event) => {
        const button = event.target.closest('[data-action][data-backup-id]');
        if (!button) {
            return;
        }
        handleAction(button.dataset.action, button.dataset.backupId);
    });

    apiClient.request(root.dataset.urlHealth)
        .then((payload) => {
            if (!payload?.ok) {
                throw payload;
            }
            loadList();
        })
        .catch((error) => {
            errors.showAll(inlineError, error);
            renderEmpty('Backups unavailable.');
        });
})();
