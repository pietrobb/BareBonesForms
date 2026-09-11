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
            draftCode:        'Draft resume code',
            draftSave:        'Save progress',
            draftResume:      'Resume saved progress',
            draftDelete:      'Delete saved progress',
            draftSaving:      'Saving draft…',
            draftSaved:       'Draft saved until {expires}. Keep the resume code private.',
            draftLoading:     'Loading draft…',
            draftLoaded:      'Saved progress restored.',
            draftDeleting:    'Deleting draft…',
            draftDeleted:     'Saved progress deleted.',
            draftFailed:      'Draft request failed.',
            repeatableAdd:    'Add item',
            repeatableRemove: 'Remove item',
            repeatableItem:   '{label} item {index}',
            repeatableCount:  '{label}: {count} items.',
            repeatableMin:    '{label} requires at least {min} items.',
            repeatableMax:    '{label} allows at most {max} items.',
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
            const renderRequest = (container._bbfRenderRequest || 0) + 1;
            container._bbfRenderRequest = renderRequest;
            const isCurrent = () => container._bbfRenderRequest === renderRequest;

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
                if (!isCurrent()) return;

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
                    if (!isCurrent()) return;
                }

                // Fetch dynamic options (options_from) before building the form
                const allFields = this._flattenFields(form.fields || [], true);
                const optionsFetches = [];
                allFields.forEach(field => {
                    if (field.options_from) {
                        optionsFetches.push(
                            fetch(field.options_from)
                                .then(r => { if (!r.ok) throw new Error(r.status); return r.json(); })
                                .then(opts => { field.options = opts; })
                                .catch(err => { console.warn('BBF: Failed to load options for ' + field.name + ':', err); field.options = field.options || []; })
                        );
                    }
                });
                if (optionsFetches.length > 0) {
                    await Promise.all(optionsFetches);
                }
                if (!isCurrent()) return;

                container.innerHTML = '';
                container.classList.remove('bbf-loading');
                container.classList.add('bbf-form-container');

                const formEl = this._buildForm(form, formId, baseUrl, options, csrfToken, langCode, isSameOrigin);
                container.appendChild(formEl);
            } catch (err) {
                if (!isCurrent()) return;
                container.innerHTML = '';
                const error = document.createElement('div');
                error.className = 'bbf-error';
                error.textContent = this._t('loadError', { message: err.message }, langCode);
                container.appendChild(error);
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
        _flattenFields: function(fields, includeRepeatableChildren) {
            const result = [];
            (fields || []).forEach(f => {
                result.push(f);
                if (f.type === 'group' && f.fields && (!f.repeatable || includeRepeatableChildren)) {
                    this._flattenFields(f.fields, includeRepeatableChildren).forEach(child => result.push(child));
                }
            });
            return result;
        },

        _repeatableGroups: function(fields) {
            return this._flattenFields(fields || []).filter(field => field.type === 'group' && field.repeatable);
        },

        _scopeRepeatableFields: function(fields, prefix) {
            const names = {};
            this._flattenFields(fields || [], true).forEach(field => { if (field.name) names[field.name] = true; });
            return this._prefixFields(fields || [], prefix, names);
        },

        _repeatableRows: function(group) {
            return (group._bbfRows || []).filter(row => row.parentElement === group._bbfRowsContainer);
        },

        _validateRepeatableGroups: function(fields, formEl, langCode) {
            const errors = {};
            this._repeatableGroups(fields).forEach(field => {
                const group = formEl.querySelector(`[data-field="${field.name}"]`);
                if (!group || this._isHidden(group)) return;
                const rows = this._repeatableRows(group);
                const min = field.min_items === undefined ? 1 : field.min_items;
                const max = field.max_items === undefined ? 10 : field.max_items;
                const label = field.label || field.title || field.name;
                if (rows.length < min) {
                    errors[field.name] = this._t('repeatableMin', { label, min }, langCode);
                    return;
                }
                if (rows.length > max) {
                    errors[field.name] = this._t('repeatableMax', { label, max }, langCode);
                    return;
                }
                rows.forEach(row => {
                    const rowFields = this._flattenFields(row._bbfFields || []).filter(child =>
                        !['section', 'page_break', 'group'].includes(child.type));
                    Object.assign(errors, this._validate(rowFields, row, langCode));
                });
            });
            return errors;
        },

        _collectRepeatableRows: function(field, group) {
            const childFields = this._flattenFields(field.fields || []).filter(child =>
                !['section', 'page_break', 'group'].includes(child.type));
            return this._repeatableRows(group).map(row => {
                const result = {};
                childFields.forEach(child => {
                    const scopedName = row._bbfPrefix + child.name;
                    const wrap = row.querySelector(`[data-field="${scopedName}"]`);
                    if (wrap && this._isHidden(wrap)) return;
                    const inputs = row.querySelectorAll(`[name="${scopedName}"]`);
                    if (child.type === 'checkbox') {
                        result[child.name] = Array.from(inputs).filter(input => input.checked).map(input => input.value);
                    } else if (child.type === 'radio') {
                        const checked = Array.from(inputs).find(input => input.checked);
                        result[child.name] = checked ? checked.value : '';
                    } else {
                        result[child.name] = inputs[0] ? inputs[0].value : '';
                    }
                    if (child.other) {
                        const other = row.querySelector(`[name="${scopedName}_other"]`);
                        result[child.name + '_other'] = other ? other.value : '';
                    }
                    if (child.type === 'email' && child.confirm) {
                        const confirm = row.querySelector(`[name="${scopedName}_confirm"]`);
                        result[child.name + '_confirm'] = confirm ? confirm.value : '';
                    }
                });
                return result;
            });
        },

        _collectRepeatableGroups: function(fields, formEl, body) {
            this._repeatableGroups(fields).forEach(field => {
                const group = formEl.querySelector(`[data-field="${field.name}"]`);
                if (!group) return;
                this._repeatableRows(group).forEach(row => {
                    row.querySelectorAll('[name]').forEach(input => { delete body[input.name]; });
                });
                body[field.name] = this._collectRepeatableRows(field, group);
            });
        },

        _resetRepeatableGroups: function(formEl) {
            formEl.querySelectorAll('.bbf-repeatable-group').forEach(group => {
                if (group._bbfResetRows) group._bbfResetRows();
            });
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
        _applyOptionConditions: function(fieldRoot, valueRoot) {
            var self = this;
            var valueChanged = false;
            valueRoot = valueRoot || fieldRoot;
            fieldRoot.querySelectorAll('[data-option-show-if]').forEach(function(optEl) {
                var cond = JSON.parse(optEl.getAttribute('data-option-show-if'));
                var visible = self._evalCondition(cond, valueRoot);
                optEl.style.display = visible ? '' : 'none';
                if (optEl.tagName === 'OPTION') {
                    optEl.hidden = !visible;
                    optEl.disabled = !visible;
                    if (!visible && optEl.selected) {
                        optEl.selected = false;
                        if (optEl.parentElement) optEl.parentElement.selectedIndex = -1;
                        valueChanged = true;
                    }
                } else if (!visible) {
                    // If hiding a checked radio/checkbox option, uncheck it
                    var inp = optEl.querySelector('input');
                    if (inp && inp.checked) { inp.checked = false; valueChanged = true; }
                }
            });
            return valueChanged;
        },

        _stabilizeOptionConditions: function(fieldRoot, valueRoot) {
            var remainingPasses = fieldRoot.querySelectorAll('[data-option-show-if]').length + 1;
            while (remainingPasses-- > 0 && this._applyOptionConditions(fieldRoot, valueRoot)) {}
        },

        _resetCustomFields: function(formEl) {
            formEl.querySelectorAll('[data-bbf-rating-reset]').forEach(function(input) {
                input._bbfResetRating();
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

        _applyConditions: function(fieldRoot, fields, animate, valueRoot) {
            valueRoot = valueRoot || fieldRoot;
            fields.forEach(field => {
                if (!field.show_if) return;
                const wrap = fieldRoot.querySelector(`[data-field="${field.name}"]`);
                if (!wrap) return;

                const generation = (wrap._bbfConditionGeneration || 0) + 1;
                wrap._bbfConditionGeneration = generation;
                const isCurrent = () => wrap._bbfConditionGeneration === generation;
                const visible = this._evalCondition(field.show_if, valueRoot);
                const wasHidden = wrap.getAttribute('data-conditional-hidden') === 'true';

                if (!animate || (visible && !wasHidden) || (!visible && wasHidden)) {
                    // No animation: instant show/hide (initial state or no change)
                    wrap.style.display = visible ? '' : 'none';
                    wrap.style.maxHeight = '';
                    wrap.style.overflow = '';
                    wrap.style.transition = '';
                    wrap.style.opacity = '';
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
                        if (!isCurrent()) return;
                        wrap.style.transition = 'max-height 0.3s ease, opacity 0.25s ease';
                        wrap.style.maxHeight = wrap.scrollHeight + 'px';
                        wrap.style.opacity = '1';
                        const done = () => {
                            wrap.removeEventListener('transitionend', done);
                            if (!isCurrent()) return;
                            wrap.style.maxHeight = '';
                            wrap.style.overflow = '';
                            wrap.style.transition = '';
                            wrap.style.opacity = '';
                        };
                        wrap.addEventListener('transitionend', done, { once: true });
                        setTimeout(done, 350); // fallback
                    });
                } else {
                    // Animate out
                    wrap.style.maxHeight = wrap.scrollHeight + 'px';
                    wrap.style.overflow = 'hidden';
                    requestAnimationFrame(() => {
                        if (!isCurrent()) return;
                        wrap.style.transition = 'max-height 0.3s ease, opacity 0.2s ease';
                        wrap.style.maxHeight = '0';
                        wrap.style.opacity = '0';
                        const done = () => {
                            wrap.removeEventListener('transitionend', done);
                            if (!isCurrent()) return;
                            wrap.style.display = 'none';
                            wrap.style.transition = '';
                            wrap.style.maxHeight = '';
                            wrap.style.overflow = '';
                            wrap.style.opacity = '';
                        };
                        wrap.addEventListener('transitionend', done, { once: true });
                        setTimeout(done, 350); // fallback
                    });
                    wrap.setAttribute('data-conditional-hidden', 'true');
                }
            });
        },

        _bindConditions: function(valueRoot, fields, animate, fieldRoot) {
            const self = this;
            fieldRoot = fieldRoot || valueRoot;
            const sources = new Set();
            fields.forEach(f => {
                if (f.show_if) this._collectSources(f.show_if).forEach(s => sources.add(s));
                // Collect sources from option-level show_if
                if (f.options) f.options.forEach(o => {
                    if (typeof o === 'object' && o.show_if) this._collectSources(o.show_if).forEach(s => sources.add(s));
                });
            });
            var handler = function() {
                self._stabilizeOptionConditions(fieldRoot, valueRoot);
                self._applyConditions(fieldRoot, fields, animate, valueRoot);
            };
            const boundInputs = [];
            sources.forEach(srcName => {
                const inputs = valueRoot.querySelectorAll(`[name="${srcName}"]`);
                inputs.forEach(inp => {
                    inp.addEventListener('change', handler);
                    inp.addEventListener('input', handler);
                    boundInputs.push(inp);
                });
            });
            // Initial state (always instant, no animation)
            this._stabilizeOptionConditions(fieldRoot, valueRoot);
            this._applyConditions(fieldRoot, fields, false, valueRoot);
            return function() {
                boundInputs.forEach(inp => {
                    inp.removeEventListener('change', handler);
                    inp.removeEventListener('input', handler);
                });
            };
        },

        // ─── Respondent drafts ───────────────────────────────

        _draftStorageKey: function(baseUrl, formId) {
            return 'bbf:draft:' + new URL(baseUrl, location.href).href + ':' + formId;
        },

        _draftRequest: async function(baseUrl, formId, action, body, isSameOrigin) {
            const options = {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            };
            if (isSameOrigin) options.credentials = 'same-origin';
            const response = await fetch(`${baseUrl}submit.php?form=${encodeURIComponent(formId)}&action=${action}`, options);
            const result = await response.json().catch(() => ({ status: 'error', message: this._t('errorDefault') }));
            if (!response.ok) {
                const error = new Error(result.message || this._t('errorDefault'));
                error.status = response.status;
                error.result = result;
                throw error;
            }
            return result;
        },

        _draftCollect: function(formEl, fields, allowlist) {
            const allowed = new Set(allowlist || []);
            const body = {};
            fields.forEach(field => {
                if (!allowed.has(field.name) || field.sensitive || ['password', 'hidden'].includes(field.type)) return;
                const inputs = formEl.querySelectorAll(`[name="${field.name}"]`);
                if (!inputs.length) return;
                if (field.type === 'checkbox') {
                    body[field.name] = Array.from(inputs).filter(input => input.checked).map(input => input.value);
                } else if (field.type === 'radio') {
                    const checked = Array.from(inputs).find(input => input.checked);
                    if (checked) body[field.name] = checked.value;
                } else {
                    body[field.name] = inputs[0].value;
                }
            });
            return body;
        },

        _draftApply: function(formEl, fields, data, allowlist) {
            const fieldMap = new Map(fields.map(field => [field.name, field]));
            const changed = [];
            const names = Array.isArray(allowlist) ? allowlist : Object.keys(data || {});
            names.forEach(name => {
                const field = fieldMap.get(name);
                if (!field || field.sensitive || ['password', 'hidden'].includes(field.type)) return;
                const inputs = formEl.querySelectorAll(`[name="${name}"]`);
                if (field.type === 'checkbox') {
                    const values = Array.isArray(data[name]) ? data[name].map(String) : [];
                    inputs.forEach(input => { input.checked = values.includes(String(input.value)); });
                } else if (field.type === 'radio') {
                    const hasValue = Object.prototype.hasOwnProperty.call(data || {}, name) && data[name] !== null;
                    inputs.forEach(input => { input.checked = hasValue && String(input.value) === String(data[name]); });
                } else if (inputs[0]) {
                    if (field.type === 'rating' && inputs[0]._bbfSetRating) inputs[0]._bbfSetRating(data[name]);
                    else inputs[0].value = data[name] === null || data[name] === undefined ? '' : String(data[name]);
                }
                inputs.forEach(input => changed.push(input));
            });
            changed.forEach(input => {
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            });
            this._stabilizeOptionConditions(formEl);
            this._applyConditions(formEl, fields, false);
        },

        _buildDraftControls: function(formEl, form, formId, baseUrl, csrfToken, langCode, isSameOrigin, fields) {
            const policy = form.drafts;
            if (!policy || policy.enabled !== true || !Array.isArray(policy.fields) || policy.fields.length === 0) return null;
            const wrap = document.createElement('div');
            wrap.className = 'bbf-field bbf-draft-controls';
            const label = document.createElement('label');
            const codeId = `bbf-${formId}-draft-code`;
            label.className = 'bbf-label'; label.htmlFor = codeId;
            label.textContent = this._t('draftCode', {}, langCode);
            const code = document.createElement('input');
            code.type = 'text'; code.id = codeId; code.className = 'bbf-input bbf-draft-code';
            code.autocomplete = 'off'; code.spellcheck = false;
            const status = document.createElement('div');
            status.className = 'bbf-draft-status'; status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
            const actions = document.createElement('div'); actions.className = 'bbf-draft-actions';
            const save = document.createElement('button'); save.type = 'button'; save.className = 'bbf-draft-save'; save.textContent = this._t('draftSave', {}, langCode);
            const resume = document.createElement('button'); resume.type = 'button'; resume.className = 'bbf-draft-resume'; resume.textContent = this._t('draftResume', {}, langCode);
            const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'bbf-draft-delete'; remove.textContent = this._t('draftDelete', {}, langCode);
            actions.appendChild(save); actions.appendChild(resume); actions.appendChild(remove);
            wrap.appendChild(label); wrap.appendChild(code); wrap.appendChild(actions); wrap.appendChild(status);
            let request = 0;
            const storageKey = this._draftStorageKey(baseUrl, formId);
            try { code.value = localStorage.getItem(storageKey) || ''; } catch (error) { /* storage may be unavailable */ }
            const update = () => { const hasCode = code.value.trim() !== ''; resume.disabled = !hasCode; remove.disabled = !hasCode; };
            const busy = value => { save.disabled = value; resume.disabled = value || code.value.trim() === ''; remove.disabled = value || code.value.trim() === ''; };
            const announce = (message, failed = false) => { status.textContent = message; status.classList.toggle('bbf-error', failed); };
            code.addEventListener('input', () => { request++; busy(false); announce(''); update(); }); update();
            save.addEventListener('click', async () => {
                const current = ++request; const submittedHandle = code.value.trim(); busy(true); announce(this._t('draftSaving', {}, langCode));
                const body = this._draftCollect(formEl, fields, policy.fields);
                body._bbf_csrf = csrfToken || ''; if (submittedHandle) body._bbf_draft_handle = submittedHandle;
                try {
                    const result = await this._draftRequest(baseUrl, formId, 'draft_save', body, isSameOrigin);
                    if (current !== request || code.value.trim() !== submittedHandle) return;
                    code.value = result.handle;
                    try { localStorage.setItem(storageKey, result.handle); } catch (error) { /* code remains visible */ }
                    announce(this._t('draftSaved', { expires: result.expires_at }, langCode));
                } catch (error) {
                    if (current !== request || code.value.trim() !== submittedHandle) return;
                    if (error.status === 404 || error.status === 410) { code.value = ''; try { localStorage.removeItem(storageKey); } catch (storageError) {} }
                    announce(error.message || this._t('draftFailed', {}, langCode), true);
                } finally { if (current === request) { busy(false); update(); } }
            });
            resume.addEventListener('click', async () => {
                const current = ++request; const submittedHandle = code.value.trim(); busy(true); announce(this._t('draftLoading', {}, langCode));
                try {
                    const result = await this._draftRequest(baseUrl, formId, 'draft_load', { _bbf_csrf: csrfToken || '', _bbf_draft_handle: submittedHandle }, isSameOrigin);
                    if (current !== request || code.value.trim() !== submittedHandle) return;
                    this._draftApply(formEl, fields, result.data, policy.fields);
                    try { localStorage.setItem(storageKey, submittedHandle); } catch (error) {}
                    announce(this._t('draftLoaded', {}, langCode));
                } catch (error) {
                    if (current !== request || code.value.trim() !== submittedHandle) return;
                    if (error.status === 404 || error.status === 410) { code.value = ''; try { localStorage.removeItem(storageKey); } catch (storageError) {} }
                    announce(error.message || this._t('draftFailed', {}, langCode), true);
                } finally { if (current === request) { busy(false); update(); } }
            });
            remove.addEventListener('click', async () => {
                const current = ++request; const submittedHandle = code.value.trim(); busy(true); announce(this._t('draftDeleting', {}, langCode));
                try {
                    await this._draftRequest(baseUrl, formId, 'draft_delete', { _bbf_csrf: csrfToken || '', _bbf_draft_handle: submittedHandle }, isSameOrigin);
                    if (current !== request || code.value.trim() !== submittedHandle) return;
                    code.value = ''; try { localStorage.removeItem(storageKey); } catch (error) {}
                    announce(this._t('draftDeleted', {}, langCode));
                } catch (error) {
                    if (current !== request || code.value.trim() !== submittedHandle) return;
                    announce(error.message || this._t('draftFailed', {}, langCode), true);
                } finally { if (current === request) { busy(false); update(); } }
            });
            return wrap;
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
                el._bbfPageState = { currentPage, totalPages: pages.length, langCode };

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

            el.querySelectorAll('.bbf-repeatable-group').forEach(group => {
                if (group._bbfBindRows) group._bbfBindRows();
            });

            el.addEventListener('reset', () => {
                setTimeout(() => {
                    this._resetRepeatableGroups(el);
                    this._resetCustomFields(el);
                    this._stabilizeOptionConditions(el);
                    this._applyConditions(el, allFlat, false);
                }, 0);
            });

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

            const draftControls = this._buildDraftControls(el, form, formId, baseUrl, csrfToken, langCode, isSameOrigin, dataFields);
            if (draftControls) el.appendChild(draftControls);

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
                    Object.assign(errors, self._validateRepeatableGroups(pages[currentPage.value], el, langCode));
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
                Object.assign(errors, this._validateRepeatableGroups(form.fields || [], el, langCode));
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
                    this._collectRepeatableGroups(form.fields || [], el, body);

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
                        // onSuccess callback — return false to skip default handling
                        if (options.onSuccess && options.onSuccess(result, body) === false) {
                            btn.disabled = false;
                            btn.textContent = form.submit_label || this._t('submitDefault', {}, langCode);
                            return;
                        }
                        if (result.redirect) {
                            window.location.href = result.redirect;
                            return;
                        }
                        msg.className = 'bbf-message bbf-success';
                        msg.textContent = form.success_message || this._t('successDefault', {}, langCode);
                        msg.style.display = 'block';
                        el.reset();
                        this._resetCustomFields(el);
                        this._stabilizeOptionConditions(el);
                        this._applyConditions(el, allFlat, false);
                        this._clearErrors(el);

                        if (options.hideOnSuccess) {
                            Array.from(el.querySelectorAll('.bbf-field, .bbf-submit-wrap, .bbf-page, .bbf-page-nav')).forEach(f => f.style.display = 'none');
                        }
                    } else {
                        if (options.onError) options.onError(result);
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
            const hasConditions = allFlat.some(f => f.show_if || (f.options || []).some(o => typeof o === 'object' && o.show_if));
            if (hasConditions) {
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
                    tplFields = self._resolveTemplates(tplFields, templates);
                    var tplNames = {};
                    var collectTemplateNames = function(items) {
                        items.forEach(function(f) {
                            tplNames[f.name] = true;
                            if (f.type === 'group' && f.fields) collectTemplateNames(f.fields);
                        });
                    };
                    collectTemplateNames(tplFields);
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
                if (f.options) {
                    f.options = f.options.map(function(option) {
                        if (!option || typeof option !== 'object' || !option.show_if) return option;
                        return Object.assign({}, option, { show_if: self._prefixCondition(option.show_if, prefix, tplNames) });
                    });
                }
                if (f.lookup && f.lookup.map) {
                    f.lookup = Object.assign({}, f.lookup, { map: Object.fromEntries(
                        Object.entries(f.lookup.map).map(function(entry) {
                            return [tplNames[entry[0]] ? prefix + entry[0] : entry[0], entry[1]];
                        })
                    ) });
                }
                if (f.autocomplete_from && typeof f.autocomplete_from === 'object' && f.autocomplete_from.map) {
                    f.autocomplete_from = Object.assign({}, f.autocomplete_from, { map: Object.fromEntries(
                        Object.entries(f.autocomplete_from.map).map(function(entry) {
                            return [tplNames[entry[0]] ? prefix + entry[0] : entry[0], entry[1]];
                        })
                    ) });
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

        _buildRepeatableGroup: function(field, langCode) {
            const group = document.createElement('div');
            group.className = 'bbf-field bbf-group bbf-repeatable-group';
            if (field.css_class) group.className += ' ' + field.css_class;
            group.setAttribute('data-field', field.name);
            const label = field.title || field.label || field.name;
            const titleId = `bbf-${field.name}-title`;
            if (label) {
                const title = document.createElement('h3');
                title.className = 'bbf-group-title';
                title.id = titleId;
                title.textContent = label;
                group.appendChild(title);
                group.setAttribute('role', 'group');
                group.setAttribute('aria-labelledby', titleId);
            }
            if (field.description) {
                const description = document.createElement('p');
                description.className = 'bbf-group-desc';
                description.textContent = field.description;
                group.appendChild(description);
            }
            const error = document.createElement('div');
            error.className = 'bbf-field-error bbf-repeatable-error';
            error.id = `bbf-${field.name}-error`;
            error.setAttribute('role', 'alert');
            group.setAttribute('aria-describedby', error.id);
            group.appendChild(error);

            const rows = document.createElement('div');
            rows.className = 'bbf-repeatable-rows';
            rows.id = `bbf-${field.name}-rows`;
            group.appendChild(rows);
            const controls = document.createElement('div');
            controls.className = 'bbf-repeatable-controls';
            const add = document.createElement('button');
            add.type = 'button';
            add.className = 'bbf-repeatable-add';
            add.textContent = field.add_label || this._t('repeatableAdd', {}, langCode);
            add.setAttribute('aria-controls', rows.id);
            controls.appendChild(add);
            const status = document.createElement('span');
            status.className = 'bbf-repeatable-status';
            status.setAttribute('role', 'status');
            status.setAttribute('aria-live', 'polite');
            controls.appendChild(status);
            group.appendChild(controls);

            const min = field.min_items === undefined ? 1 : field.min_items;
            const max = field.max_items === undefined ? 10 : field.max_items;
            let nextRowId = 1;
            group._bbfRows = [];
            group._bbfRowsContainer = rows;

            const bindRowConditions = row => {
                if (row._bbfConditionsBound) return;
                const valueRoot = group.closest('.bbf-form');
                if (!valueRoot) return;
                const rowFlat = this._flattenFields(row._bbfFields);
                const hasConditions = rowFlat.some(child => child.show_if
                    || (child.options || []).some(option => typeof option === 'object' && option.show_if));
                if (hasConditions) row._bbfConditionCleanup = this._bindConditions(valueRoot, rowFlat, false, row);
                row._bbfConditionsBound = true;
            };

            const update = announce => {
                const activeRows = this._repeatableRows(group);
                activeRows.forEach((row, index) => {
                    const itemLabel = this._t('repeatableItem', { label, index: index + 1 }, langCode);
                    row.querySelector('.bbf-repeatable-row-title').textContent = itemLabel;
                    const remove = row.querySelector('.bbf-repeatable-remove');
                    remove.disabled = activeRows.length <= min;
                    remove.setAttribute('aria-label', (field.remove_label || this._t('repeatableRemove', {}, langCode)) + ': ' + itemLabel);
                });
                add.disabled = activeRows.length >= max;
                if (announce) status.textContent = this._t('repeatableCount', { label, count: activeRows.length }, langCode);
            };

            const addRow = focus => {
                if (this._repeatableRows(group).length >= max) return null;
                const rowId = nextRowId++;
                const prefix = `${field.name}__${rowId}__`;
                const row = document.createElement('fieldset');
                row.className = 'bbf-repeatable-row';
                row.setAttribute('data-bbf-row-id', rowId);
                row._bbfPrefix = prefix;
                const legend = document.createElement('legend');
                legend.className = 'bbf-repeatable-row-title';
                row.appendChild(legend);
                let children = field.fields || [];
                if (field.shuffle) children = this._shuffle(children);
                row._bbfFields = this._scopeRepeatableFields(children, prefix);
                row._bbfFields.forEach(child => { row.appendChild(this._buildField(child, langCode)); });
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'bbf-repeatable-remove';
                remove.textContent = field.remove_label || this._t('repeatableRemove', {}, langCode);
                remove.addEventListener('click', () => {
                    if (this._repeatableRows(group).length <= min) return;
                    if (row._bbfConditionCleanup) row._bbfConditionCleanup();
                    row.remove();
                    update(true);
                });
                row.appendChild(remove);
                rows.appendChild(row);
                group._bbfRows.push(row);
                bindRowConditions(row);
                update(focus);
                if (focus) {
                    const input = row.querySelector('input, select, textarea');
                    if (input) input.focus();
                }
                return row;
            };

            add.addEventListener('click', () => { addRow(true); });
            group._bbfResetRows = () => {
                this._repeatableRows(group).forEach(row => {
                    if (row._bbfConditionCleanup) row._bbfConditionCleanup();
                    row.remove();
                });
                for (let index = 0; index < min; index++) addRow(false);
                status.textContent = '';
                update(false);
            };
            group._bbfAddRow = addRow;
            group._bbfBindRows = () => { this._repeatableRows(group).forEach(bindRowConditions); };
            group._bbfResetRows();
            return group;
        },

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
                if (field.repeatable) return this._buildRepeatableGroup(field, langCode);
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
                        if (typeof o === 'object' && o.show_if) {
                            opt.setAttribute('data-option-show-if', JSON.stringify(o.show_if));
                        }
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
                otherInput.setAttribute('aria-label', field.other_label || 'Other');
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
                const confirmLabel = this._t('emailMismatch', { label: field.label || field.name }, langCode).includes('match')
                    ? 'Confirm ' + (field.label || 'email')
                    : (field.label || 'email') + ' (confirm)';
                confirmInput.placeholder = confirmLabel;
                confirmInput.setAttribute('aria-label', confirmLabel);
                confirmInput.style.marginTop = '6px';
                if (field.required) confirmInput.required = true;
                wrap.appendChild(confirmInput);
            }

            // Lookup: fetch data from URL and auto-fill other form fields
            if (field.lookup && field.lookup.url && field.lookup.map) {
                const lk = field.lookup;
                const trigger = lk.trigger || 'blur';
                let lookupRequest = 0;

                const doLookup = async function() {
                    var val = input.value.trim();
                    var request = ++lookupRequest;
                    if (!val) return;
                    if (typeof trigger === 'number' && val.length < trigger) return;

                    try {
                        var url = lk.url.replace('{{value}}', encodeURIComponent(val));
                        var resp = await fetch(url);
                        if (!resp.ok || request !== lookupRequest || input.value.trim() !== val) return;
                        var data = await resp.json();
                        if (request !== lookupRequest || input.value.trim() !== val) return;

                        var formEl = input.closest('.bbf-form');
                        if (!formEl) return;

                        Object.keys(lk.map).forEach(function(formField) {
                            var responseKey = lk.map[formField];
                            var value = responseKey.split('.').reduce(function(obj, key) { return obj && obj[key]; }, data);
                            if (value !== undefined && value !== null) {
                                var target = formEl.querySelector('[name="' + formField + '"]');
                                if (target) {
                                    target.value = String(value);
                                    target.dispatchEvent(new Event('input', { bubbles: true }));
                                    target.dispatchEvent(new Event('change', { bubbles: true }));
                                }
                            }
                        });
                    } catch (err) {
                        if (request === lookupRequest) console.warn('BBF lookup failed for ' + field.name + ':', err);
                    }
                };

                if (typeof trigger === 'number') {
                    input.addEventListener('input', doLookup);
                } else {
                    input.addEventListener(trigger === 'change' ? 'change' : 'blur', doLookup);
                }
            }

            // Autocomplete: show typeahead suggestions from URL
            if (field.autocomplete_from) {
                var acConfig = typeof field.autocomplete_from === 'object' ? field.autocomplete_from : { url: field.autocomplete_from };
                var acUrl = acConfig.url;
                var acMinLen = acConfig.min_length || 2;
                var acDebounce = acConfig.debounce !== undefined ? acConfig.debounce : 300;
                var acMap = acConfig.map || null;

                var acList = document.createElement('div');
                var acListId = `bbf-${field.name}-autocomplete`;
                acList.className = 'bbf-autocomplete-list';
                acList.id = acListId;
                acList.setAttribute('role', 'listbox');
                acList.style.display = 'none';
                wrap.style.position = 'relative';
                wrap.appendChild(acList);

                var acTimer = null;
                var acBlurTimer = null;
                var acActive = -1;
                var acRequest = 0;

                input.setAttribute('autocomplete', 'off');
                input.setAttribute('role', 'combobox');
                input.setAttribute('aria-autocomplete', 'list');
                input.setAttribute('aria-expanded', 'false');
                input.setAttribute('aria-controls', acListId);

                input.addEventListener('input', function(e) {
                    clearTimeout(acTimer);
                    clearTimeout(acBlurTimer);
                    var request = ++acRequest;
                    // Skip programmatic input events (from lookup, autocomplete select, etc.)
                    if (!e.isTrusted) {
                        acList.style.display = 'none';
                        input.setAttribute('aria-expanded', 'false');
                        input.removeAttribute('aria-activedescendant');
                        return;
                    }
                    var val = input.value.trim();
                    if (val.length < acMinLen) {
                        acList.style.display = 'none';
                        input.setAttribute('aria-expanded', 'false');
                        input.removeAttribute('aria-activedescendant');
                        return;
                    }

                    acTimer = setTimeout(async function() {
                        try {
                            var url = acUrl.replace('{{value}}', encodeURIComponent(val));
                            var resp = await fetch(url);
                            if (!resp.ok || request !== acRequest || input.value.trim() !== val) return;
                            var items = await resp.json();
                            if (request !== acRequest || input.value.trim() !== val) return;

                            acList.innerHTML = '';
                            acActive = -1;
                            input.removeAttribute('aria-activedescendant');
                            if (!items || !items.length) {
                                acList.style.display = 'none';
                                input.setAttribute('aria-expanded', 'false');
                                return;
                            }

                            items.forEach(function(item, index) {
                                var div = document.createElement('div');
                                div.className = 'bbf-autocomplete-item';
                                div.id = acListId + '-' + index;
                                div.setAttribute('role', 'option');
                                div.setAttribute('aria-selected', 'false');
                                div.textContent = typeof item === 'object' ? item.label : item;
                                div.addEventListener('mousedown', function(e) {
                                    e.preventDefault();
                                    input.value = typeof item === 'object' ? item.value : item;
                                    acList.style.display = 'none';
                                    input.setAttribute('aria-expanded', 'false');
                                    input.removeAttribute('aria-activedescendant');
                                    input.dispatchEvent(new Event('input', { bubbles: true }));
                                    input.dispatchEvent(new Event('change', { bubbles: true }));
                                    // Map extra fields from response item
                                    if (acMap && typeof item === 'object') {
                                        var formEl = input.closest('.bbf-form');
                                        if (formEl) {
                                            Object.keys(acMap).forEach(function(formField) {
                                                var responseKey = acMap[formField];
                                                var val = responseKey.split('.').reduce(function(obj, k) { return obj && obj[k]; }, item);
                                                if (val !== undefined && val !== null) {
                                                    var target = formEl.querySelector('[name="' + formField + '"]');
                                                    if (target) {
                                                        target.value = String(val);
                                                        target.dispatchEvent(new Event('input', { bubbles: true }));
                                                        target.dispatchEvent(new Event('change', { bubbles: true }));
                                                    }
                                                }
                                            });
                                        }
                                    }
                                });
                                acList.appendChild(div);
                            });
                            acList.style.display = '';
                            input.setAttribute('aria-expanded', 'true');
                        } catch (err) {
                            if (request === acRequest) console.warn('BBF autocomplete failed for ' + field.name + ':', err);
                        }
                    }, acDebounce);
                });

                input.addEventListener('keydown', function(e) {
                    var items = acList.querySelectorAll('.bbf-autocomplete-item');
                    if (!items.length || acList.style.display === 'none') return;

                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        acActive = Math.min(acActive + 1, items.length - 1);
                        items.forEach(function(el, i) {
                            var isActive = i === acActive;
                            el.classList.toggle('bbf-autocomplete-active', isActive);
                            el.setAttribute('aria-selected', isActive ? 'true' : 'false');
                        });
                        input.setAttribute('aria-activedescendant', items[acActive].id);
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        acActive = Math.max(acActive - 1, 0);
                        items.forEach(function(el, i) {
                            var isActive = i === acActive;
                            el.classList.toggle('bbf-autocomplete-active', isActive);
                            el.setAttribute('aria-selected', isActive ? 'true' : 'false');
                        });
                        input.setAttribute('aria-activedescendant', items[acActive].id);
                    } else if (e.key === 'Enter' && acActive >= 0) {
                        e.preventDefault();
                        items[acActive].dispatchEvent(new MouseEvent('mousedown'));
                    } else if (e.key === 'Escape') {
                        acList.style.display = 'none';
                        input.setAttribute('aria-expanded', 'false');
                        input.removeAttribute('aria-activedescendant');
                    }
                });

                input.addEventListener('focus', function() {
                    clearTimeout(acBlurTimer);
                });
                input.addEventListener('blur', function() {
                    acBlurTimer = setTimeout(function() {
                        acRequest++;
                        acList.style.display = 'none';
                        input.setAttribute('aria-expanded', 'false');
                        input.removeAttribute('aria-activedescendant');
                    }, 200);
                });
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
                otherText.setAttribute('aria-label', field.other_label || 'Other');
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
            starsWrap.setAttribute('aria-describedby', (field.description ? `bbf-${field.name}-desc ` : '') + `bbf-${field.name}-error`);

            const updateRating = (value, focusStar = false, emitEvents = true) => {
                const parsed = Number(value);
                const selected = Number.isInteger(parsed) ? Math.min(maxRating, Math.max(0, parsed)) : 0;
                hidden.value = selected ? String(selected) : '';
                starsWrap.querySelectorAll('.bbf-star').forEach(s => {
                    const sv = parseInt(s.getAttribute('data-value'));
                    const checked = selected > 0 && sv === selected;
                    s.classList.toggle('bbf-star-active', selected > 0 && sv <= selected);
                    s.setAttribute('aria-checked', checked ? 'true' : 'false');
                    s.setAttribute('tabindex', (checked || (!selected && sv === 1)) ? '0' : '-1');
                    if (focusStar && checked) s.focus();
                });
                if (emitEvents) {
                    hidden.dispatchEvent(new Event('input', { bubbles: true }));
                    hidden.dispatchEvent(new Event('change', { bubbles: true }));
                }
            };
            hidden.setAttribute('data-bbf-rating-reset', '');
            hidden._bbfSetRating = value => updateRating(value, false, false);
            hidden._bbfResetRating = () => updateRating(0, false, false);

            for (let i = 1; i <= maxRating; i++) {
                const star = document.createElement('span');
                star.className = 'bbf-star';
                star.setAttribute('data-value', i);
                star.setAttribute('role', 'radio');
                star.setAttribute('tabindex', i === 1 ? '0' : '-1');
                star.setAttribute('aria-checked', 'false');
                star.setAttribute('aria-label', `${i} / ${maxRating}`);
                star.textContent = '\u2605'; // ★
                starsWrap.appendChild(star);
            }

            starsWrap.addEventListener('click', (e) => {
                const star = e.target.closest('.bbf-star');
                if (!star) return;
                updateRating(parseInt(star.getAttribute('data-value')), true);
            });

            starsWrap.addEventListener('keydown', (e) => {
                const star = e.target.closest('.bbf-star');
                if (!star) return;
                const current = parseInt(star.getAttribute('data-value'));
                if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                    e.preventDefault();
                    updateRating(Math.min(current + 1, maxRating), true);
                } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    updateRating(Math.max(current - 1, 1), true);
                } else if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    updateRating(current, true);
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

        _errorWrap: function(formEl, name) {
            const direct = formEl.querySelector(`[data-field="${name}"]`);
            if (direct) return direct;
            const match = /^([^.]+)\.(\d+)\.(.+)$/.exec(name);
            if (!match) return null;
            const group = formEl.querySelector(`[data-field="${match[1]}"]`);
            if (!group) return null;
            const row = this._repeatableRows(group)[Number(match[2])];
            return row ? row.querySelector(`[data-field="${row._bbfPrefix + match[3]}"]`) : null;
        },

        _showErrors: function(formEl, errors) {
            this._clearErrors(formEl);
            let firstInput = null;
            Object.entries(errors).forEach(([name, msg]) => {
                const wrap = this._errorWrap(formEl, name);
                if (wrap) {
                    wrap.classList.add('bbf-has-error');
                    const errEl = wrap.querySelector('.bbf-field-error');
                    if (errEl) errEl.textContent = msg;
                    const ratingGroup = wrap.querySelector('.bbf-rating-stars');
                    const inp = ratingGroup
                        ? (ratingGroup.querySelector('.bbf-star[tabindex="0"]') || ratingGroup.querySelector('.bbf-star'))
                        : wrap.querySelector('input, select, textarea');
                    if (inp) {
                        (ratingGroup || inp).setAttribute('aria-invalid', 'true');
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
                const page = firstInput.closest('.bbf-page');
                const pageState = formEl._bbfPageState;
                if (page && pageState) {
                    const pageIndex = Array.from(formEl.querySelectorAll('.bbf-page')).indexOf(page);
                    if (pageIndex >= 0) {
                        pageState.currentPage.value = pageIndex;
                        this._showPage(formEl, pageIndex, pageState.totalPages, pageState.langCode);
                    }
                }
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
                var minorUnits = Number.isInteger(p.payment.minor_units) ? p.payment.minor_units : 2;
                var amount = Number(p.payment.amount_minor) / Math.pow(10, minorUnits);
                h += '<div style="margin-bottom:6px"><strong>Payment:</strong> '
                    + p.payment.provider + ' &mdash; '
                    + amount.toFixed(minorUnits) + ' ' + p.payment.currency
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
    const init = () => {
        document.querySelectorAll('[data-form]').forEach(el => {
            const formId = el.getAttribute('data-form');
            const opts = {
                showTitle: el.getAttribute('data-show-title') !== 'false',
                hideOnSuccess: el.getAttribute('data-hide-on-success') === 'true',
            };
            BBF.render(formId, el, opts);
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Expose globally
    window.BBF = BBF;

})();
