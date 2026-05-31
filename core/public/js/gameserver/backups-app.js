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

    const defaultI18n = {
        noBackupsYet: 'No backups yet.',
        failedToLoad: 'Failed to load backups.',
        restore: 'Restore',
        download: 'Download',
        delete: 'Delete',
        creating: 'Creating…',
        backupQueued: 'Backup queued.',
        restoreQueued: 'Restore queued.',
        backupsUnavailable: 'Backups unavailable.',
        backupIdRequired: 'Backup ID is required.',
        modeUpdated: 'Backup mode set to %mode%.',
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

    const escHtml = (str) => String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

    const backupRow = (backup) => {
        const backupId = escHtml(String(backup.id));
        const created = escHtml(backup.created_at || '—');
        const size = escHtml(formatSize(backup.size_bytes));
        const status = escHtml(backup.status || 'unknown');
        const targetType = escHtml(backup.target_type || 'local');
        const targetPath = escHtml(backup.target_path || backup.archive_path || '—');
        const checksum = escHtml(backup.checksum_sha256 || '—');
        const error = escHtml(backup.error_message || backup.error_code || '');

        return `<tr>
            <td>${backupId}</td>
            <td><strong>${status}</strong><br><small>${targetType}: ${targetPath}${error ? `<br>${error}` : ''}</small></td>
            <td>${created}</td>
            <td>${size}<br><small>SHA-256: ${checksum}</small></td>
            <td>
                <div class="flex gap-2">
                    <button class="ui-button ui-button--ghost" data-action="restore" data-backup-id="${backupId}">${tr('restore')}</button>
                    <button class="ui-button ui-button--ghost" data-action="download" data-backup-id="${backupId}">${tr('download')}</button>
                    <button class="ui-button ui-button--danger" data-action="delete" data-backup-id="${backupId}">${tr('delete')}</button>
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
                renderEmpty(tr('noBackupsYet'));
                return;
            }
            listEl.innerHTML = backups.map(backupRow).join('');
        } catch (error) {
            renderEmpty(tr('failedToLoad'));
            errors.showAll(inlineError, error);
        }
    };

    const postCreate = async () => {
        createButton.disabled = true;
        createButton.dataset.original = createButton.textContent;
        createButton.textContent = tr('creating');
        try {
            errors.clearInline(inlineError);
            const label = (labelInput.value || '').trim();
            const payload = await apiClient.request(root.dataset.urlCreate, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(label ? { label } : {}),
            });
            errors.showToast({
                message: payload.data?.message || tr('backupQueued'),
                error_code: 'OK',
                request_id: payload.request_id || '',
            }, 2000);
            window.setTimeout(loadList, 1500);
        } catch (error) {
            errors.showAll(inlineError, error);
        } finally {
            createButton.disabled = false;
            createButton.textContent = createButton.dataset.original || tr('restore');
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
                message: tr('modeUpdated', { mode: payload.data?.mode || modeSelect.value }),
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
                message: tr('backupIdRequired'),
                error_code: 'INVALID_INPUT',
                request_id: '',
            });
            return;
        }

        try {
            errors.clearInline(inlineError);
            if (action === 'restore') {
                if (!window.confirm(root.dataset.confirmRestore || tr('restoreQueued'))) {
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
                    message: payload.data?.message || tr('restoreQueued'),
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
                if (!window.confirm(root.dataset.confirmDelete || tr('delete'))) {
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
            renderEmpty(tr('backupsUnavailable'));
        });
})();
