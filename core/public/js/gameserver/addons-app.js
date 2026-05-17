(function () {
    const ns = window.EasyWiGameserver || {};
    const domMount = ns.domMount;
    const apiClient = ns.apiClient;
    const errors = ns.errors;
    if (!domMount || !apiClient || !errors) {
        return;
    }

    const root = domMount.mount('#gameserver-addons');
    if (!root) {
        return;
    }

    const required = domMount.requiredDataset(root, [
        'urlHealth',
        'urlList',
        'urlInstallTemplate',
        'urlUpdateTemplate',
        'urlRemoveTemplate',
    ]);

    const inlineError = document.getElementById('gs-addons-error');
    const meta = document.getElementById('gs-addons-meta');
    const listEl = document.getElementById('gs-addons-list');

    if (!required.ok) {
        errors.showAll(inlineError, {
            error_code: 'MOUNT_CONFIG_MISSING',
            message: `Missing mount data: ${required.missing.join(', ')}`,
            request_id: '',
        });
        return;
    }

    const defaultI18n = {
        incompatible: 'Incompatible',
        updateAvailable: 'Update available',
        installed: 'Installed',
        available: 'Available',
        noAddons: 'No addons available for this template.',
        notSupported: 'Addons are not supported for this instance.',
        installedVersion: 'Installed version: %version%',
        notInstalled: 'Not installed',
        confirmAction: 'Confirm %action% for addon #%id%?',
        actionQueued: 'Addon action queued.',
        install: 'Install',
        update: 'Update',
        remove: 'Remove',
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

    const escHtml = (str) => String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

    const endpoint = (templateKey, addonId) => root.dataset[templateKey].replace('__ADDON_ID__', encodeURIComponent(String(addonId)));

    const renderStatusBadge = (addon) => {
        if (addon.compatible !== true) {
            return `<span class="rounded-full border border-rose-300 bg-rose-100 px-2 py-0.5 text-[10px] font-semibold text-rose-700">${tr('incompatible')}</span>`;
        }
        if (addon.update_available) {
            return `<span class="rounded-full border border-amber-300 bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-700">${tr('updateAvailable')}</span>`;
        }
        if (addon.installed) {
            return `<span class="rounded-full border border-emerald-300 bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">${tr('installed')}</span>`;
        }
        return `<span class="rounded-full border border-slate-300 bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-700">${tr('available')}</span>`;
    };

    const renderList = (addons) => {
        if (!Array.isArray(addons) || addons.length === 0) {
            listEl.innerHTML = `<div class="rounded-lg border border-slate-700 bg-slate-900/40 p-4 text-sm text-slate-400 md:col-span-2">${tr('noAddons')}</div>`;
            return;
        }

        listEl.innerHTML = addons.map((addon) => {
            const addonId = escHtml(String(addon.id));
            const name = escHtml(addon.name || addon.key || addon.id);
            const version = escHtml(addon.version || 'n/a');
            const description = addon.description ? `<p class="mt-2 text-xs text-slate-300">${escHtml(addon.description)}</p>` : '';
            const incompatibleReason = addon.compatible
                ? ''
                : `<p class="mt-2 text-xs text-rose-300">${escHtml(addon.incompatible_reason || tr('incompatible'))}</p>`;
            const installedLine = addon.installed
                ? `<p class="mt-1 text-xs text-slate-400">${tr('installedVersion', { version: escHtml(addon.installed_version || 'unknown') })}</p>`
                : `<p class="mt-1 text-xs text-slate-500">${tr('notInstalled')}</p>`;

            const disableInstall = addon.compatible !== true || addon.installed;
            const disableUpdate = addon.compatible !== true || addon.update_available !== true;
            const disableRemove = addon.installed !== true;

            return `<article class="rounded-lg border border-slate-700 bg-slate-900/40 p-4">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <h4 class="text-sm font-semibold text-slate-100">${name}</h4>
                        <p class="text-xs text-slate-400">Version: ${version}</p>
                    </div>
                    ${renderStatusBadge(addon)}
                </div>
                ${description}
                ${installedLine}
                ${incompatibleReason}
                <div class="mt-3 flex flex-wrap gap-2">
                    <button class="ui-button ui-button--primary" data-action="install" data-addon-id="${addonId}" ${disableInstall ? 'disabled' : ''}>${tr('install')}</button>
                    <button class="ui-button ui-button--ghost" data-action="update" data-addon-id="${addonId}" ${disableUpdate ? 'disabled' : ''}>${tr('update')}</button>
                    <button class="ui-button ui-button--danger" data-action="remove" data-addon-id="${addonId}" ${disableRemove ? 'disabled' : ''}>${tr('remove')}</button>
                </div>
            </article>`;
        }).join('');
    };

    const load = async () => {
        try {
            const health = await apiClient.request(root.dataset.urlHealth);
            if (health.data?.supports_addons === false) {
                meta.textContent = tr('notSupported');
                renderList([]);
                return;
            }
            const payload = await apiClient.request(root.dataset.urlList);
            errors.clearInline(inlineError);
            meta.textContent = `Resolver: ${health.data?.resolver_source || 'template'} · request_id=${payload.request_id || ''}`;
            renderList(payload.data?.addons || []);
        } catch (error) {
            renderList([]);
            errors.showAll(inlineError, error);
        }
    };

    const runAction = async (action, addonId) => {
        const requiresConfirm = action === 'install' || action === 'remove';
        if (requiresConfirm && !window.confirm(tr('confirmAction', { action, id: addonId }))) {
            return;
        }

        const method = action === 'remove' ? 'DELETE' : 'POST';
        const templateKey = action === 'install' ? 'urlInstallTemplate' : (action === 'update' ? 'urlUpdateTemplate' : 'urlRemoveTemplate');

        try {
            const payload = await apiClient.request(endpoint(templateKey, addonId), {
                method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ confirm: true }),
            });
            errors.clearInline(inlineError);
            errors.showToast({
                message: tr('actionQueued'),
                error_code: 'OK',
                request_id: payload.request_id || '',
            }, 2200);
            await load();
        } catch (error) {
            errors.showAll(inlineError, error);
        }
    };

    listEl.addEventListener('click', (event) => {
        const button = event.target.closest('[data-action][data-addon-id]');
        if (!button) {
            return;
        }
        runAction(button.dataset.action, button.dataset.addonId);
    });

    load();
})();
