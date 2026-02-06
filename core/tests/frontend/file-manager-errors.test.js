const assert = require('assert');
const helpers = require('../../public/js/file-manager-errors.js');

(() => {
    const fallback = 'Listing failed.';
    const payload = {
        error_code: 'agent_timeout',
        message: 'timeout',
        request_id: 'req-123',
        details: { status_code: 504 },
    };

    const normalized = helpers.normalizeErrorPayload(payload, fallback, '');
    assert.strictEqual(normalized.errorCode, 'agent_timeout');
    assert.strictEqual(normalized.message, 'timeout');
    assert.strictEqual(normalized.requestId, 'req-123');
})();

(() => {
    const normalized = helpers.normalizeErrorPayload({}, 'Fallback', 'req-xyz');
    const message = helpers.formatErrorMessage(normalized, 'Request-ID:');
    assert.ok(message.includes('Fallback'));
    assert.ok(message.includes('req-xyz'));
})();

console.log('file-manager-errors tests passed');
