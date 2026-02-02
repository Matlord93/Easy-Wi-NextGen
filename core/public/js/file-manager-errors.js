(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.EasyWiFileManager = factory();
    }
})(typeof self !== 'undefined' ? self : this, function () {
    const safeString = (value) => (typeof value === 'string' ? value : '');

    const buildRequestId = () => {
        if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
            return crypto.randomUUID();
        }
        return `${Date.now().toString(16)}-${Math.random().toString(16).slice(2, 10)}`;
    };

    const normalizeErrorPayload = (payload, fallbackMessage, requestId) => {
        const message = safeString(payload && payload.message) || fallbackMessage;
        const errorCode = safeString(payload && payload.error_code) || 'unknown_error';
        const details = payload && typeof payload === 'object' ? (payload.details || {}) : {};
        const resolvedRequestId = safeString(payload && payload.request_id) || safeString(requestId);

        return {
            message,
            errorCode,
            details,
            requestId: resolvedRequestId,
        };
    };

    const formatErrorMessage = (error, suffixLabel) => {
        if (!error) {
            return '';
        }
        const base = error.message || '';
        if (!error.requestId) {
            return base;
        }
        const suffix = suffixLabel ? `${suffixLabel} ${error.requestId}` : `Request-ID: ${error.requestId}`;
        return base ? `${base} (${suffix})` : suffix;
    };

    return {
        buildRequestId,
        normalizeErrorPayload,
        formatErrorMessage,
    };
});
