(function () {
    'use strict';
    const script = document.currentScript;
    if (window.EasyWiMinecraftCatalog) {
        window.EasyWiMinecraftCatalog.mount(script);
        return;
    }
    let state = { catalog: {}, sync: {} };
    let config = {};
    let timer = null;
    let busy = false;
    let stopped = false;

    function renderCards() {
        document.querySelectorAll('[data-minecraft-channel]').forEach((card) => {
            const channel = card.dataset.minecraftChannel;
            const data = state.catalog[channel];
            if (!data) return;
            const select = card.querySelector('[data-minecraft-version-select]');
            const previews = card.querySelector('[data-minecraft-previews]');
            if (!select || !previews) return;
            if (!previews.dataset.catalogBound) {
                previews.dataset.catalogBound = '1';
                previews.addEventListener('change', renderCards);
            }
            const previous = select.value;
            const versions = (data.versions || []).filter((version) => previews.checked || (data.release_types?.[version] || 'release') === 'release');
            select.replaceChildren();
            for (const version of versions) {
                const option = document.createElement('option');
                option.value = version;
                option.textContent = version;
                select.appendChild(option);
            }
            if (versions.includes(previous)) select.value = previous;
            if (!versions.length) {
                const option = document.createElement('option');
                option.value = '';
                option.textContent = config.loading || '';
                select.appendChild(option);
            }
            select.disabled = !versions.length;
            const button = card.querySelector('button');
            const template = card.querySelector('[name="template_id"]');
            if (button) button.disabled = !versions.length || !template?.value;
            const count = card.querySelector('[data-minecraft-version-count]');
            if (count) count.textContent = (config.available || '{count}').replace('{count}', String((data.versions || []).length));
        });
        document.querySelectorAll('[data-minecraft-sync-status]').forEach((label) => {
            label.textContent = state.sync.updating ? config.updating : config.automatic;
        });
    }

    function publish(data) {
        if (!data || typeof data.catalog !== 'object' || !data.catalog) return;
        state = { catalog: data.catalog, sync: data.sync || {} };
        renderCards();
        document.dispatchEvent(new CustomEvent('minecraft:catalog', { detail: state.catalog }));
    }

    async function refresh() {
        if (busy || stopped || !config.catalogUrl) return;
        busy = true;
        try {
            const response = await fetch(config.catalogUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (response.status === 401 || response.status === 403) { stopped = true; return; }
            if (!response.ok) throw new Error('Catalog temporarily unavailable');
            publish((await response.json()).data);
        } catch (_) {
            // Keep the previous selectable versions during transient failures.
        } finally {
            busy = false;
            if (!stopped) {
                const missing = Object.values(state.catalog).some((channel) => !(channel.versions || []).length);
                timer = window.setTimeout(refresh, state.sync.updating ? 5000 : missing ? 30000 : 60000);
            }
        }
    }

    function mount(element) {
        config = { ...config, ...(element?.dataset || {}) };
        document.querySelectorAll('[data-minecraft-catalog-initial]:not([data-catalog-read])').forEach((initial) => {
            initial.dataset.catalogRead = '1';
            try { publish(JSON.parse(initial.textContent)); } catch (_) { /* Preserve existing state. */ }
        });
        renderCards();
        if (timer !== null) window.clearTimeout(timer);
        if (!busy && !stopped) timer = window.setTimeout(refresh, 1000);
    }

    window.EasyWiMinecraftCatalog = { mount, refresh };
    mount(script);
})();
