const test = require('node:test');
const assert = require('node:assert/strict');
const fetchWithCsrfRetry = require('../../public/js/csrf-retry.js');

function response(status, options = {}) {
  return {
    status,
    ok: status >= 200 && status < 300,
    url: options.url || 'https://example.test/api/analyze/store',
    redirected: options.redirected === true,
    json: async () => options.json || {}
  };
}

test('419 then 200: refreshes token and retries once', async () => {
  const calls = [];
  const deps = {
    fetch: async (url, options) => {
      const h = options.headers || {};
      calls.push({ url, token: typeof h.get === 'function' ? h.get('X-CSRF-TOKEN') : h['X-CSRF-TOKEN'] });
      return calls.length === 1 ? response(419) : response(200);
    },
    tokenFetch: async () => response(200, { json: { token: 'fresh-token' } }),
    getMeta: () => ({ content: 'old' })
  };

  const result = await fetchWithCsrfRetry('/api/analyze/store', {
    method: 'POST',
    headers: { 'X-CSRF-TOKEN': 'old' }
  }, deps);

  assert.equal(result.kind, 'response');
  assert.equal(result.response.status, 200);
  assert.equal(calls.length, 2);
  assert.equal(calls[1].token, 'fresh-token');
});

test('419 then 401: asks the page to log in again', async () => {
  let n = 0;
  const deps = {
    fetch: async () => response(++n === 1 ? 419 : 401),
    tokenFetch: async () => response(200, { json: { token: 'fresh-token' } })
  };

  const result = await fetchWithCsrfRetry('/api/analyze/store', { headers: {} }, deps);

  assert.equal(result.kind, 'login');
  assert.equal(result.response.status, 401);
});

test('direct 401 or redirect to login: login required', async () => {
  const direct = await fetchWithCsrfRetry('/api/analyze/store', { headers: {} }, {
    fetch: async () => response(401)
  });
  assert.equal(direct.kind, 'login');

  const redirect = await fetchWithCsrfRetry('/api/analyze/store', { headers: {} }, {
    fetch: async () => response(200, { url: 'https://example.test/login', redirected: true })
  });
  assert.equal(redirect.kind, 'login');
});

test('token endpoint failing: returns the original 419 without retrying', async () => {
  let calls = 0;
  const deps = {
    fetch: async () => { calls++; return response(419); },
    tokenFetch: async () => response(500)
  };

  const result = await fetchWithCsrfRetry('/api/analyze/store', { headers: {} }, deps);

  assert.equal(result.kind, 'response');
  assert.equal(result.response.status, 419);
  assert.equal(calls, 1);
});
