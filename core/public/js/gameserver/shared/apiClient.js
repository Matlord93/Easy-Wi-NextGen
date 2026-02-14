(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.EasyWiGameserver = root.EasyWiGameserver || {};
        root.EasyWiGameserver.apiClient = factory();
    }
})(typeof self !== 'undefined' ? self : this, function () {
    const buildRequestId = () => {
        if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
            return crypto.randomUUID();
        }
        return `${Date.now().toString(16)}-${Math.random().toString(16).slice(2, 10)}`;
    };

    const normalizeError = (payload, fallbackMessage, requestId) => ({
        ok: false,
        error_code: payload?.error_code || 'HTTP_ERROR',
        message: payload?.message || payload?.error || fallbackMessage,
        request_id: payload?.request_id || requestId || '',
        context: payload?.context || {},
    });

    const request = async (url, options = {}) => {
        const requestId = buildRequestId();
        const response = await fetch(url, {
            ...options,
            headers: {
                Accept: 'application/json',
                'X-Request-ID': requestId,
                ...(options.headers || {}),
            },
        });

        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw normalizeError(payload, `Request failed (${response.status})`, requestId);
        }

        if (payload && payload.ok === false) {
            throw normalizeError(payload, payload.message || 'Request failed.', requestId);
        }

        return payload;
    };

    return {
        request,
        normalizeError,
        buildRequestId,
    };
});
