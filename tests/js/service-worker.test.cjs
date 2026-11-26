const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

test('service worker cache version is bumped for new analysis assets', () => {
  const swPath = path.join(__dirname, '../../public/sw.js');
  const sw = fs.readFileSync(swPath, 'utf8');
  const m = sw.match(/const CACHE_NAME = 'tracegeo-v(\d+)';/);
  assert.ok(m, 'CACHE_NAME should be tracegeo-vN');
  const version = Number(m[1]);
  assert.ok(version >= 5, `expected cache version >= 5, got ${version}`);
  assert.notEqual(version, 4, 'cache version must be bumped from v4');
});
