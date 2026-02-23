const assert = require('assert');
const fs = require('fs');

(() => {
    const source = fs.readFileSync(require.resolve('../../public/js/gameserver/console-app.js'), 'utf8');
    assert.ok(source.includes('#gameserver-console'), 'console mount selector missing');
    assert.ok(source.includes('apiClient.request'), 'console app must use shared api client');
    assert.ok(source.includes('errors.showAll'), 'console app must show inline errors');
    assert.ok(source.includes('MOUNT_CONFIG_MISSING'), 'console app should fail loudly on bad mount');
    assert.ok(source.includes("autoScroll = false"), 'console app should disable auto-scroll when user scrolls up');
    assert.ok(source.includes("data.meta.restarted"), 'console app should show reconnect metadata once');
})();

console.log('gameserver-console-app smoke test passed');
