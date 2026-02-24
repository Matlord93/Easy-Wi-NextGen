(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.EasyWiGameserver = root.EasyWiGameserver || {};
        root.EasyWiGameserver.errors = factory();
    }
})(typeof self !== 'undefined' ? self : this, function () {
    const ensureToast = () => {
        let toast = document.getElementById('gs-global-toast');
        if (toast) {
            return toast;
        }
        toast = document.createElement('div');
        toast.id = 'gs-global-toast';
        toast.className = 'hidden fixed bottom-4 right-4 z-50 rounded-lg border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-800 shadow-xl';
        document.body.appendChild(toast);
        return toast;
    };

    const enhanceHint = (message, code) => {
        const normalizedCode = String(code || '').toUpperCase();
        const normalizedMessage = String(message || '').toLowerCase();

        if (normalizedCode === 'CONFIG_INVALID' && normalizedMessage.includes('managed config missing')) {
            return `${message} Hint: Add this config key/path in the Agent managed-config registry and then retry the action.`;
        }

        return message;
    };

    const formatError = (error) => {
        const message = enhanceHint(error?.message || 'Request failed.', error?.error_code || 'UNKNOWN_ERROR');
        const code = error?.error_code || 'UNKNOWN_ERROR';
        const requestId = error?.request_id || '';
        const requestSuffix = requestId ? ` · request_id=${requestId}` : '';
        return `${message} [${code}]${requestSuffix}`;
    };

    const showInline = (element, error) => {
        if (!element) {
            return;
        }
        element.classList.remove('hidden');
        element.textContent = formatError(error);
    };

    const clearInline = (element) => {
        if (!element) {
            return;
        }
        element.classList.add('hidden');
        element.textContent = '';
    };

    const showToast = (error, timeoutMs = 4500) => {
        const toast = ensureToast();
        toast.classList.remove('hidden');
        toast.textContent = formatError(error);
        window.setTimeout(() => {
            toast.classList.add('hidden');
        }, timeoutMs);
    };

    const showAll = (inlineElement, error) => {
        showInline(inlineElement, error);
        showToast(error);
    };

    return {
        showInline,
        clearInline,
        showToast,
        showAll,
        formatError,
    };
});
