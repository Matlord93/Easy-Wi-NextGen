const assert = require('assert');
const fs = require('fs');

(() => {
    const source = fs.readFileSync(require.resolve('../../public/js/gameserver/settings-app.js'), 'utf8');
    assert.ok(source.includes('#gameserver-settings'), 'settings mount selector missing');
    assert.ok(source.includes('apiClient.request'), 'settings app must use shared api client');
    assert.ok(source.includes('errors.showAll'), 'settings app must show inline+toast errors');
    assert.ok(source.includes('MOUNT_CONFIG_MISSING'), 'settings app should fail loudly on bad mount');
})();

console.log('gameserver-settings-app smoke test passed');
