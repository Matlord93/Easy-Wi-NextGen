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
    ]);

    const inlineErrorEl = document.getElementById('gameserver-files-inline-error');
    if (!required.ok) {
        errors.showAll(inlineErrorEl, {
            error_code: 'MOUNT_CONFIG_MISSING',
            message: `Missing mount data: ${required.missing.join(', ')}`,
        });
        return;
    }

    const state = { cwd: '' };
    const listEl = document.getElementById('gf-list');
    const cwdEl = document.getElementById('gf-cwd');
    const breadcrumbsEl = document.getElementById('gf-breadcrumbs');
    const uploadEl = document.getElementById('gf-upload');

    const normalizeListing = (payload) => {
        const body = payload?.data && typeof payload.data === 'object' ? payload.data : payload;
        return {
            files: body?.files || body?.entries || [],
            cwd: body?.cwd || body?.path || '',
        };
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
            return `<tr>
                <td>${entry.name}</td>
                <td>${type}</td>
                <td>${entry.size_human || '0 B'}</td>
                <td>${entry.modified_at || ''}</td>
                <td>
                    ${openButton}
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
