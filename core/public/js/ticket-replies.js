(() => {
    const replies = new WeakMap();

    function setError(form, message = '') {
        const alert = form.querySelector('[data-ticket-reply-error]');
        if (alert) {
            alert.textContent = message;
            alert.hidden = !message;
        }
    }

    document.addEventListener('htmx:beforeRequest', ({detail}) => {
        const form = detail.elt;
        if (form.matches('form[data-ticket-reply]')) {
            replies.set(detail.xhr, form);
            setError(form);
        }
    });

    document.addEventListener('htmx:beforeSwap', ({detail}) => {
        const {xhr} = detail;
        const form = replies.get(xhr);
        if (!form) return;

        if (xhr.status === 400 && xhr.getResponseHeader('X-EasyWi-Ticket-Validation') === '1') {
            setError(form, xhr.responseText);
            detail.shouldSwap = false;
            detail.isError = false;
        } else if (xhr.status === 413) {
            setError(form, form.dataset.uploadTooLarge);
            detail.shouldSwap = false;
            detail.isError = false;
        } else if ((xhr.status === 400 || xhr.status >= 500) && !xhr.getResponseHeader('X-EasyWi-Problem')) {
            setError(form, form.dataset.requestFailed);
        }
    });

    document.addEventListener('htmx:afterRequest', ({detail}) => {
        const form = replies.get(detail.xhr);
        if (form && detail.xhr.status === 200 && detail.successful) {
            form.reset();
        }
    });
})();
