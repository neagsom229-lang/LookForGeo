const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const src = fs.readFileSync('public/js/analysis.js', 'utf8');

function loadPollDelayHelper() {
  const m = src.match(/window.__TRACEGEO_NEXT_POLL_DELAY__ = \(attempts, aiWaiting\) => \{[\s\S]*?\};/);
  assert.ok(m, 'expected __TRACEGEO_NEXT_POLL_DELAY__ helper in analysis.js');
  const sandbox = { window: {}, POLL_DELAYS_MS: [2000, 3000, 5000] };
  vm.createContext(sandbox);
  vm.runInContext(m[0], sandbox);
  return sandbox.window.__TRACEGEO_NEXT_POLL_DELAY__;
}

test('AI-wait polling backs off to >=8s (reproducible before/after)', () => {
  const next = loadPollDelayHelper();
  // Before fix behavior (baseline): [2000,3000,5000] regardless of AI wait.
  const baseline = [1,2,3,4].map(a => [2000,3000,5000,5000][a-1]);
  const aiWait = [1,2,3,4].map(a => next(a, true));
  const normal = [1,2,3,4].map(a => next(a, false));

  assert.deepEqual(normal, baseline);
  assert.deepEqual(aiWait, [8000, 8000, 8000, 8000]);
  assert.ok(aiWait.every((ms, i) => ms >= baseline[i]), 'AI wait should not poll faster than baseline');
});

test('analysis perf instrumentation markers are present', () => {
  for (const marker of [
    'window.__TRACEGEO_PERF__',
    "perfMark('dom:init:start')",
    "perfMark('dom:init:end')",
    "perfMeasure('dom:init'",
    "perfMark('reveal:start')",
    "perfMark('reveal:end')",
    "perfMeasure('reveal'",
    'PERF.polls++',
    'PERF.aiWaitPolls++',
  ]) {
    assert.ok(src.includes(marker), `missing marker: ${marker}`);
  }
});
