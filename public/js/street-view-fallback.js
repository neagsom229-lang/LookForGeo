/**
 * Street View fallback guard.
 *
 * Prevents the timeout fallback from firing after the street view has been
 * removed/replaced or after the analysis flow is complete. All DOM lookups are
 * null-guarded so a stale timer cannot throw.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.StreetViewFallback = factory();
    }
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    function safeBindBack(wrap, onBack) {
        if (!wrap || typeof wrap.querySelector !== 'function') return null;
        const backBtn = wrap.querySelector('#streetBackBtn');
        if (!backBtn) return null;
        backBtn.onclick = typeof onBack === 'function' ? onBack : null;
        return backBtn;
    }

    function createSession(options) {
        const opts = options || {};
        const wrap = opts.wrap;
        const timeoutMs = Number(opts.timeoutMs) > 0 ? Number(opts.timeoutMs) : 6000;
        const setTimeoutFn = typeof opts.setTimeoutFn === 'function' ? opts.setTimeoutFn : setTimeout;
        const clearTimeoutFn = typeof opts.clearTimeoutFn === 'function' ? opts.clearTimeoutFn : clearTimeout;
        const isCompleted = typeof opts.isCompleted === 'function' ? opts.isCompleted : () => false;
        const onFallback = typeof opts.onFallback === 'function' ? opts.onFallback : () => {};
        const onBack = typeof opts.onBack === 'function' ? opts.onBack : () => {};

        let settled = false;
        let timerId = null;

        function isActive() {
            return !settled && !!(wrap && wrap.isConnected) && !isCompleted();
        }

        function clearTimer() {
            if (timerId != null) {
                clearTimeoutFn(timerId);
                timerId = null;
            }
        }

        function markSettled() {
            settled = true;
            clearTimer();
        }

        function triggerFallback() {
            if (!isActive()) {
                if (!wrap || !wrap.isConnected) settled = true;
                clearTimer();
                return false;
            }
            markSettled();
            onFallback();
            safeBindBack(wrap, onBack);
            return true;
        }

        timerId = setTimeoutFn(() => {
            timerId = null;
            triggerFallback();
        }, timeoutMs);

        return {
            isSettled: () => settled,
            isActive,
            cancel: markSettled,
            triggerFallback,
            bindBack: () => safeBindBack(wrap, onBack)
        };
    }

    return {
        safeBindBack,
        createSession
    };
});
