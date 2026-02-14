const assert = require('assert');
const fs = require('fs');

(() => {
    const source = fs.readFileSync(require.resolve('../../public/js/gameserver-files-app.js'), 'utf8');
    assert.ok(source.includes('#gameserver-files-app'), 'files mount selector missing');
    assert.ok(source.includes('apiClient.request'), 'files app must use shared api client');
    assert.ok(source.includes('errors.showAll'), 'files app must show inline+toast errors');
    assert.ok(source.includes('MOUNT_CONFIG_MISSING'), 'files app should fail loudly on bad mount');
    assert.ok(source.includes("data-action=\"edit\""), 'files app should render edit action for editable files');
    assert.ok(source.includes('filesContentUrl'), 'files app should use content endpoint');
    assert.ok(source.includes('errors.showAll(editorErrorEl'), 'files app should show editor error UI on failed save');
})();

console.log('gameserver-files-app smoke test passed');
