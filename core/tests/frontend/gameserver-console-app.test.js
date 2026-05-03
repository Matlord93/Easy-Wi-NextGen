const assert = require('assert');
const fs = require('fs');

(() => {
    const source = fs.readFileSync(require.resolve('../../public/js/gameserver/console-app.js'), 'utf8');
    assert.ok(source.includes('#gameserver-console'), 'console mount selector missing');
    assert.ok(source.includes('EventSource'), 'console app must use SSE EventSource');
    assert.ok(source.includes('chunk_base64'), 'console app must decode base64 output chunks');
    assert.ok(source.includes('idempotency_key'), 'console commands should include idempotency key');
    assert.ok(source.includes('STREAM_RECONNECT'), 'console app should emit reconnect warning');
    assert.ok(source.includes('SCROLLBACK_LIMIT'), 'console app should cap scrollback buffer');
    assert.ok(source.includes("requiredDataset(root, ['urlCommand'])"), 'console app should not require streamUrl dataset key');
    assert.ok(source.includes('streamUnavailableMessage'), 'console app should use localized stream-unavailable mount message');
    assert.ok(source.includes('urlLogs'), 'console app should support log polling fallback when stream is unavailable');
    assert.ok(source.includes('startPollingFallback'), 'console app should start polling fallback without streamUrl');
    assert.ok(source.includes('X-CSRF-Token'), 'console app should send csrf header for cookie auth');
    assert.ok(source.includes('isConsoleAvailableFromHealth'), 'console app should compute availability from full health payload');
    assert.ok(source.includes("health.query_status === 'running'"), 'console app should treat query_status=running as available');
    assert.ok(source.includes("health.runtime_status === 'running'"), 'console app should treat runtime_status=running as available');
    assert.ok(source.includes("health.command_session_active === true"), 'console app should treat command session as available');
    assert.ok(source.includes("health.can_send_command === true"), 'console app should treat command capability as available');
    assert.ok(source.includes('source && source.readyState === EventSource.OPEN'), 'polling fallback should not override an open live stream banner');
    assert.ok(source.includes("payload.status === 'relay_stale'"), 'relay_stale fallback should be treated as soft fallback');
    assert.ok(source.includes('activatePollingFallback(message, payload.status === \'relay_stale\')'), 'relay_stale should not force STREAM_FALLBACK inline error');
    assert.ok(source.includes('markStreamHealthy'), 'console app should mark SSE stream healthy on incoming events');
    assert.ok(source.includes("payload.type === 'ping'"), 'ping events should mark stream healthy and clear fallback');
    assert.ok(source.includes("payload.type === 'chunk'"), 'chunk events should mark stream healthy and clear fallback');
    assert.ok(source.includes('seenChunkFingerprints'), 'console app should deduplicate replayed chunks');
    assert.ok(source.includes('if (seq < lastSeqSeen)'), 'older/replayed seq chunks should be ignored without fallback');
    assert.ok(source.includes('HEALTHY_EVENT_GRACE_MS = 30000'), 'stream errors shortly after healthy events should not force fallback');
    assert.ok(source.includes('source.onerror = async () =>'), 'stream errors should re-check health before fallback');
    assert.ok(source.includes('health.live_output_status === \'ok\''), 'fallback should be suppressed when health reports live_output_status ok');
    assert.ok(source.includes('markStreamHealthy();'), 'EventSource open and healthy events should clear fallback banner');
})();

console.log('gameserver-console-app smoke test passed');

(() => {
    const source = fs.readFileSync(require.resolve('../../public/js/gameserver/console-app.js'), 'utf8');
    assert.ok(source.includes('apiClient.buildRequestId()'), 'console command idempotency key should use apiClient.buildRequestId');
    assert.ok(source.includes("source.addEventListener('chunk', handleStreamEvent)"), 'console app should handle named chunk events');
    assert.ok(source.includes("source.addEventListener('status', handleStreamEvent)"), 'console app should handle named status events');
    assert.ok(source.includes("source.addEventListener('ping', handleStreamEvent)"), 'console app should handle named ping events');
})();
