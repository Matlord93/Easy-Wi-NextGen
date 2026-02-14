const assert = require('assert');
const fs = require('fs');

(() => {
    const source = fs.readFileSync(require.resolve('../../public/js/gameserver/console-app.js'), 'utf8');
    assert.ok(source.includes("#gameserver-console"), 'console mount selector missing');
    assert.ok(source.includes('apiClient.request'), 'console app must use shared api client');
    assert.ok(source.includes('errors.showAll'), 'console app must show inline+toast errors');
    assert.ok(source.includes('MOUNT_CONFIG_MISSING'), 'console app should fail loudly on bad mount');
})();

console.log('gameserver-console-app smoke test passed');
