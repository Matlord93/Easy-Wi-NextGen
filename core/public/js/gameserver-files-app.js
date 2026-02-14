(function () {
    const ns = window.EasyWiGameserver || {};
    const domMount = ns.domMount;
    const apiClient = ns.apiClient;
    const errors = ns.errors;

    const app = domMount?.mount?.('#gameserver-files-app');
    if (!app) {
        return;
    }

    const required = domMount.requiredDataset(app, [
        'filesListUrl',
        'filesHealthUrl',
        'filesUploadUrl',
        'filesDownloadUrl',
        'filesMkdirUrl',
        'filesRenameUrl',
        'filesDeleteUrl',
        'filesContentUrl',
    ]);

    const inlineErrorEl = document.getElementById('gameserver-files-inline-error');
    if (!required.ok) {
        errors.showAll(inlineErrorEl, {
            error_code: 'MOUNT_CONFIG_MISSING',
            message: `Missing mount data: ${required.missing.join(', ')}`,
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
            editorSaveEl.textContent = saving ? 'Saving…' : 'Save';
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
            editorTitleEl.textContent = `Edit ${state.editor.path}`;
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
                message: 'File saved successfully.',
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

    const renderBreadcrumbs = (cwd) => {
        const parts = cwd.split('/').filter(Boolean);
        let path = '';
        const links = ['<button data-path="">root</button>'];
        parts.forEach((part) => {
            path = path ? `${path}/${part}` : part;
            links.push(`<span>/</span><button data-path="${path}">${part}</button>`);
        });
        breadcrumbsEl.innerHTML = links.join(' ');
    };

    const renderList = (files) => {
        if (!Array.isArray(files) || files.length === 0) {
            listEl.innerHTML = '<tr><td colspan="5" class="dashboard-table__empty">No files found.</td></tr>';
            return;
        }

        listEl.innerHTML = files.map((entry) => {
            const type = entry.is_dir ? 'Directory' : 'File';
            const openButton = entry.is_dir
                ? `<button class="ui-button ui-button--ghost" data-action="open" data-name="${entry.name}">Open</button>`
                : `<button class="ui-button ui-button--ghost" data-action="download" data-name="${entry.name}">Download</button>`;
            const editButton = isEditableFile(entry)
                ? `<button class="ui-button ui-button--ghost" data-action="edit" data-name="${entry.name}">Edit</button>`
                : '';
            return `<tr>
                <td>${entry.name}</td>
                <td>${type}</td>
                <td>${entry.size_human || '0 B'}</td>
                <td>${entry.modified_at || ''}</td>
                <td>
                    ${openButton}
                    ${editButton}
                    <button class="ui-button ui-button--ghost" data-action="rename" data-name="${entry.name}">Rename</button>
                    <button class="ui-button ui-button--danger" data-action="delete" data-name="${entry.name}">Delete</button>
                </td>
            </tr>`;
        }).join('');
    };

    const loadList = async (path) => {
        try {
            const payload = await apiClient.request(`${app.dataset.filesListUrl}?path=${encodeURIComponent(path || '')}`);
            errors.clearInline(inlineErrorEl);
            const normalized = normalizeListing(payload);
            state.cwd = normalized.cwd;
            cwdEl.textContent = `cwd: ${state.cwd || '/'}`;
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
        const name = window.prompt('Folder name');
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
                const to = window.prompt('New name', name);
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
                if (!window.confirm(`Delete ${name}?`)) {
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

    document.getElementById('gf-editor-close')?.addEventListener('click', closeEditor);
    document.getElementById('gf-editor-cancel')?.addEventListener('click', closeEditor);
    editorSaveEl?.addEventListener('click', saveEditor);

    (async () => {
        try {
            const health = await apiClient.request(app.dataset.filesHealthUrl);
            const ok = health?.ok !== false;
            if (!ok) {
                throw health;
            }
            errors.clearInline(inlineErrorEl);
            await loadList('');
        } catch (error) {
            errors.showAll(inlineErrorEl, error);
        }
    })();
})();
