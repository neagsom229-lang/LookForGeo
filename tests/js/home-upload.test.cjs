const test = require('node:test');
const assert = require('node:assert/strict');
require('../../public/js/csrf-retry.js');
const { createUploadFetch } = require('../../public/js/home-upload.js');
const csrfRetry = require('../../public/js/csrf-retry.js');

test('uploadFetch delegates to namespaced helper (419 then retry)', async () => {
  let calls = 0;
  const toasts = [];
  const helper = (url, options) => csrfRetry(url, options, {
    fetch: async () => {
      calls++;
      return { status: calls === 1 ? 419 : 200, ok: calls !== 1 };
    },
    tokenFetch: async () => ({ ok: true, json: async () => ({ token: 'fresh' }) }),
    getMeta: () => ({ content: 'old' })
  });
  const uploadFetch = createUploadFetch({
    helper,
    toast: (message, kind) => toasts.push({ message, kind })
  });

  const result = await uploadFetch('/api/analyze/store', { method: 'POST' });

  assert.equal(result.status, 200);
  assert.equal(calls, 2);
  assert.deepEqual(toasts, []);
});

test('uploadFetch shows login toast on 401 result', async () => {
  const toasts = [];
  const uploadFetch = createUploadFetch({
    helper: async () => ({ kind: 'login', response: { status: 401 } }),
    toast: (message, kind) => toasts.push({ message, kind })
  });

  await assert.rejects(() => uploadFetch('/api/analyze/store', {}), /Please log in again/);
  assert.deepEqual(toasts, [{ message: 'Please log in again.', kind: 'error' }]);
});

test('missing helper fails loudly instead of recursing', () => {
  const toasts = [];
  assert.throws(
    () => createUploadFetch({ helper: undefined, useGlobal: false, toast: (m, k) => toasts.push({ m, k }) }),
    /CSRF retry helper missing/
  );
  assert.equal(toasts.length, 1);
});
