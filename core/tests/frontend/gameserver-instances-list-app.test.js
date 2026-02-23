const fs = require('node:fs');
const assert = require('node:assert/strict');

const source = fs.readFileSync(require.resolve('../../public/js/gameserver/instances-list-app.js'), 'utf8');
assert.ok(source.includes('#gameserver-instances-list'), 'instances list must mount root');
assert.ok(source.includes('data-instance-card'), 'instances list must scan cards');
assert.ok(source.includes('backoffSteps = [baseInterval, 30000, 60000]'), 'instances list must use failure backoff');
assert.ok(source.includes('cardState.failCount >= 3'), 'instances list should switch to unknown after repeated failures');
assert.ok(source.includes('gs-instances-refresh'), 'instances list should support refresh now button');
assert.ok(source.includes('errors.showAll(inlineError, error)'), 'instances list should render errors via shared helper');
assert.ok(source.includes('request_id'), 'instances list should surface request id through shared errors helper');

console.log('gameserver-instances-list-app smoke test passed');
