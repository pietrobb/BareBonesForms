/** Optional adapter: use only the page's existing Umami tracker. */
(function () {
    'use strict';
    if (window._bbfUmamiListener) return;
    window._bbfUmamiListener = true;
    document.addEventListener('bbf:submitted', function (event) {
        var detail = event.detail; if (!event.bbfAnalytics || event.bbfAnalytics.umami !== true) return;
        if (!detail || typeof detail.form !== 'string' || typeof detail.submission_id !== 'string') return;
        try {
            if (!window.umami || typeof window.umami.track !== 'function') return;
            var sent = window.umami.track('form_submitted', {
                form: detail.form, submission_id: detail.submission_id
            });
            if (sent && typeof sent.catch === 'function') sent.catch(function () {});
        } catch (error) { /* Analytics cannot change a successful submission. */ }
    });
})();
