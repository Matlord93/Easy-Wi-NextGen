const assert = require('assert');
const fs = require('fs');

(() => {
    const source = fs.readFileSync(require.resolve('../../public/js/gameserver-files-app.js'), 'utf8');
    assert.ok(source.includes('#gameserver-files-app'), 'files mount selector missing');
    assert.ok(source.includes('apiClient.request'), 'files app must use shared api client');
    assert.ok(source.includes('errors.showAll'), 'files app must show inline+toast errors');
    assert.ok(source.includes('MOUNT_CONFIG_MISSING'), 'files app should fail loudly on bad mount');
})();

console.log('gameserver-files-app smoke test passed');
