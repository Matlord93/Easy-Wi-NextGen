(function () {
    'use strict';
    const loadFields = ['cpu_percent', 'memory_percent', 'disk_percent', 'memory_used_bytes', 'memory_total_bytes', 'disk_used_bytes', 'disk_total_bytes'];
    const ioFields = ['net_rx_bytes_per_second', 'net_tx_bytes_per_second', 'disk_read_bytes_per_second', 'disk_write_bytes_per_second'];
    const headers = ['exported_at_utc', 'view_label', 'view_path', 'scope', 'series_key', 'sample_type', 'sample_missing', 'observed_at_utc', 'source', 'interval_seconds', 'memory_source', 'gap_before', 'stale_at_export', 'stream_interrupted_at_export', ...loadFields, ...ioFields];
    const loadSources = ['agent_heartbeat', 'proxmox_status', 'proxmox_cluster'];
    function numeric(value) { return typeof value === 'number' && Number.isFinite(value) && value >= 0 ? (value === 0 ? 0 : value) : null; }
    function text(value) { return typeof value === 'string' ? Array.from(value).slice(0, 160).join('') : ''; }
    function stamp(value, now) {
        if (typeof value !== 'string') return null;
        const parts = /^(\d{4})-(\d\d)-(\d\d)T\d\d:\d\d:\d\d(?:\.\d+)?(?:Z|[+-]\d\d:\d\d)$/.exec(value);
        if (!parts) return null;
        const year = Number(parts[1]), month = Number(parts[2]), day = Number(parts[3]);
        const leap = year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0);
        const days = [31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        const result = Date.parse(value);
        return month >= 1 && month <= 12 && day >= 1 && day <= days[month - 1] && Number.isFinite(result) && result >= 0 && result <= now + 30000 ? result : null;
    }
    function rows(history, sample, mode, context, now = Date.now()) {
        if (!history || !sample || !context || !['server', 'node'].includes(context.role) || !/^[a-f0-9]{64}$/.test(sample.series_key || '') || history.identity !== sample.series_key) return [];
        if (!['current', '5', '20'].includes(mode)) return [];
        const points = [];
        if (mode === 'current') {
            const loadTime = stamp(sample.observed_at, now), ioTime = stamp(sample.io_observed_at, now);
            if (loadTime !== null) points.push({type: 'load', time: loadTime, values: sample, source: sample.load_source, memory_source: sample.memory_source, stale: sample.stale === true, gap: [...(history.load || [])].reverse().find(point => point.time === loadTime)?.gap === true});
            if (ioTime !== null && ['counter_delta', 'proxmox_rrd'].includes(sample.io_source) && numeric(sample.io_interval_seconds) > 0 && sample.io_interval_seconds <= 120) points.push({type: 'io', time: ioTime, values: sample, source: sample.io_source, interval: sample.io_interval_seconds, stale: sample.io_stale === true, gap: [...(history.io || [])].reverse().find(point => point.time === ioTime)?.gap === true});
        } else {
            const cutoff = now - Number(mode) * 60000;
            for (const point of (history.load || []).slice(-60)) if (point.time >= cutoff) points.push({...point, type: 'load'});
            for (const point of (history.io || []).slice(-60)) if (point.time >= cutoff) points.push({...point, type: 'io'});
        }
        // Keep load and IO observations separate: provider timestamps can differ.
        const result = points.filter(point => Number.isFinite(point.time) && point.time >= 0 && point.time <= now + 30000).sort((a, b) => a.time - b.time || a.type.localeCompare(b.type)).flatMap(point => {
            if (point.type === 'io' && context.role !== 'server') return [];
            const row = Object.fromEntries(headers.map(key => [key, null]));
            const keys = point.type === 'load' ? loadFields : ioFields;
            for (const key of keys) row[key] = numeric(point.values?.[key]);
            const missing = !keys.some(key => row[key] !== null);
            if (mode === 'current' && missing) return [];
            Object.assign(row, {
                exported_at_utc: new Date(now).toISOString(),
                view_label: text(context.label),
                view_path: typeof context.path === 'string' && context.path.length <= 512 && /^\/[A-Za-z0-9_./%~-]*$/.test(context.path) ? context.path : '',
                scope: context.role, series_key: sample.series_key,
                sample_type: point.type, sample_missing: missing ? 1 : 0,
                observed_at_utc: new Date(point.time).toISOString(),
                source: (point.type === 'load' ? loadSources : ['counter_delta', 'proxmox_rrd']).includes(point.source) ? point.source : 'not_reported',
                interval_seconds: point.type === 'io' && numeric(point.interval) > 0 && point.interval <= 120 ? point.interval : null,
                memory_source: point.type === 'load' && ['guest_balloon', 'host_rss'].includes(point.memory_source) ? point.memory_source : null,
                gap_before: point.gap ? 1 : 0,
                stale_at_export: point.stale || now - point.time > 120000 ? 1 : 0,
                stream_interrupted_at_export: history.interrupted ? 1 : 0,
            });
            return [row];
        });
        return result.some(row => row.sample_missing === 0) ? result : [];
    }
    function cell(value) {
        if (value === null || value === undefined) return '""';
        let string = String(value);
        // A quoted CSV cell can still be interpreted as a spreadsheet formula.
        if (typeof value === 'string' && /^[\s\p{Cc}\p{Cf}]*[=+\-@]/u.test(string)) string = "'" + string;
        return '"' + string.replace(/"/g, '""') + '"';
    }
    function csv(data) { return '\ufeff' + [headers, ...data.map(row => headers.map(key => row[key]))].map(row => row.map(cell).join(',')).join('\r\n') + '\r\n'; }
    function filename(role, identity, now = Date.now()) {
        const scope = role === 'server' ? 'server' : 'node';
        const key = /^[a-f0-9]{64}$/.test(identity || '') ? identity.slice(0, 12) : 'unknown';
        return 'easywi-' + scope + '-' + key + '-' + new Date(now).toISOString().replace(/[-:]/g, '').replace(/\.\d{3}Z$/, 'Z') + '.csv';
    }
    const api = {rows, csv, filename, headers};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    if (typeof window !== 'undefined') window.EasyWiInfrastructureExport = api;
})();
