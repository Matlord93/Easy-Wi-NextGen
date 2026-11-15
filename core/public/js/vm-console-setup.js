for (const root of document.querySelectorAll('[data-console-setup]')) {
    const quote = value => "'" + value.replaceAll("'", "'\\''") + "'";
    const command = root.querySelector('[data-console-setup-command]');
    if (command) command.textContent = root.dataset.commandPrefix + quote(location.origin);
    const status = root.querySelector('[data-console-origin-status]');
    if (status) status.textContent = root.dataset.gatewayOrigin === location.origin ? root.dataset.originMatch : root.dataset.originMismatch;
}
