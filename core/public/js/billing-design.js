(() => {
    'use strict';
    document.querySelectorAll('[data-invoice-editor]').forEach(root => {
        const form = root.querySelector('form'), status = root.querySelector('[data-preview-status]'), frame = root.querySelector('iframe');
        let timer, controller, generation = 0;
        function mode() {
            const visual = form.elements.namedItem('mode').value === 'visual';
            const fields = form.querySelector('[data-visual-fields]'); fields.hidden = !visual; fields.disabled = !visual;
            form.querySelector('[data-custom-fields]').hidden = visual;
        }
        async function preview() {
            clearTimeout(timer); controller?.abort(); controller = new AbortController();
            const current = ++generation, signal = controller.signal, timeout = setTimeout(() => controller.abort(), 10000);
            try {
                const response = await fetch(root.dataset.previewUrl, {method:'POST',body:new FormData(form),signal,credentials:'same-origin',cache:'no-store',redirect:'error',headers:{Accept:'application/json'}});
                const data = await response.json();
                if (current !== generation) return;
                if (!response.ok || typeof data.html !== 'string') throw new Error(data.error || root.dataset.previewError);
                frame.srcdoc = data.html; status.textContent = root.dataset.previewReady;
            } catch (error) {
                if (current === generation) status.textContent = error.name === 'AbortError' ? root.dataset.previewError : (error.message || root.dataset.previewError);
            } finally { clearTimeout(timeout); }
        }
        form.addEventListener('input', () => { mode(); generation++; controller?.abort(); clearTimeout(timer); timer = setTimeout(preview,450); });
        form.addEventListener('change', () => { mode(); generation++; controller?.abort(); clearTimeout(timer); timer = setTimeout(preview,100); });
        root.querySelector('[data-preview-refresh]').addEventListener('click',preview);
        form.addEventListener('submit', () => { clearTimeout(timer); generation++; controller?.abort(); });
        mode();
    });
})();
