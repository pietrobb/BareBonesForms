/** Compatibility URL: all visit-context behavior lives in bbf-context.js. */
(function (window, document) {
    'use strict';

    if (window.BBFContext) {
        if (!window._bbfContextLoading) window._bbfContextLoading = window.BBFContext.ready;
        return;
    }
    // Capture now, not after either script loading or config fetching finishes.
    if (!window._bbfContextPage) window._bbfContextPage = {
        landing_url: window.location.href,
        referrer: document.referrer || '',
        touch_at: new Date().toISOString()
    };
    if (window._bbfContextLoading) return;

    var source = document.currentScript;
    var url = new URL('bbf-context.js', source ? source.src : window.location.href).href;
    var resolveLoading; var loadingTimeout = window.setTimeout(failed, 5000);
    var loading = new Promise(function (resolve) { resolveLoading = resolve; });
    // Publish before inserting the script; the renderer must use the same guard.
    window._bbfContextLoading = loading;

    function failed() {
        if (window._bbfContextLoading === loading) window._bbfContextLoading = null;
        window.clearTimeout(loadingTimeout); resolveLoading(null);
    }
    try {
        var script = document.createElement('script');
        script.src = url;
        script.async = true;
        script.onload = function () {
            if (!window.BBFContext) { failed(); return; }
            window.BBFContext.ready.then(function (api) { window.clearTimeout(loadingTimeout); resolveLoading(api); }, failed);
        };
        script.onerror = failed;
        (document.head || document.documentElement).appendChild(script);
    } catch (ignore) { failed(); }
})(window, document);
