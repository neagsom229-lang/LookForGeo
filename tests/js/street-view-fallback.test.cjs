const test = require('node:test');
const assert = require('node:assert/strict');
const { createSession, safeBindBack } = require('../../public/js/street-view-fallback.js');

function makeWrap(connected = true) {
  const handlers = {};
  const backBtn = {
    onclick: null,
    addEventListener: (evt, fn) => { handlers[evt] = fn; },
  };
  const wrap = {
    isConnected: connected,
    querySelector: (sel) => (sel === '#streetBackBtn' ? backBtn : null),
    innerHTML: '',
  };
  return { wrap, backBtn, handlers };
}

function fakeTimers() {
  let now = 0;
  let nextId = 1;
  const queue = [];
  return {
    setTimeoutFn: (fn, ms) => {
      const id = nextId++;
      queue.push({ id, fn, at: now + ms });
      queue.sort((a, b) => a.at - b.at);
      return id;
    },
    clearTimeoutFn: (id) => {
      const idx = queue.findIndex(t => t.id === id);
      if (idx >= 0) queue.splice(idx, 1);
    },
    tick: (ms) => {
      now += ms;
      while (queue.length && queue[0].at <= now) {
        const t = queue.shift();
        t.fn();
      }
    }
  };
}

test('fallback does not fire after completion and does not throw', () => {
  const { wrap } = makeWrap(true);
  const timers = fakeTimers();
  let fallbackCalls = 0;
  let completed = true;

  const session = createSession({
    wrap,
    timeoutMs: 6000,
    setTimeoutFn: timers.setTimeoutFn,
    clearTimeoutFn: timers.clearTimeoutFn,
    isCompleted: () => completed,
    onFallback: () => { fallbackCalls++; },
    onBack: () => {}
  });

  assert.equal(session.isSettled(), false);
  timers.tick(7000);

  assert.equal(fallbackCalls, 0);
  assert.equal(session.isSettled(), false);
});

test('fallback does not fire when wrap is disconnected (stale timer) and does not throw', () => {
  const { wrap } = makeWrap(false);
  const timers = fakeTimers();
  let fallbackCalls = 0;

  const session = createSession({
    wrap,
    timeoutMs: 6000,
    setTimeoutFn: timers.setTimeoutFn,
    clearTimeoutFn: timers.clearTimeoutFn,
    isCompleted: () => false,
    onFallback: () => { fallbackCalls++; },
    onBack: () => {}
  });

  timers.tick(7000);
  assert.equal(fallbackCalls, 0);
  assert.doesNotThrow(() => session.triggerFallback());
});

test('safeBindBack is null-guarded', () => {
  assert.equal(safeBindBack(null, () => {}), null);
  assert.equal(safeBindBack({}, () => {}), null);
  const { wrap } = makeWrap(true);
  wrap.querySelector = () => null;
  assert.doesNotThrow(() => safeBindBack(wrap, () => {}));
});
