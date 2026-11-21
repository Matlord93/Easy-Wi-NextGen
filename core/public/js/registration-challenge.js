(async () => {
    const form = document.querySelector('form[data-registration-pow]');
    if (!form) return;

    const status = form.querySelector('[data-registration-status]');
    const output = form.elements.namedItem('pow_solution');
    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    if (!globalThis.crypto?.subtle) {
        status.textContent = status.dataset.unavailable;
        return;
    }
    try {
        const encoder = new TextEncoder();
        const target = '0'.repeat(Number(form.dataset.registrationPow));
        for (let i = 0; i < 8 * 16 ** Number(form.dataset.registrationPow); i++) {
            const hash = await crypto.subtle.digest('SHA-256', encoder.encode(form.dataset.registrationNonce + i));
            const hex = Array.from(new Uint8Array(hash), byte => byte.toString(16).padStart(2, '0')).join('');
            if (hex.startsWith(target)) {
                output.value = String(i);
                status.hidden = true;
                button.disabled = false;
                return;
            }
        }
    } catch (_) {
        // A browser without Web Crypto cannot complete this verification.
    }
    status.textContent = status.dataset.failure;
})();
