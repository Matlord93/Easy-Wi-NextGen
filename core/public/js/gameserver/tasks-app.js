(function () {
    const ns = window.EasyWiGameserver || {};
    const domMount = ns.domMount;
    const apiClient = ns.apiClient;
    const errors = ns.errors;

    const mount = domMount?.mount?.('#gameserver-tasks');
    if (!mount) {
        return;
    }

    const required = domMount.requiredDataset(mount, [
        'urlTasks',
        'urlCancelTemplate',
        'urlLogs',
        'urlHealth',
    ]);

    const errorPanel = document.getElementById('gs-tasks-error');
    if (!required.ok) {
        errors.showAll(errorPanel, {
            error_code: 'MOUNT_CONFIG_MISSING',
            message: `Missing mount data: ${required.missing.join(', ')}`,
        });
        return;
    }

    const defaultI18n = {
        noTasksFound: 'No tasks found.',
        logs: 'Logs',
        cancel: 'Cancel',
        loadingLogs: 'Loading logs for %id%…',
        cancelConfirm: 'Cancel task %id%?',
    };
    let i18n = defaultI18n;
    try {
        i18n = { ...defaultI18n, ...(mount.dataset.i18n ? JSON.parse(mount.dataset.i18n) : {}) };
    } catch (_) {
        i18n = defaultI18n;
    }
    const tr = (key, replacements = {}) => Object.entries(replacements).reduce(
        (msg, [k, v]) => msg.replaceAll(`%${k}%`, String(v)),
        i18n[key] || defaultI18n[key] || key,
    );

    const jobsBody = document.getElementById('gs-tasks-list');
    const logsBox = document.getElementById('gs-tasks-logs');
    let selectedTaskId = null;
    let cursor = '';
    let poll = null;

    const taskLabel = (task) => `${task.type || 'task'} #${task.id}`;
    const cancelUrl = (taskId) => mount.dataset.urlCancelTemplate.replace('__TASK_ID__', encodeURIComponent(taskId));

    const appendLogs = (items) => {
        if (!Array.isArray(items) || items.length === 0) {
            return;
        }
        if (logsBox.dataset.empty === '1') {
            logsBox.textContent = '';
            logsBox.dataset.empty = '0';
        }
        items.forEach((entry) => {
            const line = document.createElement('div');
            line.className = 'text-xs text-slate-300';
            line.textContent = `[${entry.created_at || '-'}] ${entry.message || ''}`;
            logsBox.appendChild(line);
        });
        logsBox.scrollTop = logsBox.scrollHeight;
    };

    const renderTasks = (tasks) => {
        if (!Array.isArray(tasks) || tasks.length === 0) {
            jobsBody.innerHTML = `<tr><td colspan="5" class="dashboard-table__empty">${tr('noTasksFound')}</td></tr>`;
            return;
        }

        jobsBody.innerHTML = '';
        tasks.forEach((task) => {
            const tr_el = document.createElement('tr');
            const idCell = document.createElement('td');
            const typeCell = document.createElement('td');
            const statusCell = document.createElement('td');
            const createdCell = document.createElement('td');
            const actionsCell = document.createElement('td');

            idCell.textContent = task.id;
            typeCell.textContent = task.type || '-';
            statusCell.textContent = task.status || '-';
            createdCell.textContent = task.created_at || '-';
            actionsCell.className = 'text-right';

            const logsBtn = document.createElement('button');
            logsBtn.type = 'button';
            logsBtn.className = 'ui-button ui-button--ghost';
            logsBtn.dataset.open = task.id;
            logsBtn.textContent = tr('logs');

            const cancelBtn = document.createElement('button');
            cancelBtn.type = 'button';
            cancelBtn.className = 'ui-button ui-button--danger';
            cancelBtn.dataset.cancel = task.id;
            cancelBtn.textContent = tr('cancel');

            actionsCell.appendChild(logsBtn);
            actionsCell.appendChild(cancelBtn);

            [idCell, typeCell, statusCell, createdCell, actionsCell].forEach((cell) => tr_el.appendChild(cell));
            jobsBody.appendChild(tr_el);
        });
    };

    const loadLogs = async () => {
        if (!selectedTaskId) {
            return;
        }
        try {
            const query = cursor
                ? `?task_id=${encodeURIComponent(selectedTaskId)}&cursor=${encodeURIComponent(cursor)}`
                : `?task_id=${encodeURIComponent(selectedTaskId)}`;
            const payload = await apiClient.request(`${mount.dataset.urlLogs}${query}`);
            errors.clearInline(errorPanel);
            const data = payload.data || {};
            appendLogs(data.logs || []);
            cursor = data.cursor || cursor;
        } catch (error) {
            errors.showAll(errorPanel, error);
        }
    };

    const loadTasks = async () => {
        try {
            const payload = await apiClient.request(mount.dataset.urlTasks);
            errors.clearInline(errorPanel);
            renderTasks(payload.data?.tasks || []);
        } catch (error) {
            errors.showAll(errorPanel, error);
        }
    };

    mount.addEventListener('click', async (event) => {
        const openBtn = event.target.closest('[data-open]');
        if (openBtn) {
            selectedTaskId = openBtn.dataset.open;
            cursor = '';
            logsBox.dataset.empty = '1';
            logsBox.textContent = tr('loadingLogs', { id: selectedTaskId });
            await loadLogs();
            if (poll) {
                window.clearInterval(poll);
            }
            poll = window.setInterval(loadLogs, 2000);
            return;
        }

        const cancelBtn = event.target.closest('[data-cancel]');
        if (!cancelBtn) {
            return;
        }
        if (!window.confirm(tr('cancelConfirm', { id: cancelBtn.dataset.cancel }))) {
            return;
        }

        cancelBtn.disabled = true;
        try {
            await apiClient.request(cancelUrl(cancelBtn.dataset.cancel), { method: 'POST' });
            errors.clearInline(errorPanel);
            await loadTasks();
        } catch (error) {
            errors.showAll(errorPanel, error);
        } finally {
            cancelBtn.disabled = false;
        }
    });

    (async () => {
        try {
            await apiClient.request(mount.dataset.urlHealth);
            errors.clearInline(errorPanel);
            await loadTasks();
        } catch (error) {
            errors.showAll(errorPanel, error);
        }
    })();
})();
