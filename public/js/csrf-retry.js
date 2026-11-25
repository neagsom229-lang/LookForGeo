/* CSRF refresh + retry shared by the upload handlers. Testable with Node. */
(function (root) {
  function isLoginResponse(res) {
    if (!res) return false;
    const url = String((res && res.url) || '');
    return res.status === 401 || (res.redirected === true && url.includes('/login'));
  }

  async function fetchWithCsrfRetry(url, options, deps) {
    const d = deps || {};
    const doFetch = d.fetch || function (u, o) { return fetch(u, o); };
    const getMeta = d.getMeta || function () {
      return (typeof document !== 'undefined')
        ? document.querySelector('meta[name="csrf-token"]')
        : null;
    };

    let res = await doFetch(url, options);
    if (isLoginResponse(res)) return { kind: 'login', response: res };
    if (res.status !== 419) return { kind: 'response', response: res };

    try {
      const tokenRes = await (d.tokenFetch
        ? d.tokenFetch()
        : fetch('/csrf-token', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store'
          }));
      if (!tokenRes || !tokenRes.ok) return { kind: 'response', response: res };
      const payload = await tokenRes.json();
      if (!payload || !payload.token) return { kind: 'response', response: res };

      const meta = getMeta();
      if (meta) meta.content = payload.token;
      const headers = new Headers(options.headers || {});
      headers.set('X-CSRF-TOKEN', payload.token);
      res = await doFetch(url, Object.assign({}, options, { headers: headers }));

      if (isLoginResponse(res)) return { kind: 'login', response: res };
      return { kind: 'response', response: res };
    } catch (err) {
      if (typeof console !== 'undefined') console.error('CSRF refresh failed:', err);
      return { kind: 'response', response: res };
    }
  }

  root.fetchWithCsrfRetry = fetchWithCsrfRetry;
  if (typeof module !== 'undefined' && module.exports) module.exports = fetchWithCsrfRetry;
})(typeof globalThis !== 'undefined' ? globalThis : this);
