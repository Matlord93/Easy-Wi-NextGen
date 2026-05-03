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
})();

console.log('gameserver-console-app smoke test passed');

(() => {
    const source = fs.readFileSync(require.resolve('../../public/js/gameserver/console-app.js'), 'utf8');
    assert.ok(source.includes('apiClient.buildRequestId()'), 'console command idempotency key should use apiClient.buildRequestId');
    assert.ok(source.includes("source.addEventListener('chunk', handleStreamEvent)"), 'console app should handle named chunk events');
    assert.ok(source.includes("source.addEventListener('status', handleStreamEvent)"), 'console app should handle named status events');
    assert.ok(source.includes("source.addEventListener('ping', handleStreamEvent)"), 'console app should handle named ping events');
})();
