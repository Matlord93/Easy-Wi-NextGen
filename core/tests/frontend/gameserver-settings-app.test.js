const assert = require('assert');
const fs = require('fs');

(() => {
    const source = fs.readFileSync(require.resolve('../../public/js/gameserver/settings-app.js'), 'utf8');
    assert.ok(source.includes('#gameserver-settings'), 'settings mount selector missing');
    assert.ok(source.includes('apiClient.request'), 'settings app must use shared api client');
    assert.ok(source.includes('errors.showAll'), 'settings app must show inline+toast errors');
    assert.ok(source.includes('MOUNT_CONFIG_MISSING'), 'settings app should fail loudly on bad mount');
    assert.ok(source.includes('summary.data || {}'), 'settings app should bootstrap configs from settings payload');
    assert.ok(source.includes('gs-settings-create'), 'settings app should support creating configs');
    assert.ok(source.includes('errors.showToast'), 'settings app should show success/error feedback through shared errors helper');
    assert.ok(source.includes('gs-auto-backup-time'), 'settings app should control auto-backup time input');
    assert.ok(source.includes('gs-auto-restart-time'), 'settings app should control auto-restart time input');
    assert.ok(source.includes('gs-auto-update-time'), 'settings app should control auto-update time input');
    assert.ok(source.includes('time: autoBackupTime?.value'), 'settings app should submit automation times');
    assert.ok(source.includes('urlAccessHealth'), 'settings app should require access health endpoint');
    assert.ok(source.includes('gs-access-reveal'), 'settings app should support one-time password reveal');
})();

console.log('gameserver-settings-app smoke test passed');
