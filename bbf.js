/**
 * BareBonesForms — Renderer  v1.0.1
 *
 * Zero dependencies. Fetches form JSON, renders HTML form, validates, submits.
 *
 * Usage:
 *   <div id="bbf-kontakt" data-form="kontakt"></div>
 *   <script src="bbf.js"></script>
 *
 * Or manually:
 *   BBF.render('kontakt', '#my-container');
 *
 * Language support:
 *   <script src="bbf.js"></script>
 *   <script src="lang/de.js"></script>
 *   <div data-form="kontakt" data-lang="de"></div>
 *
 * Styling: bbf.css is auto-loaded by this script. Override with your own CSS as needed.
 */

(function() {
    'use strict';

    const BBF = {
        baseUrl: (function() {
            const scripts = document.getElementsByTagName('script');
            const src = scripts[scripts.length - 1].src;
            return src.substring(0, src.lastIndexOf('/') + 1);
        })(),

        // ─── Auto-load bbf.css if not already present ───────
        _cssInjected: (function() {
            const scripts = document.getElementsByTagName('script');
            const src = scripts[scripts.length - 1].src;
            const base = src.substring(0, src.lastIndexOf('/') + 1);
            const cssUrl = base + 'bbf.css';
            // Check if already loaded
            const links = document.querySelectorAll('link[rel="stylesheet"]');
            for (let i = 0; i < links.length; i++) {
                if (links[i].href === cssUrl) return true;
            }
            // Inject it
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = cssUrl;
            document.head.appendChild(link);
            return true;
        })(),

        // ─── Language ────────────────────────────────────────
        // Default messages (English). Override by loading a language file
        // or calling BBF.registerLang().
        lang: {
            loading:          'Loading…',
            submitDefault:    'Submit',
            submittingDefault:'Sending…',
            successDefault:   'Thank you! Your submission has been received.',
            errorDefault:     'Something went wrong.',
            networkError:     'Network error. Please try again.',
            serverError:      'Server error ({status})',
            formNotFound:     'Form "{id}" not found ({status})',
            loadError:        'Failed to load form: {message}',
            required:         '{label} is required.',
            invalidEmail:     '{label} must be a valid email.',
            invalidUrl:       '{label} must be a valid URL.',
            invalidTel:       '{label} must be a valid phone number.',
            invalidNumber:    '{label} must be a number.',
            numberMin:        '{label} must be at least {min}.',
            numberMax:        '{label} must be at most {max}.',
            invalidOption:    '{label} contains an invalid selection.',
            tooShort:         '{label} must be at least {min} characters.',
            tooLong:          '{label} must be at most {max} characters.',
            invalidFormat:    '{label} has invalid format.',
            emailMismatch:    '{label} addresses do not match.',
            dateMin:          '{label} must be on or after {min}.',
            dateMax:          '{label} must be on or before {max}.',
            nextPage:         'Next',
            prevPage:         'Previous',
            crossFieldDefault:'Please check your input.',
        },

        // Registered language packs: { de: {...}, sk: {...}, ... }
        langs: {},

        /**
         * Register a language pack.
         * Usage: BBF.registerLang('de', { required: '{label} ist erforderlich.', ... });
         */
        registerLang: function(code, messages) {
            this.langs[code] = messages;
        },

        /**
         * Translate a message key with parameters.
         * _t('required', { label: 'Email' }, 'de') → 'Email ist erforderlich.'
         */
        _t: function(key, params, langCode) {
            const msgs = (langCode && this.langs[langCode]) || this.lang;
            let msg = msgs[key] || this.lang[key] || key;
            if (params) {
                Object.keys(params).forEach(k => {
                    msg = msg.replace(new RegExp('\\{' + k + '\\}', 'g'), params[k]);
                });
            }
            return msg;
        },

        /**
         * Render a form into a container
         */
        render: async function(formId, containerSelector, options = {}) {
            const container = typeof containerSelector === 'string'
                ? document.querySelector(containerSelector)
                : containerSelector;

            if (!container) {
                console.error(`BareBonesForms: container not found: ${containerSelector}`);
                return;
            }

            // Resolve language: options.lang > data-lang > global default
            const langCode = options.lang
                || container.getAttribute('data-lang')
                || null;

            container.classList.add('bbf-loading');
            container.innerHTML = `<div class="bbf-spinner">${this._t('loading', {}, langCode)}</div>`;

            try {
                const baseUrl = options.baseUrl || this.baseUrl;
                const isSameOrigin = new URL(baseUrl, location.href).origin === location.origin;

                // Load form definition (always via submit.php — it strips
                // server-side config and works with .htaccess protection)
                const formUrl = `${baseUrl}submit.php?form=${formId}&action=definition`;
                const resp = await fetch(formUrl);
                if (!resp.ok) throw new Error(this._t('formNotFound', { id: formId, status: resp.status }, langCode));
                const form = await resp.json();

                // Fetch CSRF token for same-origin requests
                let csrfToken = null;
                if (isSameOrigin) {
                    try {
                        const csrfResp = await fetch(
                            `${baseUrl}submit.php?form=${formId}&action=csrf`,
                            { credentials: 'same-origin' }
                        );
                        if (csrfResp.ok) {
                            const csrfData = await csrfResp.json();
                            csrfToken = csrfData.csrf_token || null;
                        }
                    } catch (e) { /* CSRF may be disabled */ }
                }

                container.innerHTML = '';
                container.classList.remove('bbf-loading');
                container.classList.add('bbf-form-container');

                const formEl = this._buildForm(form, formId, baseUrl, options, csrfToken, langCode, isSameOrigin);
                container.appendChild(formEl);
            } catch (err) {
                container.innerHTML = `<div class="bbf-error">${this._t('loadError', { message: err.message }, langCode)}</div>`;
                console.error('BareBonesForms:', err);
            }
        },

        // ─── Pagination helpers ──────────────────────────────

        _splitPages: function(fields) {
            const pages = [[]];
            fields.forEach(f => {
                if (f.type === 'page_break') {
                    pages.push([]);
                } else {
                    pages[pages.length - 1].push(f);
                }
            });
            return pages;
        },

        _showPage: function(formEl, pageIndex, totalPages, langCode) {
            formEl.querySelectorAll('.bbf-page').forEach((p, i) => {
                p.style.display = i === pageIndex ? '' : 'none';
            });
            const nav = formEl.querySelector('.bbf-page-nav');
            if (nav) {
                nav.querySelector('.bbf-prev').style.display = pageIndex > 0 ? '' : 'none';
                nav.querySelector('.bbf-next').style.display = pageIndex < totalPages - 1 ? '' : 'none';
                nav.querySelector('.bbf-submit').style.display = pageIndex === totalPages - 1 ? '' : 'none';
                const indicator = nav.querySelector('.bbf-page-indicator');
                if (indicator) indicator.textContent = `${pageIndex + 1} / ${totalPages}`;
            }
        },

        // ─── Field helpers ──────────────────────────────────

        // Flatten nested group fields into a flat array
        _flattenFields: function(fields) {
            const result = [];
            (fields || []).forEach(f => {
                result.push(f);
                if (f.type === 'group' && f.fields) {
                    this._flattenFields(f.fields).forEach(child => result.push(child));
                }
            });
            return result;
        },

        // Check if element or any ancestor is conditionally hidden
        _isHidden: function(el) {
            while (el) {
                if (el.getAttribute && el.getAttribute('data-conditional-hidden') === 'true') return true;
                if (el.classList && el.classList.contains('bbf-form')) return false;
                el = el.parentElement;
            }
            return false;
        },

        // ─── Conditional logic ───────────────────────────────

        // Get current value of a form field by name
        _getFieldValue: function(formEl, fieldName) {
            const inputs = formEl.querySelectorAll(`input[name="${fieldName}"]`);
            if (inputs.length > 0 && inputs[0].type === 'radio') {
                const checked = formEl.querySelector(`input[name="${fieldName}"]:checked`);
                return checked ? checked.value : '';
            }
            if (inputs.length > 0 && inputs[0].type === 'checkbox') {
                return Array.from(formEl.querySelectorAll(`input[name="${fieldName}"]:checked`)).map(c => c.value);
            }
            const el = formEl.querySelector(`[name="${fieldName}"]`);
            return el ? el.value : '';
        },

        // Compare a field value against a target using an operator
        _compareValues: function(currentVal, targetVal, op) {
            // empty / not_empty — no target needed
            if (op === 'empty') {
                return Array.isArray(currentVal) ? currentVal.length === 0 : !currentVal;
            }
            if (op === 'not_empty') {
                return Array.isArray(currentVal) ? currentVal.length > 0 : !!currentVal;
            }

            const targets = Array.isArray(targetVal) ? targetVal : [targetVal];

            // String contains
            if (op === 'contains') {
                if (Array.isArray(currentVal)) {
                    return targets.some(t => currentVal.some(v => String(v).includes(String(t))));
                }
                return targets.some(t => String(currentVal).includes(String(t)));
            }

            // Numeric comparisons
            if (op === 'gt' || op === 'gte' || op === 'lt' || op === 'lte') {
                const num = parseFloat(currentVal);
                const tgt = parseFloat(targets[0]);
                if (isNaN(num) || isNaN(tgt)) return false;
                if (op === 'gt')  return num > tgt;
                if (op === 'gte') return num >= tgt;
                if (op === 'lt')  return num < tgt;
                if (op === 'lte') return num <= tgt;
            }

            // Not equals
            if (op === 'not') {
                return Array.isArray(currentVal)
                    ? !currentVal.some(v => targets.includes(v))
                    : !targets.includes(currentVal);
            }

            // Default: equals
            return Array.isArray(currentVal)
                ? currentVal.some(v => targets.includes(v))
                : targets.includes(currentVal);
        },

        // Recursively evaluate a condition (supports all/any nesting)
        _evalCondition: function(cond, formEl) {
            // all: every sub-condition must be true
            if (cond.all) {
                return cond.all.every(c => this._evalCondition(c, formEl));
            }
            // any: at least one sub-condition must be true
            if (cond.any) {
                return cond.any.some(c => this._evalCondition(c, formEl));
            }
            // Simple condition: { field, value, op }
            if (cond.field) {
                const val = this._getFieldValue(formEl, cond.field);
                return this._compareValues(val, cond.value, cond.op);
            }
            return true;
        },

        // Apply show_if on individual options within radio/checkbox/select
        _applyOptionConditions: function(formEl) {
            var self = this;
            formEl.querySelectorAll('[data-option-show-if]').forEach(function(optEl) {
                var cond = JSON.parse(optEl.getAttribute('data-option-show-if'));
                var visible = self._evalCondition(cond, formEl);
                optEl.style.display = visible ? '' : 'none';
                // If hiding a checked option, uncheck it
                if (!visible) {
                    var inp = optEl.querySelector('input');
                    if (inp && inp.checked) { inp.checked = false; }
                }
            });
        },

        // Recursively collect all source field names from a condition tree
        _collectSources: function(cond) {
            const sources = new Set();
            if (cond.field) sources.add(cond.field);
            if (cond.all) cond.all.forEach(c => this._collectSources(c).forEach(s => sources.add(s)));
            if (cond.any) cond.any.forEach(c => this._collectSources(c).forEach(s => sources.add(s)));
            return sources;
        },

        _applyConditions: function(formEl, fields, animate) {
            fields.forEach(field => {
                if (!field.show_if) return;
                const wrap = formEl.querySelector(`[data-field="${field.name}"]`);
                if (!wrap) return;

                const visible = this._evalCondition(field.show_if, formEl);
                const wasHidden = wrap.getAttribute('data-conditional-hidden') === 'true';

                if (!animate || (visible && !wasHidden) || (!visible && wasHidden)) {
                    // No animation: instant show/hide (initial state or no change)
                    wrap.style.display = visible ? '' : 'none';
                    wrap.setAttribute('data-conditional-hidden', visible ? '' : 'true');
                    return;
                }

                if (visible) {
                    // Animate in
                    wrap.setAttribute('data-conditional-hidden', '');
                    wrap.style.display = '';
                    wrap.style.overflow = 'hidden';
                    wrap.style.maxHeight = '0';
                    wrap.style.opacity = '0';
                    requestAnimationFrame(() => {
                        wrap.style.transition = 'max-height 0.3s ease, opacity 0.25s ease';
                        wrap.style.maxHeight = wrap.scrollHeight + 'px';
                        wrap.style.opacity = '1';
                        const done = () => { wrap.style.maxHeight = ''; wrap.style.overflow = ''; wrap.style.transition = ''; wrap.style.opacity = ''; wrap.removeEventListener('transitionend', done); };
                        wrap.addEventListener('transitionend', done, { once: true });
                        setTimeout(done, 350); // fallback
                    });
                } else {
                    // Animate out
                    wrap.style.maxHeight = wrap.scrollHeight + 'px';
                    wrap.style.overflow = 'hidden';
                    requestAnimationFrame(() => {
                        wrap.style.transition = 'max-height 0.3s ease, opacity 0.2s ease';
                        wrap.style.maxHeight = '0';
                        wrap.style.opacity = '0';
                        const done = () => { wrap.style.display = 'none'; wrap.style.transition = ''; wrap.style.maxHeight = ''; wrap.style.overflow = ''; wrap.style.opacity = ''; wrap.removeEventListener('transitionend', done); };
                        wrap.addEventListener('transitionend', done, { once: true });
                        setTimeout(done, 350); // fallback
                    });
                    wrap.setAttribute('data-conditional-hidden', 'true');
                }
            });
        },

        _bindConditions: function(formEl, fields, animate) {
            const self = this;
            const sources = new Set();
            fields.forEach(f => {
                if (f.show_if) this._collectSources(f.show_if).forEach(s => sources.add(s));
                // Collect sources from option-level show_if
                if (f.options) f.options.forEach(o => {
                    if (typeof o === 'object' && o.show_if) this._collectSources(o.show_if).forEach(s => sources.add(s));
                });
            });
            var handler = function() {
                self._applyConditions(formEl, fields, animate);
                self._applyOptionConditions(formEl);
            };
            sources.forEach(srcName => {
                const inputs = formEl.querySelectorAll(`[name="${srcName}"]`);
                inputs.forEach(inp => {
                    inp.addEventListener('change', handler);
                    inp.addEventListener('input', handler);
                });
            });
            // Initial state (always instant, no animation)
            this._applyConditions(formEl, fields, false);
            this._applyOptionConditions(formEl);
        },

        // ─── Build form ──────────────────────────────────────

        _buildForm: function(form, formId, baseUrl, options, csrfToken, langCode, isSameOrigin) {
            // Resolve templates before building
            if (form.templates) {
                form.fields = this._resolveTemplates(form.fields || [], form.templates);
            }

            const el = document.createElement('form');
            el.className = 'bbf-form';
            if (form.label_position === 'left' || form.label_position === 'right') {
                el.classList.add(`bbf-labels-${form.label_position}`);
            }
            el.noValidate = true;
            el.setAttribute('data-form-id', formId);

            // Title
            if (form.name && options.showTitle !== false) {
                const title = document.createElement('h2');
                title.className = 'bbf-title';
                title.textContent = form.name;
                el.appendChild(title);
            }

            // Description
            if (form.description) {
                const desc = document.createElement('p');
                desc.className = 'bbf-description';
                desc.textContent = form.description;
                el.appendChild(desc);
            }

            // Flatten groups and filter out non-data types
            const allFlat = this._flattenFields(form.fields || []);
            const dataFields = allFlat.filter(f => f.type !== 'page_break' && f.type !== 'section' && f.type !== 'group');

            // Check for multi-page
            const hasPages = (form.fields || []).some(f => f.type === 'page_break');
            let pages, currentPage;

            if (hasPages) {
                pages = this._splitPages(form.fields || []);
                currentPage = { value: 0 };

                pages.forEach((pageFields, pi) => {
                    const pageDiv = document.createElement('div');
                    pageDiv.className = 'bbf-page';
                    pageDiv.setAttribute('data-page', pi);
                    if (pi > 0) pageDiv.style.display = 'none';

                    pageFields.forEach(field => {
                        pageDiv.appendChild(this._buildField(field, langCode));
                    });
                    el.appendChild(pageDiv);
                });
            } else {
                (form.fields || []).forEach(field => {
                    el.appendChild(this._buildField(field, langCode));
                });
            }

            // Honeypot (hidden anti-spam)
            const hp = document.createElement('div');
            hp.style.cssText = 'position:absolute;left:-9999px;top:-9999px;';
            hp.setAttribute('aria-hidden', 'true');
            hp.innerHTML = `<input type="text" name="_bbf_hp" tabindex="-1" autocomplete="off">`;
            el.appendChild(hp);

            // CSRF token
            if (csrfToken) {
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = '_bbf_csrf';
                csrfInput.value = csrfToken;
                el.appendChild(csrfInput);
            }

            // Navigation / Submit
            if (hasPages) {
                const nav = document.createElement('div');
                nav.className = 'bbf-field bbf-page-nav';

                const prevBtn = document.createElement('button');
                prevBtn.type = 'button';
                prevBtn.className = 'bbf-prev';
                prevBtn.textContent = this._t('prevPage', {}, langCode);
                prevBtn.style.display = 'none';

                const indicator = document.createElement('span');
                indicator.className = 'bbf-page-indicator';
                indicator.textContent = `1 / ${pages.length}`;

                const nextBtn = document.createElement('button');
                nextBtn.type = 'button';
                nextBtn.className = 'bbf-next';
                nextBtn.textContent = this._t('nextPage', {}, langCode);

                const submitBtn = document.createElement('button');
                submitBtn.type = 'submit';
                submitBtn.className = 'bbf-submit';
                submitBtn.textContent = form.submit_label || this._t('submitDefault', {}, langCode);
                submitBtn.style.display = 'none';

                nav.appendChild(prevBtn);
                nav.appendChild(indicator);
                nav.appendChild(nextBtn);
                nav.appendChild(submitBtn);
                el.appendChild(nav);

                // Page navigation handlers
                const self = this;
                nextBtn.addEventListener('click', () => {
                    // Validate current page fields (flatten groups)
                    const pageFlat = self._flattenFields(pages[currentPage.value]);
                    const pageFields = pageFlat.filter(f => f.type !== 'section' && f.type !== 'group');
                    const errors = self._validate(pageFields, el, langCode);
                    self._showErrors(el, errors);
                    if (Object.keys(errors).length > 0) return;

                    currentPage.value++;
                    self._showPage(el, currentPage.value, pages.length, langCode);
                });
                prevBtn.addEventListener('click', () => {
                    currentPage.value--;
                    self._showPage(el, currentPage.value, pages.length, langCode);
                });
            } else {
                const btnWrap = document.createElement('div');
                btnWrap.className = 'bbf-field bbf-submit-wrap';
                const btn = document.createElement('button');
                btn.type = 'submit';
                btn.className = 'bbf-submit';
                btn.textContent = form.submit_label || this._t('submitDefault', {}, langCode);
                btnWrap.appendChild(btn);
                el.appendChild(btnWrap);
            }

            // Message area (before submit button so errors are visible)
            const msg = document.createElement('div');
            msg.className = 'bbf-message';
            msg.setAttribute('role', 'status');
            msg.setAttribute('aria-live', 'polite');
            msg.style.display = 'none';
            const submitWrap = el.querySelector('.bbf-submit-wrap, .bbf-page-nav');
            if (submitWrap) el.insertBefore(msg, submitWrap); else el.appendChild(msg);

            // Submit handler
            el.addEventListener('submit', async (e) => {
                e.preventDefault();
                const btn = el.querySelector('.bbf-submit');

                // Validate ALL data fields (flatten groups, skip non-data types)
                const allFields = allFlat.filter(f => f.type !== 'page_break' && f.type !== 'section' && f.type !== 'group');
                const errors = this._validate(allFields, el, langCode);
                // Cross-field validations
                const crossErrors = this._validateCrossField(form.validations, el, langCode);
                Object.assign(errors, crossErrors);
                this._showErrors(el, errors);
                if (Object.keys(errors).length > 0) return;

                btn.disabled = true;
                btn.textContent = form.submitting_label || this._t('submittingDefault', {}, langCode);

                try {
                    const data = new FormData(el);
                    const body = {};
                    data.forEach((v, k) => {
                        if (body[k]) {
                            if (!Array.isArray(body[k])) body[k] = [body[k]];
                            body[k].push(v);
                        } else {
                            body[k] = v;
                        }
                    });

                    // Remove conditionally hidden fields (and group children) from submission
                    el.querySelectorAll('[data-conditional-hidden="true"]').forEach(hiddenWrap => {
                        const fname = hiddenWrap.getAttribute('data-field');
                        if (fname) delete body[fname];
                        // Also remove all fields inside hidden groups
                        hiddenWrap.querySelectorAll('[data-field]').forEach(child => {
                            const cn = child.getAttribute('data-field');
                            if (cn) delete body[cn];
                        });
                    });

                    const fetchOpts = {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(body),
                    };
                    if (isSameOrigin) fetchOpts.credentials = 'same-origin';
                    const sandboxParam = new URLSearchParams(window.location.search).has('sandbox') ? '&sandbox' : '';
                    const resp = await fetch(`${baseUrl}submit.php?form=${formId}${sandboxParam}`, fetchOpts);

                    let result;
                    const contentType = resp.headers.get('content-type') || '';
                    if (contentType.includes('application/json')) {
                        result = await resp.json();
                    } else {
                        const text = await resp.text();
                        result = { status: 'error', message: resp.ok ? text : this._t('serverError', { status: resp.status }, langCode) };
                    }

                    if (result.status === 'ok' && result.sandbox) {
                        msg.className = 'bbf-message bbf-success';
                        msg.innerHTML = this._renderSandboxPreview(result);
                        msg.style.display = 'block';
                        this._clearErrors(el);
                    } else if (result.status === 'ok') {
                        if (result.redirect) {
                            window.location.href = result.redirect;
                            return;
                        }
                        msg.className = 'bbf-message bbf-success';
                        msg.textContent = form.success_message || this._t('successDefault', {}, langCode);
                        msg.style.display = 'block';
                        el.reset();
                        this._clearErrors(el);

                        if (options.hideOnSuccess) {
                            Array.from(el.querySelectorAll('.bbf-field, .bbf-submit-wrap, .bbf-page, .bbf-page-nav')).forEach(f => f.style.display = 'none');
                        }
                    } else {
                        if (result.errors) {
                            this._showErrors(el, result.errors);
                        }
                        msg.className = 'bbf-message bbf-error';
                        msg.textContent = result.message || this._t('errorDefault', {}, langCode);
                        msg.style.display = 'block';
                    }
                } catch (err) {
                    msg.className = 'bbf-message bbf-error';
                    msg.textContent = this._t('networkError', {}, langCode);
                    msg.style.display = 'block';
                    console.error('BareBonesForms submit error:', err);
                }

                btn.disabled = false;
                btn.textContent = form.submit_label || this._t('submitDefault', {}, langCode);
            });

            // Bind conditional logic (use flattened fields to catch group children)
            const condFields = allFlat.filter(f => f.show_if);
            if (condFields.length > 0) {
                this._bindConditions(el, allFlat, !!form.animate_conditions);
            }

            return el;
        },

        // ─── Template resolution ─────────────────────────────
        // Resolves "use" references on group fields by cloning
        // template fields and prefixing their names.

        _resolveTemplates: function(fields, templates) {
            if (!templates) return fields;
            var self = this;
            return fields.map(function(field) {
                if (field.type === 'group' && field.use && templates[field.use]) {
                    var prefix = field.prefix || '';
                    var tplFields = JSON.parse(JSON.stringify(templates[field.use]));
                    var tplNames = {};
                    tplFields.forEach(function(f) { tplNames[f.name] = true; });
                    var resolved = Object.assign({}, field);
                    resolved.fields = self._prefixFields(tplFields, prefix, tplNames);
                    delete resolved.use;
                    delete resolved.prefix;
                    return resolved;
                }
                if (field.type === 'group' && field.fields) {
                    var resolved = Object.assign({}, field);
                    resolved.fields = self._resolveTemplates(field.fields, templates);
                    return resolved;
                }
                return field;
            });
        },

        _prefixFields: function(fields, prefix, tplNames) {
            var self = this;
            return fields.map(function(field) {
                var f = Object.assign({}, field);
                f.name = prefix + f.name;
                if (f.show_if) {
                    f.show_if = self._prefixCondition(f.show_if, prefix, tplNames);
                }
                if (f.type === 'group' && f.fields) {
                    f.fields = self._prefixFields(f.fields, prefix, tplNames);
                }
                return f;
            });
        },

        _prefixCondition: function(cond, prefix, tplNames) {
            if (cond.all) {
                var self = this;
                return { all: cond.all.map(function(c) { return self._prefixCondition(c, prefix, tplNames); }) };
            }
            if (cond.any) {
                var self = this;
                return { any: cond.any.map(function(c) { return self._prefixCondition(c, prefix, tplNames); }) };
            }
            if (cond.field && tplNames[cond.field]) {
                return Object.assign({}, cond, { field: prefix + cond.field });
            }
            return cond;
        },

        // ─── Fisher-Yates shuffle (returns new array) ────────

        _shuffle: function(arr) {
            var a = arr.slice();
            for (var i = a.length - 1; i > 0; i--) {
                var j = Math.floor(Math.random() * (i + 1));
                var tmp = a[i]; a[i] = a[j]; a[j] = tmp;
            }
            return a;
        },

        // ─── Build single field ──────────────────────────────

        _buildField: function(field, langCode) {
            const type = field.type || 'text';

            // Section break (no data, visual only)
            if (type === 'section') {
                const section = document.createElement('div');
                section.className = 'bbf-field bbf-section';
                if (field.css_class) section.className += ' ' + field.css_class;
                const sTitle = field.title || field.label;
                if (sTitle) {
                    const h = document.createElement('h3');
                    h.className = 'bbf-section-title';
                    h.textContent = sTitle;
                    section.appendChild(h);
                }
                if (field.description) {
                    const d = document.createElement('p');
                    d.className = 'bbf-section-desc';
                    d.textContent = field.description;
                    section.appendChild(d);
                }
                section.setAttribute('data-field', field.name || '_section');
                return section;
            }

            // Group container (nested fields with optional show_if)
            if (type === 'group') {
                const group = document.createElement('div');
                group.className = 'bbf-field bbf-group';
                if (field.css_class) group.className += ' ' + field.css_class;
                group.setAttribute('data-field', field.name);
                const gTitle = field.title || field.label;
                if (gTitle) {
                    const h = document.createElement('h3');
                    h.className = 'bbf-group-title';
                    h.textContent = gTitle;
                    group.appendChild(h);
                }
                if (field.description) {
                    const d = document.createElement('p');
                    d.className = 'bbf-group-desc';
                    d.textContent = field.description;
                    group.appendChild(d);
                }
                var children = field.fields || [];
                if (field.shuffle) children = this._shuffle(children);
                children.forEach(child => {
                    group.appendChild(this._buildField(child, langCode));
                });
                return group;
            }

            // Page break (handled by _splitPages, not rendered)
            if (type === 'page_break') {
                const pb = document.createElement('div');
                pb.style.display = 'none';
                return pb;
            }

            // Rating field
            if (type === 'rating') {
                return this._buildRating(field, langCode);
            }

            const wrap = document.createElement('div');
            wrap.className = `bbf-field bbf-field-${type}`;
            wrap.setAttribute('data-field', field.name);

            // Size class
            if (field.size === 'small') wrap.classList.add('bbf-size-small');
            else if (field.size === 'medium') wrap.classList.add('bbf-size-medium');
            // large = default 100%

            // Custom CSS class
            if (field.css_class) wrap.className += ' ' + field.css_class;

            // Label position: "left" puts label and input side by side
            if (field.label_position === 'left') wrap.classList.add('bbf-label-left');

            // Label (for non-group fields)
            if (type !== 'radio' && type !== 'checkbox') {
                if (field.label) {
                    const label = document.createElement('label');
                    label.className = 'bbf-label';
                    label.setAttribute('for', `bbf-${field.name}`);
                    label.textContent = field.label;
                    if (field.required) {
                        const req = document.createElement('span');
                        req.className = 'bbf-required';
                        req.textContent = ' *';
                        label.appendChild(req);
                    }
                    wrap.appendChild(label);
                }

                // Description
                if (field.description) {
                    const desc = document.createElement('small');
                    desc.className = 'bbf-field-desc';
                    desc.id = `bbf-${field.name}-desc`;
                    desc.textContent = field.description;
                    wrap.appendChild(desc);
                }
            }

            // Input
            let input;

            switch (type) {
                case 'textarea':
                    input = document.createElement('textarea');
                    input.rows = field.rows || 4;
                    break;

                case 'select':
                    input = document.createElement('select');
                    if (field.placeholder) {
                        const opt = document.createElement('option');
                        opt.value = '';
                        opt.textContent = field.placeholder;
                        opt.disabled = true;
                        opt.selected = true;
                        input.appendChild(opt);
                    }
                    var selOpts = field.options || [];
                    if (field.shuffle) selOpts = this._shuffle(selOpts);
                    selOpts.forEach(o => {
                        const opt = document.createElement('option');
                        opt.value = typeof o === 'object' ? o.value : o;
                        opt.textContent = typeof o === 'object' ? o.label : o;
                        input.appendChild(opt);
                    });
                    // "Other" option for select
                    if (field.other) {
                        const otherOpt = document.createElement('option');
                        otherOpt.value = '__other__';
                        otherOpt.textContent = field.other_label || 'Other…';
                        input.appendChild(otherOpt);
                    }
                    break;

                case 'radio':
                case 'checkbox':
                    return this._buildGroup(field, type, langCode);

                case 'hidden':
                    input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = field.name;
                    input.value = field.value || '';
                    wrap.appendChild(input);
                    wrap.style.display = 'none';
                    return wrap;

                case 'password':
                    input = document.createElement('input');
                    input.type = 'password';
                    break;

                default:
                    input = document.createElement('input');
                    input.type = type; // text, email, url, tel, number, date, etc.
                    break;
            }

            input.name = field.name;
            input.id = `bbf-${field.name}`;
            input.className = 'bbf-input';

            if (field.placeholder) input.placeholder = field.placeholder;
            if (field.required) input.required = true;
            if (field.readonly) { input.readOnly = true; input.classList.add('bbf-readonly'); }
            if (field.minlength) input.minLength = field.minlength;
            if (field.maxlength) input.maxLength = field.maxlength;
            if (field.min !== undefined) input.min = field.min;
            if (field.max !== undefined) input.max = field.max;
            if (field.pattern) input.pattern = field.pattern;
            if (field.autocomplete) input.autocomplete = field.autocomplete;
            if (field.value !== undefined) input.value = field.value;

            // Accessibility: link input to description and error
            const ariaDesc = [];
            if (field.description) ariaDesc.push(`bbf-${field.name}-desc`);
            ariaDesc.push(`bbf-${field.name}-error`);
            input.setAttribute('aria-describedby', ariaDesc.join(' '));

            // Prefix / suffix (e.g. "€", "kg", "ks")
            if (field.prefix || field.suffix) {
                const inputGroup = document.createElement('div');
                inputGroup.className = 'bbf-input-group';
                if (field.prefix) {
                    const pre = document.createElement('span');
                    pre.className = 'bbf-input-prefix';
                    pre.textContent = field.prefix;
                    inputGroup.appendChild(pre);
                }
                inputGroup.appendChild(input);
                if (field.suffix) {
                    const suf = document.createElement('span');
                    suf.className = 'bbf-input-suffix';
                    suf.textContent = field.suffix;
                    inputGroup.appendChild(suf);
                }
                wrap.appendChild(inputGroup);
            } else {
                wrap.appendChild(input);
            }

            // "Other" text field for select
            if (type === 'select' && field.other) {
                const otherInput = document.createElement('input');
                otherInput.type = 'text';
                otherInput.name = field.name + '_other';
                otherInput.className = 'bbf-input bbf-other-input';
                otherInput.placeholder = field.other_label || 'Other…';
                otherInput.style.display = 'none';
                otherInput.style.marginTop = '6px';
                wrap.appendChild(otherInput);

                input.addEventListener('change', () => {
                    otherInput.style.display = input.value === '__other__' ? '' : 'none';
                    if (input.value !== '__other__') otherInput.value = '';
                });
            }

            // Email confirmation field
            if (type === 'email' && field.confirm) {
                const confirmInput = document.createElement('input');
                confirmInput.type = 'email';
                confirmInput.name = field.name + '_confirm';
                confirmInput.id = `bbf-${field.name}-confirm`;
                confirmInput.className = 'bbf-input';
                confirmInput.placeholder = this._t('emailMismatch', { label: field.label || field.name }, langCode).includes('match')
                    ? 'Confirm ' + (field.label || 'email')
                    : (field.label || 'email') + ' (confirm)';
                confirmInput.style.marginTop = '6px';
                if (field.required) confirmInput.required = true;
                wrap.appendChild(confirmInput);
            }

            // Error placeholder
            const errEl = document.createElement('div');
            errEl.className = 'bbf-field-error';
            errEl.id = `bbf-${field.name}-error`;
            errEl.setAttribute('role', 'alert');
            wrap.appendChild(errEl);

            return wrap;
        },

        // ─── Build radio/checkbox group ──────────────────────

        _buildGroup: function(field, type, langCode) {
            const fieldset = document.createElement('fieldset');
            fieldset.className = `bbf-fieldset bbf-field bbf-field-${type}`;
            fieldset.setAttribute('data-field', field.name);

            // Size and custom class
            if (field.size === 'small') fieldset.classList.add('bbf-size-small');
            else if (field.size === 'medium') fieldset.classList.add('bbf-size-medium');
            if (field.css_class) fieldset.className += ' ' + field.css_class;

            if (field.label) {
                const legend = document.createElement('legend');
                legend.className = 'bbf-label';
                legend.textContent = field.label;
                if (field.required) {
                    const req = document.createElement('span');
                    req.className = 'bbf-required';
                    req.textContent = ' *';
                    legend.appendChild(req);
                }
                fieldset.appendChild(legend);
            }

            const groupAriaDesc = [];
            if (field.description) {
                const desc = document.createElement('small');
                desc.className = 'bbf-field-desc';
                desc.id = `bbf-${field.name}-desc`;
                desc.textContent = field.description;
                fieldset.appendChild(desc);
                groupAriaDesc.push(desc.id);
            }
            groupAriaDesc.push(`bbf-${field.name}-error`);

            const optionsWrap = document.createElement('div');
            optionsWrap.className = 'bbf-options';
            optionsWrap.setAttribute('role', 'group');
            optionsWrap.setAttribute('aria-describedby', groupAriaDesc.join(' '));

            // Column layout
            if (field.columns === 2) optionsWrap.classList.add('bbf-columns-2');
            else if (field.columns === 3) optionsWrap.classList.add('bbf-columns-3');
            else if (field.columns === 'inline' || field.columns === 4) optionsWrap.classList.add('bbf-columns-inline');

            var grpOpts = field.options || [];
            if (field.shuffle) grpOpts = this._shuffle(grpOpts);
            grpOpts.forEach((o, i) => {
                const optWrap = document.createElement('label');
                optWrap.className = 'bbf-option';
                const inp = document.createElement('input');
                inp.type = type;
                inp.name = field.name;
                inp.id = `bbf-${field.name}-${i}`;
                inp.value = typeof o === 'object' ? o.value : o;
                // Default checked: field.value (string or array) or option-level checked
                if (typeof o === 'object' && o.checked) {
                    inp.checked = true;
                } else if (field.value !== undefined) {
                    var fv = field.value;
                    // Normalize: checkbox accepts both string and array
                    if (type === 'checkbox' && !Array.isArray(fv)) fv = [String(fv)];
                    if (type === 'radio' && String(fv) === inp.value) inp.checked = true;
                    else if (type === 'checkbox' && Array.isArray(fv) && fv.map(String).includes(inp.value)) inp.checked = true;
                }
                const span = document.createElement('span');
                span.textContent = typeof o === 'object' ? o.label : o;
                optWrap.appendChild(inp);
                optWrap.appendChild(span);
                // Conditional option visibility
                if (typeof o === 'object' && o.show_if) {
                    optWrap.setAttribute('data-option-show-if', JSON.stringify(o.show_if));
                }
                optionsWrap.appendChild(optWrap);
            });

            // "Other" option
            if (field.other) {
                const otherWrap = document.createElement('label');
                otherWrap.className = 'bbf-option bbf-option-other';
                const otherInp = document.createElement('input');
                otherInp.type = type;
                otherInp.name = field.name;
                otherInp.id = `bbf-${field.name}-other`;
                otherInp.value = '__other__';
                const otherSpan = document.createElement('span');
                otherSpan.textContent = field.other_label || 'Other…';
                otherWrap.appendChild(otherInp);
                otherWrap.appendChild(otherSpan);

                const otherText = document.createElement('input');
                otherText.type = 'text';
                otherText.name = field.name + '_other';
                otherText.className = 'bbf-input bbf-other-input';
                otherText.style.display = 'none';
                otherText.style.marginTop = '4px';

                otherInp.addEventListener('change', () => {
                    otherText.style.display = otherInp.checked ? '' : 'none';
                });
                // Hide "other" text when another option is selected (radio only)
                if (type === 'radio') {
                    optionsWrap.addEventListener('change', () => {
                        otherText.style.display = otherInp.checked ? '' : 'none';
                        if (!otherInp.checked) otherText.value = '';
                    });
                }

                optionsWrap.appendChild(otherWrap);
                optionsWrap.appendChild(otherText);
            }

            fieldset.appendChild(optionsWrap);

            const err = document.createElement('div');
            err.className = 'bbf-field-error';
            err.id = `bbf-${field.name}-error`;
            err.setAttribute('role', 'alert');
            fieldset.appendChild(err);

            return fieldset;
        },

        // ─── Build rating field ──────────────────────────────

        _buildRating: function(field, langCode) {
            const wrap = document.createElement('div');
            wrap.className = 'bbf-field bbf-field-rating';
            wrap.setAttribute('data-field', field.name);
            if (field.css_class) wrap.className += ' ' + field.css_class;

            if (field.label) {
                const label = document.createElement('label');
                label.className = 'bbf-label';
                label.textContent = field.label;
                if (field.required) {
                    const req = document.createElement('span');
                    req.className = 'bbf-required';
                    req.textContent = ' *';
                    label.appendChild(req);
                }
                wrap.appendChild(label);
            }

            if (field.description) {
                const desc = document.createElement('small');
                desc.className = 'bbf-field-desc';
                desc.id = `bbf-${field.name}-desc`;
                desc.textContent = field.description;
                wrap.appendChild(desc);
            }

            const maxRating = field.max || 5;
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = field.name;
            hidden.value = '';
            wrap.appendChild(hidden);

            const starsWrap = document.createElement('div');
            starsWrap.className = 'bbf-rating-stars';
            starsWrap.setAttribute('role', 'radiogroup');
            starsWrap.setAttribute('aria-label', field.label || 'Rating');

            for (let i = 1; i <= maxRating; i++) {
                const star = document.createElement('span');
                star.className = 'bbf-star';
                star.setAttribute('data-value', i);
                star.setAttribute('role', 'radio');
                star.setAttribute('tabindex', '0');
                star.setAttribute('aria-checked', 'false');
                star.setAttribute('aria-label', `${i} / ${maxRating}`);
                star.textContent = '\u2605'; // ★
                starsWrap.appendChild(star);
            }

            starsWrap.addEventListener('click', (e) => {
                const star = e.target.closest('.bbf-star');
                if (!star) return;
                const val = star.getAttribute('data-value');
                hidden.value = val;
                starsWrap.querySelectorAll('.bbf-star').forEach(s => {
                    const sv = parseInt(s.getAttribute('data-value'));
                    s.classList.toggle('bbf-star-active', sv <= parseInt(val));
                    s.setAttribute('aria-checked', sv === parseInt(val) ? 'true' : 'false');
                });
            });

            starsWrap.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    e.target.click();
                }
            });

            // Hover effect
            starsWrap.addEventListener('mouseover', (e) => {
                const star = e.target.closest('.bbf-star');
                if (!star) return;
                const hv = parseInt(star.getAttribute('data-value'));
                starsWrap.querySelectorAll('.bbf-star').forEach(s => {
                    s.classList.toggle('bbf-star-hover', parseInt(s.getAttribute('data-value')) <= hv);
                });
            });
            starsWrap.addEventListener('mouseleave', () => {
                starsWrap.querySelectorAll('.bbf-star').forEach(s => s.classList.remove('bbf-star-hover'));
            });

            wrap.appendChild(starsWrap);

            const errEl = document.createElement('div');
            errEl.className = 'bbf-field-error';
            errEl.id = `bbf-${field.name}-error`;
            errEl.setAttribute('role', 'alert');
            wrap.appendChild(errEl);

            return wrap;
        },

        // ─── Validation ──────────────────────────────────────

        _validate: function(fields, formEl, langCode) {
            const errors = {};
            const t = (key, params) => this._t(key, params, langCode);

            fields.forEach(field => {
                const name = field.name;
                const type = field.type || 'text';

                // Skip non-data types
                if (type === 'section' || type === 'page_break' || type === 'group') return;

                // Skip conditionally hidden fields (including those inside hidden groups)
                const wrap = formEl.querySelector(`[data-field="${name}"]`);
                if (wrap && this._isHidden(wrap)) return;

                let value;

                if (type === 'checkbox') {
                    const checked = formEl.querySelectorAll(`input[name="${name}"]:checked`);
                    value = checked.length > 0 ? Array.from(checked).map(c => c.value) : '';
                } else if (type === 'radio') {
                    const checked = formEl.querySelector(`input[name="${name}"]:checked`);
                    value = checked ? checked.value : '';
                } else if (type === 'rating') {
                    const hidden = formEl.querySelector(`input[name="${name}"]`);
                    value = hidden ? hidden.value : '';
                } else {
                    const input = formEl.querySelector(`[name="${name}"]`);
                    value = input ? input.value.trim() : '';
                }

                const label = field.label || name;

                // Required
                if (field.required && (!value || (Array.isArray(value) && value.length === 0))) {
                    errors[name] = t('required', { label });
                    return;
                }

                if (!value || value === '') return;

                // Type-specific validation
                if (type === 'email') {
                    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
                        errors[name] = t('invalidEmail', { label });
                        return;
                    }
                    // Confirm email
                    if (field.confirm) {
                        const confirmInput = formEl.querySelector(`[name="${name}_confirm"]`);
                        if (confirmInput && confirmInput.value.trim() !== value) {
                            errors[name] = t('emailMismatch', { label });
                            return;
                        }
                    }
                }
                if (type === 'url' && !/^https?:\/\/.+/.test(value)) {
                    errors[name] = t('invalidUrl', { label });
                    return;
                }
                if (type === 'tel' && !/^[+\d][\d\s\-().]{5,}$/.test(value)) {
                    errors[name] = t('invalidTel', { label });
                    return;
                }
                if (type === 'number' || type === 'rating') {
                    const num = Number(value);
                    if (isNaN(num)) {
                        errors[name] = t('invalidNumber', { label });
                        return;
                    }
                    if (field.min !== undefined && num < field.min) {
                        errors[name] = t('numberMin', { label, min: field.min });
                        return;
                    }
                    if (field.max !== undefined && num > field.max) {
                        errors[name] = t('numberMax', { label, max: field.max });
                        return;
                    }
                }
                if (type === 'date') {
                    if (field.min && value < field.min) {
                        errors[name] = t('dateMin', { label, min: field.min });
                        return;
                    }
                    if (field.max && value > field.max) {
                        errors[name] = t('dateMax', { label, max: field.max });
                        return;
                    }
                }

                // Options validation (select, radio, checkbox) — skip __other__
                if (field.options && field.options.length > 0) {
                    const validValues = field.options.map(o => typeof o === 'object' ? o.value : o);
                    if (field.other) validValues.push('__other__');
                    const vals = Array.isArray(value) ? value : [value];
                    const invalid = vals.some(v => !validValues.includes(v));
                    if (invalid) {
                        errors[name] = t('invalidOption', { label });
                        return;
                    }
                }

                // Length validation
                if (field.minlength && typeof value === 'string' && value.length < field.minlength) {
                    errors[name] = t('tooShort', { label, min: field.minlength });
                    return;
                }
                if (field.maxlength && typeof value === 'string' && value.length > field.maxlength) {
                    errors[name] = t('tooLong', { label, max: field.maxlength });
                    return;
                }

                // Pattern validation
                if (field.pattern) {
                    try {
                        if (!new RegExp(field.pattern).test(value)) {
                            errors[name] = field.pattern_message || t('invalidFormat', { label });
                        }
                    } catch (e) {
                        // Invalid regex — skip client-side, server will catch it
                    }
                }
            });
            return errors;
        },

        // Cross-field validations (form.validations array)
        _validateCrossField: function(validations, formEl, langCode) {
            var errors = {};
            if (!validations || !validations.length) return errors;
            var self = this;
            var fallback = this._t('crossFieldDefault', {}, langCode);
            validations.forEach(function(rule) {
                var fields = rule.fields || [];
                var key = '_validation_' + fields.join('_');
                if (rule.type === 'min_sum') {
                    var sum = 0;
                    fields.forEach(function(name) {
                        var val = parseFloat(self._getFieldValue(formEl, name)) || 0;
                        sum += val;
                    });
                    if (sum < (rule.min || 1)) {
                        errors[key] = rule.message || fallback;
                    }
                } else if (rule.type === 'min_filled') {
                    var filled = 0;
                    fields.forEach(function(name) {
                        var val = self._getFieldValue(formEl, name);
                        if (val !== '' && val !== null && val !== undefined && !(Array.isArray(val) && val.length === 0)) filled++;
                    });
                    if (filled < (rule.min || 1)) {
                        errors[key] = rule.message || fallback;
                    }
                }
            });
            return errors;
        },

        _showErrors: function(formEl, errors) {
            this._clearErrors(formEl);
            let firstInput = null;
            Object.entries(errors).forEach(([name, msg]) => {
                const wrap = formEl.querySelector(`[data-field="${name}"]`);
                if (wrap) {
                    wrap.classList.add('bbf-has-error');
                    const errEl = wrap.querySelector('.bbf-field-error');
                    if (errEl) errEl.textContent = msg;
                    const inp = wrap.querySelector('input, select, textarea');
                    if (inp) {
                        inp.setAttribute('aria-invalid', 'true');
                        if (!firstInput) firstInput = inp;
                    }
                } else if (name.startsWith('_validation_')) {
                    // Cross-field error — show in form message area
                    const msgEl = formEl.querySelector('.bbf-message');
                    if (msgEl) {
                        msgEl.className = 'bbf-message bbf-error';
                        msgEl.textContent = msg;
                        msgEl.style.display = '';
                    }
                }
            });
            if (firstInput) {
                firstInput.focus();
                firstInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
            } else {
                const msgEl = formEl.querySelector('.bbf-message');
                if (msgEl && msgEl.style.display !== 'none') {
                    msgEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        },

        _renderSandboxPreview: function(result) {
            var h = '<div style="text-align:left">';
            h += '<strong style="font-size:1.05em">Sandbox Preview</strong>';
            h += '<div style="font-size:0.85em;opacity:0.8;margin:2px 0 10px">Nothing was saved or sent. This is what <em>would</em> happen:</div>';

            var p = result.on_submit_preview || {};

            // Validation
            var v = result.validation || {};
            h += '<div style="margin-bottom:8px">';
            h += v.passed
                ? '<span style="color:#4ade80">&#10003; Validation passed</span>'
                : '<span style="color:#f87171">&#10007; Validation failed</span>';
            h += '</div>';

            // Store
            if (p.store) {
                h += '<div style="margin-bottom:6px"><strong>Storage:</strong> ' + p.store.backend + (p.store.enabled ? '' : ' (disabled)') + '</div>';
            }

            // Payment
            if (p.payment) {
                h += '<div style="margin-bottom:6px"><strong>Payment:</strong> '
                    + p.payment.provider + ' &mdash; '
                    + p.payment.amount.toFixed(2) + ' ' + p.payment.currency
                    + ' &mdash; &ldquo;' + this._esc(p.payment.product_name) + '&rdquo;</div>';
            }

            // Emails
            if (p.confirm_email) {
                h += '<details style="margin-bottom:6px"><summary style="cursor:pointer"><strong>Confirmation email</strong> &rarr; ' + this._esc(p.confirm_email.to) + ' <span style="opacity:0.5;font-size:0.85em">(click to expand)</span></summary>';
                h += '<div style="margin:6px 0;padding:8px;background:rgba(0,0,0,0.15);border-radius:4px;font-size:0.82em">';
                h += '<div style="margin-bottom:4px"><strong>Subject:</strong> ' + this._esc(p.confirm_email.subject) + '</div>';
                if (p.confirm_email.body_preview) h += '<div style="white-space:pre-wrap;max-height:200px;overflow:auto">' + this._esc(p.confirm_email.body_preview) + '</div>';
                h += '</div></details>';
            }
            if (p.notify) {
                h += '<details style="margin-bottom:6px"><summary style="cursor:pointer"><strong>Notification email</strong> &rarr; ' + this._esc(p.notify.to) + ' <span style="opacity:0.5;font-size:0.85em">(click to expand)</span></summary>';
                h += '<div style="margin:6px 0;padding:8px;background:rgba(0,0,0,0.15);border-radius:4px;font-size:0.82em">';
                h += '<div style="margin-bottom:4px"><strong>Subject:</strong> ' + this._esc(p.notify.subject) + '</div>';
                if (p.notify.body_preview) h += '<div style="white-space:pre-wrap;max-height:200px;overflow:auto">' + this._esc(p.notify.body_preview) + '</div>';
                h += '</div></details>';
            }

            // Webhooks
            if (p.webhooks && p.webhooks.length) {
                h += '<div style="margin-bottom:6px"><strong>Webhooks:</strong> ' + p.webhooks.length + ' configured</div>';
            }

            // Meta
            if (result.meta) {
                h += '<div style="margin-bottom:6px"><strong>Meta:</strong> payment_status = ' + (result.meta.payment_status || 'n/a') + '</div>';
            }

            h += '</div>';
            return h;
        },

        _esc: function(s) {
            if (!s) return '';
            var d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        },

        _clearErrors: function(formEl) {
            formEl.querySelectorAll('.bbf-has-error').forEach(el => el.classList.remove('bbf-has-error'));
            formEl.querySelectorAll('[aria-invalid]').forEach(el => el.removeAttribute('aria-invalid'));
            formEl.querySelectorAll('.bbf-field-error').forEach(el => el.textContent = '');
            const msgEl = formEl.querySelector('.bbf-message');
            if (msgEl && !msgEl.classList.contains('bbf-success')) { msgEl.textContent = ''; msgEl.style.display = 'none'; msgEl.className = 'bbf-message'; }
        }
    };

    // Auto-init: find all elements with data-form attribute
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-form]').forEach(el => {
            const formId = el.getAttribute('data-form');
            const opts = {
                showTitle: el.getAttribute('data-show-title') !== 'false',
                hideOnSuccess: el.getAttribute('data-hide-on-success') === 'true',
            };
            BBF.render(formId, el, opts);
        });
    });

    // Expose globally
    window.BBF = BBF;

})();
