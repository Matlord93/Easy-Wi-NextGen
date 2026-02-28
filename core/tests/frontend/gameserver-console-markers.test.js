const assert = require('assert');
const fs = require('fs');

(() => {
    const source = fs.readFileSync(require.resolve('../../public/js/gameserver/console-app.js'), 'utf8');
    assert.ok(source.includes('streamUrl'), 'console app must read streamUrl dataset key');
    assert.ok(source.includes('new EventSource'), 'console app must open EventSource stream');
    assert.ok(source.includes('chunk_base64'), 'console app must decode chunk payloads');
})();

console.log('gameserver-console marker smoke test passed');
