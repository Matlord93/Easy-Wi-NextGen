const assert = require('assert');
const fs = require('fs');

(() => {
    const source = fs.readFileSync(require.resolve('../../public/js/gameserver/overview-app.js'), 'utf8');
    assert.ok(source.includes("#gameserver-overview-mount"), 'overview mount selector missing');
    assert.ok(source.includes('data-power-action'), 'power action binding missing');
    assert.ok(source.includes('errors.showAll'), 'shared error panel+toast usage missing');
    assert.ok(source.includes('apiClient.request'), 'shared api client usage missing');
    assert.ok(source.includes('dataset.urlQuery'), 'query endpoint mount binding missing');
    assert.ok(source.includes('Query not supported'), 'supported=false state handling missing');
})();

console.log('gameserver-overview-app smoke test passed');
