(() => {
    if (window.easywiUpdateActivationBound) return;
    window.easywiUpdateActivationBound = true;
    const memory = new Map();
    const read = (key) => {
        try { return window.sessionStorage.getItem(key) || memory.get(key); }
        catch { return memory.get(key); }
    };
    const write = (key, value) => {
        memory.set(key, value);
        try { window.sessionStorage.setItem(key, value); } catch { /* Private storage may be disabled. */ }
    };
    const check = () => {
        const card = document.getElementById('core-update-card');
        if (!card || !['update', 'both'].includes(card.dataset.updateKind)) return;
        const id = card.dataset.updateJob;
        if (!id) return;
        const key = `easywi-update:${id}`;
        if (['pending', 'running'].includes(card.dataset.updateStatus)) {
            write(key, 'running');
        } else if (card.dataset.updateStatus === 'success' && read(key) === 'running') {
            // Persist first: the reloaded document must not start a reload loop.
            write(key, 'reloaded');
            window.location.reload();
        }
    };
    document.addEventListener('htmx:afterSwap', check);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', check);
    else check();
})();
