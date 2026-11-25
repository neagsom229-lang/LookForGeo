/* Page-level upload wrapper. Testable with Node; no Blade markup here. */
(function (root) {
  function createUploadFetch(deps) {
    const d = deps || {};
    const helper = d.helper !== undefined
      ? d.helper
      : (d.useGlobal === false ? undefined : (root.CsrfRetry && root.CsrfRetry.fetchWithCsrfRetry));
    const toast = d.toast || function (message, kind) {
      if (typeof root.showToast === 'function') root.showToast(message, kind);
    };

    if (typeof helper !== 'function') {
      toast('Upload helper failed to load. Please hard-refresh the page.', 'error');
      throw new Error('CSRF retry helper missing');
    }

    // Different name on purpose: helper lives at CsrfRetry.fetchWithCsrfRetry.
    return function uploadFetch(url, options) {
      return helper(url, options).then(function (result) {
        if (result.kind === 'login') {
          toast('Please log in again.', 'error');
          throw new Error('Please log in again.');
        }
        return result.response;
      });
    };
  }

  root.HomeUpload = Object.assign(root.HomeUpload || {}, { createUploadFetch });
  if (typeof module !== 'undefined' && module.exports) module.exports = { createUploadFetch };
})(typeof globalThis !== 'undefined' ? globalThis : this);
