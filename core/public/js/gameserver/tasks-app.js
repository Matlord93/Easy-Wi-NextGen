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
            jobsBody.innerHTML = '<tr><td colspan="5" class="dashboard-table__empty">No tasks found.</td></tr>';
            return;
        }

        jobsBody.innerHTML = '';
        tasks.forEach((task) => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${task.id}</td>
                <td>${task.type || '-'}</td>
                <td>${task.status || '-'}</td>
                <td>${task.created_at || '-'}</td>
                <td class="text-right">
                    <button type="button" class="ui-button ui-button--ghost" data-open="${task.id}">Logs</button>
                    <button type="button" class="ui-button ui-button--danger" data-cancel="${task.id}">Cancel</button>
                </td>
            `;
            jobsBody.appendChild(tr);
        });
    };

    const loadLogs = async () => {
        if (!selectedTaskId) {
            return;
        }
        try {
            const query = cursor ? `?task_id=${encodeURIComponent(selectedTaskId)}&cursor=${encodeURIComponent(cursor)}` : `?task_id=${encodeURIComponent(selectedTaskId)}`;
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
            logsBox.textContent = `Loading logs for ${selectedTaskId}...`;
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
        if (!window.confirm(`Cancel ${taskLabel({ id: cancelBtn.dataset.cancel })}?`)) {
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
