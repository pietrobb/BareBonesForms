/** Configured, session-scoped visit context. No legacy per-key storage migration. */
(function (window, document) {
    'use strict';

    if (window.BBFContext) return;

    var script = document.currentScript;
    var endpoint = new URL('submit.php?action=context', script ? script.src : window.location.href).href;
    // The compatibility loader captures before downloading this module/config.
    var page = window._bbfContextPage || {
        landing_url: window.location.href,
        referrer: document.referrer || '',
        touch_at: new Date().toISOString()
    };
    var snapshot = null; var readyTimeout = window.setTimeout(function () { resolveReady(api); }, 5000);
    var automatic = ['landing_url', 'referrer', 'touch_at'];
    var own = Object.prototype.hasOwnProperty;
    var resolveReady;
    var api = {
        ready: new Promise(function (resolve) { resolveReady = resolve; }),
        // Synchronous: the renderer awaits ready, then calls fill before FormData.
        // Only configured hidden inputs are owned here, including empty values.
        fill: function (formElement) {
            if (!snapshot) return;
            var root = formElement || document;
            if (!root.querySelectorAll) return;
            Array.prototype.forEach.call(root.querySelectorAll('input'), function (field) {
                if (field.type === 'hidden' && own.call(snapshot, field.name)) {
                    field.value = snapshot[field.name];
                }
            });
        }
    };
    window.BBFContext = api;
    // Do not replace an in-flight promise created by gclid.js or the renderer.
    if (!window._bbfContextLoading) window._bbfContextLoading = api.ready;

    document.addEventListener('submit', function (event) { api.fill(event.target); }, true);
    document.addEventListener('DOMContentLoaded', function () { api.fill(document); });

    function names(value) {
        if (!Array.isArray(value) || value.some(function (name) {
            return typeof name !== 'string' || !name;
        })) throw new Error('Invalid visit_context parameter list');
        // Names are validated by the endpoint. No names are interpolated into CSS.
        return value.filter(function (name, index) {
            return automatic.indexOf(name) === -1 && value.indexOf(name) === index;
        }).sort();
    }

    function initialize(config) {
        if (!config || !config.visit_context) throw new Error('Missing visit_context');
        var triggers = names(config.visit_context.trigger_params);
        var params = names(config.visit_context.params);
        var keys = triggers.concat(params).filter(function (name, index, all) {
            return all.indexOf(name) === index;
        });
        var fields = keys.concat(automatic);
        // Exactly one JSON snapshot per endpoint/config, never individual values.
        var storageKey = 'bbf:visit-context:v1:' + JSON.stringify([endpoint, triggers, params]);
        var stored = null;
        try {
            var record = JSON.parse(window.sessionStorage.getItem(storageKey));
            if (record && record.version === 1 && record.fields &&
                typeof record.fields === 'object' && !Array.isArray(record.fields) &&
                fields.every(function (name) {
                    return own.call(record.fields, name) && typeof record.fields[name] === 'string';
                }) && record.fields.landing_url &&
                /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/.test(record.fields.touch_at) &&
                !isNaN(Date.parse(record.fields.touch_at))) {
                stored = record.fields;
            }
        } catch (ignore) { /* Missing/denied storage or malformed JSON: start in memory. */ }

        var query = new URL(page.landing_url).searchParams;
        var current = Object.create(null);
        keys.forEach(function (name) {
            current[name] = '';
            query.getAll(name).forEach(function (value) {
                // Preserve baseline click-ID normalization, never trim UTM values.
                if (triggers.indexOf(name) !== -1) value = value.trim();
                if (value !== '') current[name] = value;
            });
        });
        var newTouch = !stored || triggers.some(function (name) { return current[name] !== ''; });
        snapshot = Object.create(null);
        if (newTouch) {
            keys.forEach(function (name) { snapshot[name] = current[name]; });
            automatic.forEach(function (name) { snapshot[name] = page[name]; });
            try {
                window.sessionStorage.setItem(storageKey, JSON.stringify({ version: 1, fields: snapshot }));
            } catch (ignore) { /* The in-memory snapshot remains authoritative. */ }
        } else {
            fields.forEach(function (name) { snapshot[name] = stored[name]; });
        }
        api.fill(document);
    }

    // Config/transport failures must not prevent a form from being submitted.
    Promise.resolve().then(function () {
        return window.fetch(endpoint, { credentials: 'same-origin' });
    }).then(function (response) {
        if (!response.ok) throw new Error('Visit context config unavailable');
        return response.json();
    }).then(initialize).catch(function () {
        // No guessed defaults and no partially imported legacy attribution.
    }).then(function () { window.clearTimeout(readyTimeout); resolveReady(api); });
})(window, document);
