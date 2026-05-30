(function () {
    const ns = window.EasyWiGameserver || {};
    const domMount = ns.domMount;
    const apiClient = ns.apiClient;
    const errors = ns.errors;

    const app = domMount?.mount?.('#gameserver-files-app');
    if (!app) {
        return;
    }

    const filesDataset = domMount.requiredDataset(app, [
        'filesHealthUrl',
        'filesUploadUrl',
        'filesDownloadUrl',
        'filesMkdirUrl',
        'filesRenameUrl',
        'filesDeleteUrl',
        'filesContentUrl',
    ]);
    const listUrl = app.dataset.filesListUrl || app.dataset.urlSlots;
    const missing = filesDataset.missing.slice();
    if (!listUrl) {
        missing.push('filesListUrl|urlSlots');
    }

    const hasAccessEndpoints = Boolean(app.dataset.urlAccessHealth && app.dataset.urlAccessReveal && app.dataset.urlAccessReset);

    const defaultI18n = {
        accessUnavailable: 'Access credentials are unavailable for this instance.',
        missingMountData: 'Missing mount data: %fields%',
        saving: 'Saving…',
        save: 'Save',
        editTitle: 'Edit %path%',
        fileSaved: 'File saved successfully.',
        root: 'root',
        noFiles: 'No files found.',
        directory: 'Directory',
        file: 'File',
        open: 'Open',
        download: 'Download',
        edit: 'Edit',
        rename: 'Rename',
        delete: 'Delete',
        passwordLabel: 'Password',
        host: 'Host',
        port: 'Port',
        backend: 'Backend',
        user: 'User',
        rootPath: 'Root',
        status: 'Status',
        statusNeedsAttention: 'Needs attention',
        statusReady: 'Ready',
        provisioning: 'Provisioning in progress…',
        accessLoadFailed: 'Access data could not be loaded.',
        cwd: 'cwd',
        folderName: 'Folder name',
        newName: 'New name',
        deleteConfirm: 'Delete %name%?',
        revealing: 'Revealing…',
        revealPassword: 'Reveal password',
        resetting: 'Resetting…',
        resetPassword: 'Reset password',
        passwordCopied: 'Password copied to clipboard.',
        passwordCopyFailed: 'Password could not be copied automatically. Please copy it manually.',
    };
    let i18n = defaultI18n;
    try {
        i18n = { ...defaultI18n, ...(app.dataset.i18n ? JSON.parse(app.dataset.i18n) : {}) };
    } catch (error) {
        i18n = defaultI18n;
    }
    const tr = (key, replacements = {}) => Object.entries(replacements).reduce(
        (message, [name, value]) => message.replaceAll(`%${name}%`, String(value)),
        i18n[key] || defaultI18n[key] || key,
    );

    const inlineErrorEl = document.getElementById('gameserver-files-inline-error');
    if (missing.length) {
        errors.showAll(inlineErrorEl, {
            error_code: 'MOUNT_CONFIG_MISSING',
            message: tr('missingMountData', { fields: missing.join(', ') }),
        });
        return;
    }

    const EDITABLE_EXTENSIONS = new Set(['cfg', 'ini', 'json', 'yaml', 'yml', 'txt', 'log', 'properties', 'conf', 'env', 'xml']);

    const state = {
        cwd: '',
        editor: {
            path: '',
            etag: '',
            isOpen: false,
            isSaving: false,
        },
    };

    const listEl = document.getElementById('gf-list');
    const cwdEl = document.getElementById('gf-cwd');
    const breadcrumbsEl = document.getElementById('gf-breadcrumbs');
    const uploadEl = document.getElementById('gf-upload');
    const editorModalEl = document.getElementById('gf-editor-modal');
    const editorTitleEl = document.getElementById('gf-editor-title');
    const editorErrorEl = document.getElementById('gf-editor-error');
    const editorContentEl = document.getElementById('gf-editor-content');
    const editorSaveEl = document.getElementById('gf-editor-save');
    const accessMetaEl = document.getElementById('gf-access-meta');
    const accessRevealEl = document.getElementById('gf-access-reveal');
    const accessResetEl = document.getElementById('gf-access-reset');
    const passwordModalEl = document.getElementById('gf-password-modal');
    const passwordModalErrorEl = document.getElementById('gf-password-error');
    const passwordModalHostEl = document.getElementById('gf-password-host');
    const passwordModalPortEl = document.getElementById('gf-password-port');
    const passwordModalUserEl = document.getElementById('gf-password-user');
    const passwordModalValueEl = document.getElementById('gf-password-value');
    const passwordModalCopyEl = document.getElementById('gf-password-copy');

    const setAccessUnavailable = () => {
        if (accessMetaEl) {
            accessMetaEl.innerHTML = `<div class="text-slate-400">${tr('accessUnavailable')}</div>`;
        }
        if (accessRevealEl) {
            accessRevealEl.disabled = true;
        }
        if (accessResetEl) {
            accessResetEl.disabled = true;
        }
    };

    const normalizeListing = (payload) => {
        const body = payload?.data && typeof payload.data === 'object' ? payload.data : payload;
        return {
            files: body?.files || body?.entries || [],
            cwd: body?.cwd || body?.path || '',
        };
    };

    const isEditableFile = (entry) => {
        if (!entry || entry.is_dir) {
            return false;
        }

        const parts = String(entry.name || '').toLowerCase().split('.');
        const extension = parts.length > 1 ? parts.pop() : '';
        return EDITABLE_EXTENSIONS.has(extension);
    };

    const joinPath = (base, name) => {
        if (!base) {
            return name;
        }
        return `${base}/${name}`;
    };

    const setEditorSaving = (saving) => {
        state.editor.isSaving = saving;
        if (editorSaveEl) {
            editorSaveEl.disabled = saving;
            editorSaveEl.textContent = saving ? tr('saving') : tr('save');
        }
    };

    const closeEditor = () => {
        state.editor = { path: '', etag: '', isOpen: false, isSaving: false };
        errors.clearInline(editorErrorEl);
        setEditorSaving(false);
        if (editorContentEl) {
            editorContentEl.value = '';
        }
        editorModalEl?.classList.add('hidden');
        editorModalEl?.classList.remove('flex');
    };

    const openEditor = async (entryName) => {
        const fullPath = joinPath(state.cwd, entryName);
        try {
            const payload = await apiClient.request(`${app.dataset.filesContentUrl}?path=${encodeURIComponent(fullPath)}`);
            state.editor.path = payload.path || fullPath;
            state.editor.etag = payload.etag || '';
            state.editor.isOpen = true;
            errors.clearInline(editorErrorEl);
            editorTitleEl.textContent = tr('editTitle', { path: state.editor.path });
            editorContentEl.value = payload.content || '';
            editorModalEl.classList.remove('hidden');
            editorModalEl.classList.add('flex');
            editorContentEl.focus();
        } catch (error) {
            errors.showAll(inlineErrorEl, error);
        }
    };

    const saveEditor = async () => {
        if (!state.editor.isOpen || state.editor.isSaving) {
            return;
        }

        setEditorSaving(true);
        try {
            const payload = await apiClient.request(app.dataset.filesContentUrl, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    path: state.editor.path,
                    content: editorContentEl.value,
                    etag: state.editor.etag || undefined,
                }),
            });

            state.editor.etag = payload.new_etag || state.editor.etag;
            errors.clearInline(editorErrorEl);
            errors.showToast({
                message: tr('fileSaved'),
                error_code: 'OK',
                request_id: payload.request_id || '',
            });
            closeEditor();
            await loadList(state.cwd);
        } catch (error) {
            errors.showAll(editorErrorEl, error);
        } finally {
            setEditorSaving(false);
        }
    };

    const escHtml = (str) => String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

    const renderBreadcrumbs = (cwd) => {
        const parts = cwd.split('/').filter(Boolean);
        let path = '';
        const links = [`<button data-path="">${tr('root')}</button>`];
        parts.forEach((part) => {
            path = path ? `${path}/${part}` : part;
            links.push(`<span>/</span><button data-path="${escHtml(path)}">${escHtml(part)}</button>`);
        });
        breadcrumbsEl.innerHTML = links.join(' ');
    };

    const renderList = (files) => {
        if (!Array.isArray(files) || files.length === 0) {
            listEl.innerHTML = `<tr><td colspan="5" class="dashboard-table__empty">${tr('noFiles')}</td></tr>`;
            return;
        }

        listEl.innerHTML = files.map((entry) => {
            const safeName = escHtml(entry.name);
            const type = entry.is_dir ? tr('directory') : tr('file');
            const openButton = entry.is_dir
                ? `<button class="ui-button ui-button--ghost" data-action="open" data-name="${safeName}">${tr('open')}</button>`
                : `<button class="ui-button ui-button--ghost" data-action="download" data-name="${safeName}">${tr('download')}</button>`;
            const editButton = isEditableFile(entry)
                ? `<button class="ui-button ui-button--ghost" data-action="edit" data-name="${safeName}">${tr('edit')}</button>`
                : '';
            return `<tr>
                <td>${safeName}</td>
                <td>${type}</td>
                <td>${escHtml(entry.size_human || '0 B')}</td>
                <td>${escHtml(entry.modified_at || '')}</td>
                <td>
                    ${openButton}
                    ${editButton}
                    <button class="ui-button ui-button--ghost" data-action="rename" data-name="${safeName}">${tr('rename')}</button>
                    <button class="ui-button ui-button--danger" data-action="delete" data-name="${safeName}">${tr('delete')}</button>
                </td>
            </tr>`;
        }).join('');
    };


    const setButtonBusy = (button, busy, busyLabel, idleLabel) => {
        if (!button) {
            return;
        }
        button.disabled = busy;
        button.textContent = busy ? busyLabel : idleLabel;
    };

    const closePasswordModal = () => {
        if (!passwordModalEl) {
            return;
        }
        passwordModalEl.classList.add('hidden');
        passwordModalEl.classList.remove('flex');
        if (passwordModalValueEl) {
            passwordModalValueEl.value = '';
        }
        errors.clearInline(passwordModalErrorEl);
    };

    const showPasswordModal = (credential = {}) => {
        const password = credential.password || '';
        if (!passwordModalEl || !passwordModalValueEl) {
            window.alert(`${tr('passwordLabel')}: ${password}`);
            return;
        }

        if (passwordModalHostEl) {
            passwordModalHostEl.textContent = credential.host || '—';
        }
        if (passwordModalPortEl) {
            passwordModalPortEl.textContent = credential.port || '—';
        }
        if (passwordModalUserEl) {
            passwordModalUserEl.textContent = credential.username || '—';
        }
        passwordModalValueEl.value = password;
        errors.clearInline(passwordModalErrorEl);
        passwordModalEl.classList.remove('hidden');
        passwordModalEl.classList.add('flex');
        passwordModalValueEl.focus();
        passwordModalValueEl.select();
    };

    const renderAccess = (credential = {}) => {
        if (!accessMetaEl) {
            return;
        }
        accessMetaEl.innerHTML = `
            <div>${tr('host')}: <span class="font-semibold">${escHtml(credential.host || '—')}</span></div>
            <div>${tr('port')}: <span class="font-semibold">${escHtml(String(credential.port || '—'))}</span></div>
            <div>${tr('backend')}: <span class="font-semibold">${escHtml(credential.backend || '—')}</span></div>
            <div>${tr('user')}: <span class="font-semibold">${escHtml(credential.username || '—')}</span></div>
            <div>${tr('rootPath')}: <span class="font-semibold">${escHtml(credential.root_path || '—')}</span></div>
            <div>${tr('status')}: <span class="font-semibold">${credential.last_error_code ? tr('statusNeedsAttention') : tr('statusReady')}</span></div>
            ${credential.last_error_code ? `<div class="text-rose-300">${escHtml(String(credential.last_error_code))}: ${escHtml(credential.last_error_message || '')}</div>` : ''}
        `;

        if (accessRevealEl) {
            accessRevealEl.disabled = Boolean(credential.password_revealed);
        }
    };

    const loadAccess = async () => {
        if (!hasAccessEndpoints) {
            setAccessUnavailable();
            return;
        }

        try {
            const payload = await apiClient.request(app.dataset.urlAccessHealth);
            const credential = payload?.data?.credential || payload?.credential || {};
            renderAccess(credential);

            if (payload?.error_code === 'sftp_provisioning_pending' && accessMetaEl) {
                accessMetaEl.insertAdjacentHTML('beforeend', `<div class="text-amber-300">${escHtml(tr('provisioning'))}</div>`);
            }
        } catch (error) {
            if (accessMetaEl) {
                accessMetaEl.innerHTML = `<div class="text-rose-300">${tr('accessLoadFailed')}</div>`;
            }
            errors.showAll(inlineErrorEl, error);
        }
    };

    const loadList = async (path) => {
        try {
            const payload = await apiClient.request(`${listUrl}?path=${encodeURIComponent(path || '')}`);
            errors.clearInline(inlineErrorEl);
            const normalized = normalizeListing(payload);
            state.cwd = normalized.cwd;
            cwdEl.textContent = `${tr('cwd')}: ${state.cwd || '/'}`;
            renderBreadcrumbs(state.cwd);
            renderList(normalized.files);
        } catch (error) {
            errors.showAll(inlineErrorEl, error);
        }
    };

    document.getElementById('gf-refresh')?.addEventListener('click', () => loadList(state.cwd));
    document.getElementById('gf-up')?.addEventListener('click', () => {
        const parts = (state.cwd || '').split('/').filter(Boolean);
        parts.pop();
        loadList(parts.join('/'));
    });
    document.getElementById('gf-mkdir')?.addEventListener('click', async () => {
        const name = window.prompt(tr('folderName'));
        if (!name) {
            return;
        }
        try {
            await apiClient.request(app.dataset.filesMkdirUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ path: state.cwd, name }),
            });
            errors.clearInline(inlineErrorEl);
            await loadList(state.cwd);
        } catch (error) {
            errors.showAll(inlineErrorEl, error);
        }
    });

    listEl?.addEventListener('click', async (event) => {
        const button = event.target.closest('button[data-action]');
        if (!button) {
            return;
        }

        const name = button.dataset.name;
        const action = button.dataset.action;

        if (action === 'open') {
            const next = state.cwd ? `${state.cwd}/${name}` : name;
            await loadList(next);
            return;
        }

        if (action === 'download') {
            window.location.assign(`${app.dataset.filesDownloadUrl}?path=${encodeURIComponent(state.cwd)}&name=${encodeURIComponent(name)}`);
            return;
        }

        if (action === 'edit') {
            await openEditor(name);
            return;
        }

        try {
            if (action === 'rename') {
                const to = window.prompt(tr('newName'), name);
                if (!to || to === name) {
                    return;
                }
                await apiClient.request(app.dataset.filesRenameUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ path: state.cwd, from: name, to }),
                });
            }

            if (action === 'delete') {
                if (!window.confirm(tr('deleteConfirm', { name }))) {
                    return;
                }
                await apiClient.request(app.dataset.filesDeleteUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ path: state.cwd, name }),
                });
            }

            errors.clearInline(inlineErrorEl);
            await loadList(state.cwd);
        } catch (error) {
            errors.showAll(inlineErrorEl, error);
        }
    });

    breadcrumbsEl?.addEventListener('click', async (event) => {
        const button = event.target.closest('button[data-path]');
        if (!button) {
            return;
        }
        await loadList(button.dataset.path || '');
    });

    uploadEl?.addEventListener('change', async () => {
        if (!uploadEl.files || uploadEl.files.length === 0) {
            return;
        }

        const form = new FormData();
        form.set('path', state.cwd);
        form.set('upload', uploadEl.files[0]);

        try {
            await apiClient.request(app.dataset.filesUploadUrl, { method: 'POST', body: form });
            errors.clearInline(inlineErrorEl);
            uploadEl.value = '';
            await loadList(state.cwd);
        } catch (error) {
            errors.showAll(inlineErrorEl, error);
        }
    });


    accessRevealEl?.addEventListener('click', async () => {
        if (!hasAccessEndpoints) {
            return;
        }
        setButtonBusy(accessRevealEl, true, tr('revealing'), tr('revealPassword'));
        try {
            const payload = await apiClient.request(app.dataset.urlAccessReveal, { method: 'POST' });
            showPasswordModal({
                username: payload.data?.username,
                password: payload.data?.password || '',
                host: payload.data?.host,
                port: payload.data?.port,
            });
            await loadAccess();
        } catch (error) {
            errors.showAll(inlineErrorEl, error);
        } finally {
            setButtonBusy(accessRevealEl, false, tr('revealing'), tr('revealPassword'));
        }
    });

    accessResetEl?.addEventListener('click', async () => {
        if (!hasAccessEndpoints) {
            return;
        }
        setButtonBusy(accessResetEl, true, tr('resetting'), tr('resetPassword'));
        try {
            await apiClient.request(app.dataset.urlAccessReset, { method: 'POST' });
            await loadAccess();
        } catch (error) {
            errors.showAll(inlineErrorEl, error);
        } finally {
            setButtonBusy(accessResetEl, false, tr('resetting'), tr('resetPassword'));
        }
    });

    document.getElementById('gf-editor-close')?.addEventListener('click', closeEditor);
    document.getElementById('gf-editor-cancel')?.addEventListener('click', closeEditor);
    editorSaveEl?.addEventListener('click', saveEditor);

    document.querySelectorAll('[data-gf-password-close]').forEach((button) => {
        button.addEventListener('click', closePasswordModal);
    });
    passwordModalEl?.addEventListener('click', (event) => {
        if (event.target === passwordModalEl) {
            closePasswordModal();
        }
    });
    passwordModalCopyEl?.addEventListener('click', async () => {
        const val = passwordModalValueEl?.value;
        if (!val) {
            return;
        }
        let copied = false;
        try {
            await navigator.clipboard.writeText(val);
            copied = true;
        } catch (_) {
            try {
                const ta = document.createElement('textarea');
                ta.value = val;
                ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0';
                document.body.appendChild(ta);
                ta.focus();
                ta.select();
                copied = document.execCommand('copy');
                document.body.removeChild(ta);
            } catch (_2) {
                copied = false;
            }
        }
        if (copied) {
            errors.showToast({ message: tr('passwordCopied'), error_code: 'OK' }, 3000);
        }
    });

    (async () => {
        try {
            const health = await apiClient.request(app.dataset.filesHealthUrl);
            const ok = health?.ok !== false;
            if (!ok) {
                throw health;
            }
            errors.clearInline(inlineErrorEl);
            await loadList('');
            await loadAccess();
        } catch (error) {
            errors.showAll(inlineErrorEl, error);
        }
    })();
})();
