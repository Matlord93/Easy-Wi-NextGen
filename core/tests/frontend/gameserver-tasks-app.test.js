const assert = require('assert');
const fs = require('fs');

(() => {
    const source = fs.readFileSync(require.resolve('../../public/js/gameserver/tasks-app.js'), 'utf8');
    assert.ok(source.includes('#gameserver-tasks'), 'tasks mount selector missing');
    assert.ok(source.includes('apiClient.request'), 'tasks app must use shared api client');
    assert.ok(source.includes('errors.showAll'), 'tasks app must show inline+toast errors');
    assert.ok(source.includes('MOUNT_CONFIG_MISSING'), 'tasks app should fail loudly on bad mount');
    assert.ok(!source.includes('urlSchedules'), 'tasks app should not require schedule endpoint');
    assert.ok(!source.includes('gs-schedule-form'), 'tasks app should not mount schedule form controls');
})();

console.log('gameserver-tasks-app smoke test passed');
