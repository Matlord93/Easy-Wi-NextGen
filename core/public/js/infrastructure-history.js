(function () {
    'use strict';
    const fields = {load: ['cpu_percent', 'memory_percent'], network: ['net_rx_bytes_per_second', 'net_tx_bytes_per_second'], disk: ['disk_read_bytes_per_second', 'disk_write_bytes_per_second']};
    const windowMs = 20 * 60 * 1000;
    function time(value, now) {
        if (typeof value !== 'string' || !/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?(?:Z|[+-]\d\d:\d\d)$/.test(value)) return null;
        const result = Date.parse(value);
        return Number.isFinite(result) && result <= now + 30000 && result >= now - 120000 ? result : null;
    }
    function number(value, percent = false) {
        return typeof value === 'number' && Number.isFinite(value) && value >= 0 && (!percent || value <= 100) ? value : null;
    }
    class History {
        constructor() { this.identity = null; this.load = []; this.io = []; this.loadGap = false; this.ioGap = false; this.interrupted = false; }
        interrupt() { this.loadGap = this.ioGap = true; this.interrupted = true; }
        append(lane, stamp, values, now, gap, metadata = {}) {
            const points = this[lane];
            if (points.length && stamp <= points[points.length - 1].time) return false;
            const last = points[points.length - 1];
            const broken = gap || !!last && (stamp - last.time > (lane === 'load' ? 90000 : 180000) || Object.values(last.values).every(value => value === null));
            points.push({time: stamp, values, gap: broken, source: metadata.source, interval: metadata.interval, memory_source: metadata.memory_source});
            this[lane] = points.filter(point => point.time >= now - windowMs).slice(-60);
            return true;
        }
        push(sample, now = Date.now()) {
            if (!sample || typeof sample !== 'object' || typeof sample.series_key !== 'string') { this.interrupt(); return; }
            if (sample.series_key !== this.identity) {
                this.identity = sample.series_key; this.load = []; this.io = []; this.loadGap = this.ioGap = false;
            }
            const stamp = time(sample.observed_at, now);
            if (stamp !== null && sample.stale === false) {
                const values = Object.fromEntries([...fields.load, 'disk_percent', 'memory_used_bytes', 'memory_total_bytes', 'disk_used_bytes', 'disk_total_bytes'].map(key => [key, number(sample[key], key.endsWith('_percent'))]));
                if (this.append('load', stamp, values, now, this.loadGap, {source:sample.load_source, memory_source:sample.memory_source})) this.loadGap = false;
                this.interrupted = false;
            } else { this.loadGap = true; this.interrupted = true; }
            const ioTime = time(sample.io_observed_at, now), interval = number(sample.io_interval_seconds);
            if (ioTime !== null && sample.io_stale === false && interval > 0 && interval <= 120 && ['counter_delta', 'proxmox_rrd'].includes(sample.io_source)) {
                const values = Object.fromEntries([...fields.network, ...fields.disk].map(key => [key, number(sample[key])]));
                if (this.append('io', ioTime, values, now, this.ioGap, {source:sample.io_source, interval:sample.io_interval_seconds})) this.ioGap = false;
            } else { this.ioGap = true; }
            this.load = this.load.filter(point => point.time >= now - windowMs);
            this.io = this.io.filter(point => point.time >= now - windowMs);
        }
    }
    function rate(value, locale) {
        if (number(value) === null) return '—';
        const units = ['B/s', 'KiB/s', 'MiB/s', 'GiB/s']; let index = 0;
        while (value >= 1024 && index < units.length - 1) { value /= 1024; index++; }
        return new Intl.NumberFormat(locale, {maximumFractionDigits: 2}).format(value) + ' ' + units[index];
    }
    function geometry(points, keys, now, percent = false) {
        const end = Math.max(now, points[points.length - 1]?.time || now), start = Math.max(end - windowMs, Math.min(points[0]?.time || end, end - 20000));
        const max = percent ? 100 : Math.max(1, ...points.flatMap(p => keys.map(k => number(p.values[k]) || 0)));
        return {max, start, end, paths: keys.map(key => {
            let path = '', last = null, lastDot = null;
            for (const point of points) {
                const value = number(point.values[key], percent);
                if (value === null || point.time < start) { last = null; continue; }
                const x = (point.time - start) / (end - start) * 300 + 10, y = 90 - value / max * 80;
                const gap = !last || point.gap || point.time - last.time > (percent ? 90000 : 180000);
                path += (gap ? 'M' : 'L') + x.toFixed(2) + ',' + y.toFixed(2);
                last = point; lastDot = {x, y};
            }
            return {path, dot: lastDot};
        })};
    }
    if (typeof module !== 'undefined' && module.exports) module.exports = {History, geometry, rate, time};
    if (typeof document === 'undefined' || window.EasyWiInfrastructureHistory) return;
    const stores = new WeakMap(), ns = 'http://www.w3.org/2000/svg';
    function exportRows(root, history, now = Date.now()) {
        const api = window.EasyWiInfrastructureExport;
        if (!api) return [];
        const panel = root.closest('[data-infra-usage-url]');
        const label = panel?.closest('.infra-page')?.querySelector('.infra-hero h1')?.textContent?.trim() || '';
        const sample = JSON.parse(root.querySelector('[data-history-sample]').textContent);
        return api.rows(history, sample, root.querySelector('[data-history-export-range]')?.value || '20', {role:root.dataset.seriesRole, label, path:window.location.pathname}, now);
    }
    function updateExport(root, history, now) {
        const button = root.querySelector('[data-history-export]');
        if (button) button.disabled = exportRows(root, history, now).length === 0;
    }
    function draw(root, history, now) {
        const labels = JSON.parse(root.querySelector('[data-history-labels]').textContent);
        updateExport(root, history, now);
        const validCount = history.load.filter(point => fields.load.some(key => point.values[key] !== null)).length;
        root.querySelector('[data-history-status]').textContent = history.interrupted ? labels.interrupted : validCount < 2 ? labels.waiting : validCount + ' ' + labels.points;
        for (const figure of root.querySelectorAll('[data-history-chart]')) {
            const kind = figure.dataset.historyChart, keys = fields[kind]; if (!keys) continue;
            const points = kind === 'load' ? history.load : history.io, data = geometry(points, keys, now, kind === 'load');
            const svg = document.createElementNS(ns, 'svg'); svg.setAttribute('viewBox', '0 0 320 100'); svg.setAttribute('role', 'img'); svg.setAttribute('aria-label', figure.querySelector('figcaption').textContent);
            for (const y of [10, 50, 90]) { const line = document.createElementNS(ns, 'path'); line.setAttribute('d', 'M10,' + y + 'L310,' + y); line.setAttribute('class', 'infra-history-grid-line'); svg.append(line); }
            data.paths.forEach((series, index) => {
                const path = document.createElementNS(ns, 'path'); path.setAttribute('d', series.path); path.setAttribute('class', 'infra-history-line infra-history-line-' + (index + 1)); svg.append(path);
                if (series.dot) { const dot = document.createElementNS(ns, 'circle'); dot.setAttribute('cx', series.dot.x); dot.setAttribute('cy', series.dot.y); dot.setAttribute('r', '2.5'); dot.setAttribute('class', 'infra-history-dot-' + (index + 1)); svg.append(dot); }
            });
            figure.querySelector('.infra-history-plot').replaceChildren(svg);
            const scale = kind === 'load' ? '0–100 %' : points.some(point => keys.some(key => point.values[key] !== null)) ? '0–' + rate(data.max, document.documentElement.lang) : '—';
            const clock = new Intl.DateTimeFormat(document.documentElement.lang, {timeStyle: 'medium', timeZone: 'Europe/Berlin'});
            figure.querySelector('[data-history-scale]').textContent = scale + ' · ' + clock.format(new Date(data.start)) + '–' + clock.format(new Date(data.end));
        }
    }
    function mount(panel) {
        const map = stores.get(panel) || new Map(), live = new Set(), now = Date.now();
        for (const root of panel.querySelectorAll('[data-infra-series]')) {
            try {
                const role = root.dataset.seriesRole; if (!['node', 'server'].includes(role)) continue;
                const sample = JSON.parse(root.querySelector('[data-history-sample]').textContent), history = map.get(role) || new History();
                history.push(sample, now); map.set(role, history); live.add(role); draw(root, history, now);
                root.querySelectorAll('[data-usage-rate]').forEach(element => { element.textContent = rate(sample[element.dataset.usageRate + '_bytes_per_second'], document.documentElement.lang); });
            } catch (_) { /* Keep server-rendered values on invalid optional history data. */ }
        }
        for (const role of map.keys()) if (!live.has(role)) map.delete(role);
        stores.set(panel, map);
    }
    function interrupt(panel) {
        const map = stores.get(panel); if (!map) return;
        for (const root of panel.querySelectorAll('[data-infra-series]')) {
            const history = map.get(root.dataset.seriesRole); if (history) { history.interrupt(); draw(root, history, Date.now()); }
        }
    }
    document.addEventListener('change', event => {
        if (!event.target.matches('[data-history-export-range]')) return;
        const root = event.target.closest('[data-infra-series]'), panel = root?.closest('[data-infra-usage-url]');
        const history = panel ? stores.get(panel)?.get(root.dataset.seriesRole) : null;
        if (history) { updateExport(root, history, Date.now()); root.querySelector('[data-history-export-status]')?.setAttribute('hidden',''); }
    });
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-history-export]');
        if (!button || button.disabled) return;
        const root = button.closest('[data-infra-series]'), panel = root?.closest('[data-infra-usage-url]');
        const history = panel ? stores.get(panel)?.get(root.dataset.seriesRole) : null;
        if (!history) return;
        const status = root.querySelector('[data-history-export-status]');
        let labels;
        try { labels = JSON.parse(root.querySelector('[data-history-labels]').textContent); } catch (_) { return; }
        let link, url;
        try {
            const data = exportRows(root, history);
            if (!data.length) { button.disabled = true; status.textContent = labels.export_empty; status.hidden = false; return; }
            const api = window.EasyWiInfrastructureExport;
            url = URL.createObjectURL(new Blob([api.csv(data)], {type:'text/csv;charset=utf-8'}));
            link = document.createElement('a'); link.href = url; link.download = api.filename(root.dataset.seriesRole, history.identity); document.body.append(link); link.click();
            status.textContent = labels.export_success.replace('{rows}', String(data.length)); status.hidden = false;
        } catch (_) { status.textContent = labels.export_failed; status.hidden = false; }
        finally { link?.remove(); if (url) window.setTimeout(() => URL.revokeObjectURL(url), 1000); }
    });
    window.EasyWiInfrastructureHistory = {mount, interrupt};
    document.querySelectorAll('[data-infra-usage-url]').forEach(mount);
})();
