const assert = require('assert');
const fs = require('fs');

(() => {
    const source = fs.readFileSync(require.resolve('../../public/js/gameserver/backups-app.js'), 'utf8');
    assert.ok(source.includes('#gameserver-backups'), 'backups mount selector missing');
    assert.ok(source.includes('apiClient.request'), 'backups app must use shared api client');
    assert.ok(source.includes('errors.showAll'), 'backups app must surface inline+toast errors');
    assert.ok(source.includes('MOUNT_CONFIG_MISSING'), 'backups app should fail loudly on bad mount');
})();

console.log('gameserver-backups-app smoke test passed');
