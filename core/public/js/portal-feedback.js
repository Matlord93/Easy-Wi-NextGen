(() => {
    const {sessionExpired, sessionSignIn} = document.currentScript.dataset;
    const handled = new Set([
        'session_expired', 'login_required', 'forbidden', 'two_factor_required', 'form_expired',
        'not_found', 'module_disabled', 'method_not_allowed', 'rate_limited', 'unavailable', 'customer_required',
    ]);
    const show = (url, code, data) => {
        const message = typeof data?.error === 'string' ? data.error : data?.error?.message;
        if (!url || !handled.has(code) || new URL(url, location.href).origin !== location.origin || typeof message !== 'string') return;
        const render = () => {
            let notice = document.querySelector('[data-portal-feedback]');
            if (!notice) {
                notice = document.createElement('aside');
                notice.dataset.portalFeedback = '';
                notice.className = 'portal-feedback-card portal-feedback-notice';
                notice.setAttribute('role', 'alert');
                document.body.prepend(notice);
            }
            const expired = code === 'login_required' && sessionExpired;
            notice.replaceChildren(document.createTextNode(expired ? sessionExpired : message));
            if (typeof data.action_url === 'string' && typeof data.action === 'string') {
                const destination = new URL(data.action_url, location.href);
                if (destination.origin === location.origin && ['http:', 'https:'].includes(destination.protocol)) {
                    const link = document.createElement('a');
                    link.href = destination.href;
                    link.textContent = expired ? sessionSignIn : data.action;
                    notice.append(link);
                }
            }
        };
        if (document.body) render();
        else document.addEventListener('DOMContentLoaded', render, {once: true});
    };

    const fetchRequest = window.fetch;
    if (fetchRequest) {
        window.fetch = async (...args) => {
            const response = await fetchRequest.apply(window, args);
            const code = response.headers.get('X-EasyWi-Problem');
            if (handled.has(code)) {
                response.clone().json().then(data => show(response.url, code, data)).catch(() => {});
            }
            return response;
        };
    }
    const send = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.send = function (...args) {
        this.addEventListener('loadend', () => {
            const code = this.getResponseHeader('X-EasyWi-Problem');
            if (!handled.has(code)) return;
            try {
                const data = this.responseType === 'json' ? this.response : JSON.parse(this.responseText);
                show(this.responseURL, code, data);
            } catch (_) {
                // The original request handler still receives its response.
            }
        }, {once: true});
        return send.apply(this, args);
    };
})();
