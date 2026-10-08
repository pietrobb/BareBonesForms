<?php
/**
 * BareBonesForms — server-side form HTML for the embedded mode (2.2.0).
 *
 * bbf_render_html() writes the DOM and classes bbf.js builds, so bbf.css styles it and BBF.enhance() can add the
 * client-side validation and conditions. No session, configuration or I/O: a host application loads this file
 * (it loads bbf_form.php) and posts the form to its own endpoint, where bbf_validate() checks the input.
 */
require_once __DIR__ . '/bbf_form.php';

const BBF_RENDER_INPUT_TYPES = ['text', 'email', 'tel', 'url', 'number', 'date', 'password'];
const BBF_RENDER_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'svg'];

/**
 * A complete <form method="post"> for $form (a definition from bbf_load_form() or an array).
 *   $opts  action        the URL the form posts to (required)
 *          values        [field => current value] when editing; a file field's value is ['url' => …, 'name' => …]
 *                        (or a list of them): shown as a preview or link with a "<field>__remove" checkbox. A
 *                        select/radio/checkbox value no longer among the options is offered as an option (selected),
 *                        so saving does not change it; pass the same values to bbf_validate() to accept it there.
 *          errors        [field => message] from bbf_validate(); keys that match no field show above the button
 *          hidden        [name => value] extra hidden inputs, e.g. the host's CSRF token
 *          lang ('en'), messages, options_resolver   as in bbf_validate()
 *          id_prefix     ('bbf-') element ids are "<prefix><field>": 'field-' gives id="field-sku"
 *          form_attrs    [attribute => value] on <form>, e.g. ['id' => 'form-admin-product', 'class' => 'admin-form'];
 *                        class is added to bbf-form; method, action and enctype are BBF's and cannot be set here
 *          before_submit trusted HTML of the host, inserted unescaped right before the submit button (extra inputs
 *                        the definition does not describe; bbf_validate() ignores input keys it does not know)
 *          submit_label  overrides the definition's submit_label; show_title (true) the <h2> with the form name
 *          templates     control(array $field, mixed $value, array $ctx): ?string   a field's control, null = BBF's
 *                        field(array $field, string $controlHtml, array $ctx): ?string   the whole field, null = BBF's
 *                        ctx: id, name (the input name), value, error, error_id (the id of the error element, for
 *                        aria-describedby), attrs (raw [attribute => value] BBF puts on the input; print them with
 *                        bbf_attrs()), hidden (true when show_if hides the field now), lang. Templates return HTML
 *                        and escape what they print (bbf_e(), bbf_attrs()).
 * A select gets an empty "—" choice when it has no placeholder and is optional or has no value yet.
 * Throws BbfFormException for a definition error and for a field this renderer cannot draw (a repeatable group,
 * rating, page_break, an x- type without a render handler) unless templates.control draws it: never a silent gap.
 */
function bbf_render_html(array $form, array $opts): string {
    $action = $opts['action'] ?? null;
    if (!is_string($action) || trim($action) === '') {
        throw new BbfFormException('bbf_render_html(): opts.action, the URL the form posts to, is required.');
    }
    $templates = is_array($opts['templates'] ?? null) ? $opts['templates'] : [];
    foreach ($templates as $key => $template) {
        if (!in_array($key, ['control', 'field'], true) || !is_callable($template)) {
            throw new BbfFormException("bbf_render_html(): templates.$key is not a callable control or field template.");
        }
    }
    $resolver = $opts['options_resolver'] ?? null;
    if ($resolver !== null && !is_callable($resolver)) throw new BbfFormException('options_resolver must be callable.');
    $prefix = $opts['id_prefix'] ?? 'bbf-';
    if (!is_string($prefix) || !preg_match('/\A[A-Za-z][A-Za-z0-9_-]*\z/D', $prefix)) {
        throw new BbfFormException('bbf_render_html(): id_prefix must start with a letter and contain only letters, digits, - and _.');
    }
    $formAttrs = $opts['form_attrs'] ?? [];
    if (!is_array($formAttrs) || array_intersect_key($formAttrs, ['method' => 1, 'action' => 1, 'enctype' => 1])) {
        throw new BbfFormException('bbf_render_html(): form_attrs must be an array without method, action and enctype.');
    }
    $beforeSubmit = $opts['before_submit'] ?? '';
    if (!is_string($beforeSubmit)) throw new BbfFormException('bbf_render_html(): before_submit must be an HTML string.');
    $form = bbf_prepare_form($form);
    return bbf_with_messages($opts, static function () use ($form, $opts, $action, $templates, $resolver, $prefix, $formAttrs, $beforeSubmit): string {
        $values = is_array($opts['values'] ?? null) ? $opts['values'] : [];
        $fields = bbf_keep_stored_options(bbf_resolve_options($form['fields'], $resolver), $values);
        $flat = bbf_flatten_fields($fields);
        $current = [];
        foreach ($flat as $field) {
            $name = $field['name'] ?? null;
            if (!is_string($name)) continue;
            if (array_key_exists($name, $values)) $current[$name] = $values[$name];
            elseif (array_key_exists('value', $field)) $current[$name] = $field['value'];
        }
        $visible = bbfVisibleInput($flat, $current);
        $hidden = [];
        foreach ($flat as $field) {
            if (is_string($field['name'] ?? null) && !empty($field['show_if']) && !bbf_eval_condition($field['show_if'], $visible)) {
                $hidden[$field['name']] = true;
            }
        }
        $state = [
            'values' => $values,
            'errors' => array_filter(is_array($opts['errors'] ?? null) ? $opts['errors'] : [], 'is_scalar'),
            'hidden' => $hidden,
            'prefix' => $prefix,
            'templates' => $templates,
            'lang' => is_string($opts['lang'] ?? null) ? $opts['lang'] : 'en',
            'rendered' => [],
            'multipart' => false,
        ];
        $body = '';
        foreach ($fields as $field) $body .= bbf_render_field($field, $state);

        $formClass = bbf_render_class(['bbf-form',
            in_array($form['label_position'] ?? null, ['left', 'right'], true) ? 'bbf-labels-' . $form['label_position'] : null,
            is_string($formAttrs['class'] ?? null) ? $formAttrs['class'] : null]);
        $html = '<form' . bbf_attrs([
            'class' => $formClass,
            'method' => 'post',
            'action' => $action,
            'enctype' => $state['multipart'] ? 'multipart/form-data' : null,
            'data-form-id' => is_string($form['id'] ?? null) ? $form['id'] : null,
            'data-bbf-instance' => rtrim($prefix, '-_'),
        ] + array_diff_key($formAttrs, ['class' => 1])) . '>';
        if (is_string($form['name'] ?? null) && $form['name'] !== '' && ($opts['show_title'] ?? true) !== false) {
            $html .= '<h2 class="bbf-title">' . bbf_e($form['name']) . '</h2>';
        }
        if (is_string($form['description'] ?? null) && $form['description'] !== '') {
            $html .= '<p class="bbf-description">' . bbf_e($form['description']) . '</p>';
        }
        foreach (is_array($opts['hidden'] ?? null) ? $opts['hidden'] : [] as $name => $value) {
            if (is_scalar($value)) $html .= '<input' . bbf_attrs(['type' => 'hidden', 'name' => (string)$name, 'value' => (string)$value]) . '>';
        }
        $html .= $body;
        $formLevel = array_diff_key($state['errors'], $state['rendered']);
        $html .= $formLevel
            ? '<div class="bbf-message bbf-error" role="status" aria-live="polite">' . bbf_e(implode(' ', array_map('strval', $formLevel))) . '</div>'
            : '<div class="bbf-message" role="status" aria-live="polite" style="display:none"></div>';
        $label = $opts['submit_label'] ?? $form['submit_label'] ?? null;
        if (!is_string($label) || $label === '') $label = bbf_t('submitDefault');
        $html .= $beforeSubmit . '<div class="bbf-field bbf-submit-wrap"><button type="submit" class="bbf-submit">' . bbf_e($label) . '</button></div>';
        return $html . '</form>';
    });
}

/** HTML-escape for text and attribute values. */
function bbf_e(mixed $value): string {
    return htmlspecialchars(is_scalar($value) ? (string)$value : '', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/**
 * ' name="value"' pairs, values escaped; null/false omit the attribute, true writes it bare (required). For templates and
 * render handlers printing ctx.attrs. An attribute name that is not a plain HTML name throws.
 */
function bbf_attrs(array $attrs): string {
    $out = '';
    foreach ($attrs as $name => $value) {
        if (!preg_match('/\A[A-Za-z_:][A-Za-z0-9_:.-]*\z/D', (string)$name)) {
            throw new InvalidArgumentException('bbf_attrs(): invalid attribute name ' . json_encode((string)$name));
        }
        if ($value === null || $value === false) continue;
        $out .= $value === true ? ' ' . $name : ' ' . $name . '="' . bbf_e($value) . '"';
    }
    return $out;
}

/** One field of the definition tree, sections and groups included. */
function bbf_render_field(array $field, array &$state): string {
    $type = $field['type'] ?? 'text';
    $name = is_string($field['name'] ?? null) ? $field['name'] : '';
    $condition = isset($state['hidden'][$name]) ? ['style' => 'display:none', 'data-conditional-hidden' => 'true'] : [];

    if ($type === 'section' || ($type === 'group' && empty($field['repeatable']))) {
        $kind = $type === 'section' ? 'section' : 'group';
        $html = '<div' . bbf_attrs(['class' => bbf_render_class(["bbf-field bbf-$kind", $field['css_class'] ?? null]),
            'data-field' => $name !== '' ? $name : '_section'] + $condition) . '>';
        $title = $field['title'] ?? $field['label'] ?? null;
        if (is_string($title) && $title !== '') $html .= "<h3 class=\"bbf-$kind-title\">" . bbf_e($title) . '</h3>';
        if (is_string($field['description'] ?? null) && $field['description'] !== '') {
            $html .= "<p class=\"bbf-$kind-desc\">" . bbf_e($field['description']) . '</p>';
        }
        if ($type === 'group') foreach ($field['fields'] ?? [] as $child) $html .= bbf_render_field($child, $state);
        return $html . '</div>';
    }

    $id = $state['prefix'] . $name;
    $error = isset($state['errors'][$name]) ? (string)$state['errors'][$name] : null;
    $state['rendered'][$name] = true;
    if ($type === 'file') $state['multipart'] = true;
    $value = array_key_exists($name, $state['values']) ? $state['values'][$name] : ($field['value'] ?? null);
    $describedBy = trim((!empty($field['description']) ? "$id-desc " : '') . "$id-error" . ($type === 'file' ? " $id-files" : ''));
    $attrs = [
        'placeholder' => is_scalar($field['placeholder'] ?? null) && $field['placeholder'] !== '' ? $field['placeholder'] : null,
        'required' => !empty($field['required']),
        'readonly' => !empty($field['readonly']),
        'minlength' => $field['minlength'] ?? null,
        'maxlength' => $field['maxlength'] ?? null,
        'min' => $field['min'] ?? null,
        'max' => $field['max'] ?? null,
        'step' => $type === 'number' ? ($field['step'] ?? null) : null,
        'pattern' => $field['pattern'] ?? null,
        'autocomplete' => $field['autocomplete'] ?? null,
        'aria-describedby' => $describedBy,
        'aria-invalid' => $error !== null ? 'true' : null,
    ];
    $attrs = array_filter($attrs, static fn($v) => $v === true || $v === false || is_scalar($v));
    $inputName = $name;
    if ($type === 'checkbox' || ($type === 'file' && bbf_uploads_field_max_files($field) > 1)) $inputName .= '[]';
    $ctx = ['id' => $id, 'name' => $inputName, 'value' => $value, 'error' => $error, 'error_id' => "$id-error", 'attrs' => $attrs,
        'hidden' => $condition !== [], 'lang' => $state['lang']];

    $control = null;
    if (isset($state['templates']['control'])) $control = ($state['templates']['control'])($field, $value, $ctx);
    if ($control === null && bbf_is_custom_type($type)) {
        $render = bbf_custom_type($type)['render'] ?? null;
        if (!is_callable($render)) throw new BbfFormException("Field '$name': custom type $type has no render handler; register one or use templates.control.");
        $control = $render($field, $value, $ctx);
    }
    $control ??= bbf_render_control($field, $value, $ctx, $state);
    if (!is_string($control)) throw new BbfFormException("Field '$name': a control template or render handler returned no HTML.");

    if (isset($state['templates']['field'])) {
        $custom = ($state['templates']['field'])($field, $control, $ctx);
        if ($custom !== null) {
            if (!is_string($custom)) throw new BbfFormException("Field '$name': the field template returned no HTML.");
            return $custom;
        }
    }
    return bbf_render_wrap($field, $control, $ctx, $condition);
}

/** BBF's own control for $field: the input(s) only; bbf_render_wrap() adds label, description and error. */
function bbf_render_control(array $field, mixed $value, array $ctx, array $state): string {
    $type = $field['type'] ?? 'text';
    $name = $field['name'];
    $base = ['name' => $ctx['name'], 'id' => $ctx['id']];
    $attrs = $ctx['attrs'];
    $scalar = is_scalar($value) ? (string)$value : '';

    if (in_array($type, BBF_RENDER_INPUT_TYPES, true) || $type === 'textarea') {
        $class = 'bbf-input' . (!empty($field['readonly']) ? ' bbf-readonly' : '');
        $html = $type === 'textarea'
            ? '<textarea' . bbf_attrs($base + ['class' => $class, 'rows' => (int)($field['rows'] ?? 4) ?: 4] + $attrs) . '>' . bbf_e($scalar) . '</textarea>'
            : '<input' . bbf_attrs(['type' => $type] + $base + ['class' => $class, 'value' => $scalar] + $attrs) . '>';
        if (is_scalar($field['prefix'] ?? null) || is_scalar($field['suffix'] ?? null)) {
            $html = '<div class="bbf-input-group">'
                . (is_scalar($field['prefix'] ?? null) ? '<span class="bbf-input-prefix">' . bbf_e($field['prefix']) . '</span>' : '')
                . $html
                . (is_scalar($field['suffix'] ?? null) ? '<span class="bbf-input-suffix">' . bbf_e($field['suffix']) . '</span>' : '')
                . '</div>';
        }
        if ($type === 'email' && !empty($field['confirm'])) {
            $label = bbf_t('emailConfirm', ['label' => $field['label'] ?? $name]);
            $confirm = $state['values'][$name . '_confirm'] ?? $value;
            $html .= '<input' . bbf_attrs(['type' => 'email', 'name' => $name . '_confirm', 'id' => $ctx['id'] . '-confirm',
                'class' => 'bbf-input', 'value' => is_scalar($confirm) ? (string)$confirm : '', 'placeholder' => $label,
                'aria-label' => $label, 'style' => 'margin-top:6px', 'required' => !empty($field['required'])]) . '>';
        }
        return $html;
    }

    if ($type === 'select') {
        $options = bbf_render_options($field);
        $wanted = $value === null ? null : array_map('strval', array_filter(is_array($value) ? $value : [$value], 'is_scalar'));
        $matched = $wanted !== null && array_intersect($wanted, array_column($options, 'value')) !== [];
        $other = !$matched && !empty($field['other']) && $scalar !== '' && $scalar !== '__other__';
        $otherText = $value === '__other__' || $other ? ($state['values'][$name . '_other'] ?? ($other ? $scalar : '')) : '';
        $otherSelected = $other || $value === '__other__';
        $html = '<select' . bbf_attrs($base + ['class' => 'bbf-input'] + $attrs) . '>';
        $placeholderSelected = !$matched && !$otherSelected;
        if (is_scalar($field['placeholder'] ?? null) && $field['placeholder'] !== '') {
            $html .= '<option value="" disabled' . ($placeholderSelected ? ' selected' : '') . '>' . bbf_e($field['placeholder']) . '</option>';
        } elseif (empty($field['required']) || array_diff($wanted ?? [], ['']) === []) {
            // Optional, or nothing chosen yet: an explicit empty choice instead of a silently preselected first option.
            $html .= '<option value=""' . ($placeholderSelected ? ' selected' : '') . '>—</option>';
        } elseif ($placeholderSelected) {
            // As in bbf.js: a value matching no option leaves the select empty (so "required" catches it).
            $html .= '<option value="" hidden disabled selected></option>';
        }
        foreach ($options as $option) {
            $html .= '<option' . bbf_attrs(['value' => $option['value'], 'selected' => $wanted !== null && in_array($option['value'], $wanted, true),
                'data-option-show-if' => $option['show_if'], 'data-bbf-stored' => $option['stored']]) . '>' . bbf_e($option['label']) . '</option>';
        }
        if (!empty($field['other'])) {
            $html .= '<option value="__other__"' . ($otherSelected ? ' selected' : '') . '>' . bbf_e($field['other_label'] ?? bbf_t('optionOther')) . '</option>';
        }
        $html .= '</select>';
        if (!empty($field['other'])) $html .= bbf_render_other_input($field, $otherText, $otherSelected, '6px');
        return $html;
    }

    if ($type === 'radio' || $type === 'checkbox') {
        $options = bbf_render_options($field);
        $wanted = $value === null ? [] : array_map('strval', array_filter(is_array($value) ? $value : [$value], 'is_scalar'));
        $known = array_column($options, 'value');
        $otherValues = array_values(array_diff($wanted, $known, ['__other__']));
        $otherChecked = !empty($field['other']) && (in_array('__other__', $wanted, true) || $otherValues !== []);
        $otherText = $state['values'][$name . '_other'] ?? ($otherValues[0] ?? '');
        $columns = $field['columns'] ?? null;
        $class = 'bbf-options' . match (true) {
            $columns === 2 => ' bbf-columns-2',
            $columns === 3 => ' bbf-columns-3',
            $columns === 'inline' || $columns === 4 => ' bbf-columns-inline',
            default => '',
        };
        $html = '<div' . bbf_attrs(['class' => $class, 'role' => 'group', 'aria-describedby' => $attrs['aria-describedby']]) . '>';
        $first = true;
        foreach ($options as $i => $option) {
            $checked = $value === null ? $option['checked'] : in_array($option['value'], $wanted, true);
            $html .= '<label' . bbf_attrs(['class' => 'bbf-option', 'data-option-show-if' => $option['show_if']]) . '>'
                . '<input' . bbf_attrs(['type' => $type, 'name' => $ctx['name'], 'id' => $ctx['id'] . '-' . $i, 'value' => $option['value'],
                    'checked' => $checked, 'data-bbf-stored' => $option['stored'], 'aria-invalid' => $first ? ($attrs['aria-invalid'] ?? null) : null]) . '>'
                . '<span>' . bbf_e($option['label']) . '</span></label>';
            $first = false;
        }
        if (!empty($field['other'])) {
            $html .= '<label class="bbf-option bbf-option-other"><input' . bbf_attrs(['type' => $type, 'name' => $ctx['name'],
                    'id' => $ctx['id'] . '-other', 'value' => '__other__', 'checked' => $otherChecked]) . '>'
                . '<span>' . bbf_e($field['other_label'] ?? bbf_t('optionOther')) . '</span></label>'
                . bbf_render_other_input($field, is_scalar($otherText) ? (string)$otherText : '', $otherChecked, '4px');
        }
        return $html . '</div>';
    }

    if ($type === 'hidden') {
        return '<input' . bbf_attrs(['type' => 'hidden', 'name' => $name, 'value' => $scalar]) . '>';
    }

    if ($type === 'file') {
        $accept = array_map(static fn($ext) => '.' . ltrim((string)$ext, '.'), is_array($field['accept'] ?? null) ? $field['accept'] : []);
        $files = bbf_render_existing_files($value);
        $html = '<input' . bbf_attrs(['type' => 'file'] + $base + ['class' => 'bbf-input bbf-file-input',
            'accept' => $accept ? implode(',', $accept) : null, 'multiple' => bbf_uploads_field_max_files($field) > 1,
            // An existing file satisfies "required"; the browser must not insist on a new one.
            'required' => !empty($field['required']) && !$files,
            'aria-describedby' => $attrs['aria-describedby'], 'aria-invalid' => $attrs['aria-invalid'] ?? null]) . '>';
        if ($files) {
            $html .= '<div class="bbf-file-current">';
            foreach ($files as $file) {
                $html .= $file['image']
                    ? '<a class="bbf-file-link" href="' . bbf_e($file['url']) . '"><img class="bbf-file-preview" src="' . bbf_e($file['url']) . '" alt="' . bbf_e($file['name']) . '"></a>'
                    : '<a class="bbf-file-link" href="' . bbf_e($file['url']) . '">' . bbf_e($file['name']) . '</a>';
            }
            $removeName = count($files) === 1 ? $files[0]['name'] : ($field['label'] ?? $name);
            $html .= '<label class="bbf-option bbf-file-remove-existing"><input' . bbf_attrs(['type' => 'checkbox', 'name' => $name . '__remove',
                'id' => $ctx['id'] . '-remove', 'value' => '1', 'checked' => in_array((string)($state['values'][$name . '__remove'] ?? ''), ['1', 'on'], true)]) . '>'
                . '<span>' . bbf_e(bbf_t('fileRemove', ['name' => $removeName])) . '</span></label></div>';
        }
        return $html . '<ul class="bbf-file-list" id="' . bbf_e($ctx['id']) . '-files"></ul>'
            . '<div class="bbf-file-status" role="status" aria-live="polite"></div>';
    }

    if ($type === 'group') {
        throw new BbfFormException("Field '$name': bbf_render_html() does not draw repeatable groups; use templates.control or bbf.js.");
    }
    throw new BbfFormException("Field '$name': bbf_render_html() cannot draw type " . (is_string($type) ? $type : '?') . '; use templates.control or bbf.js.');
}

/** The field wrapper bbf.js builds: label (legend for radio/checkbox), description, control, error. */
function bbf_render_wrap(array $field, string $control, array $ctx, array $condition): string {
    $type = $field['type'] ?? 'text';
    $name = $field['name'];
    if ($type === 'hidden') {
        return '<div' . bbf_attrs(['class' => 'bbf-field bbf-field-hidden', 'data-field' => $name, 'style' => 'display:none']) . '>' . $control . '</div>';
    }
    $group = $type === 'radio' || $type === 'checkbox';
    $size = in_array($field['size'] ?? null, ['small', 'medium'], true) && $type !== 'file' ? 'bbf-size-' . $field['size'] : null;
    $class = bbf_render_class([
        $group ? "bbf-fieldset bbf-field bbf-field-$type" : 'bbf-field bbf-field-' . (is_string($type) ? $type : 'text'),
        $size,
        $field['css_class'] ?? null,
        !$group && $type !== 'file' && ($field['label_position'] ?? null) === 'left' ? 'bbf-label-left' : null,
        $ctx['error'] !== null ? 'bbf-has-error' : null,
    ]);
    $tag = $group ? 'fieldset' : 'div';
    $html = "<$tag" . bbf_attrs(['class' => $class, 'data-field' => $name] + $condition) . '>';
    if (is_scalar($field['label'] ?? null) && $field['label'] !== '') {
        $required = !empty($field['required']) ? '<span class="bbf-required"> *</span>' : '';
        $html .= $group
            ? '<legend class="bbf-label">' . bbf_e($field['label']) . $required . '</legend>'
            : '<label class="bbf-label" for="' . bbf_e($ctx['id']) . '">' . bbf_e($field['label']) . $required . '</label>';
    }
    if (is_scalar($field['description'] ?? null) && $field['description'] !== '') {
        $html .= '<small class="bbf-field-desc" id="' . bbf_e($ctx['id']) . '-desc">' . bbf_e($field['description']) . '</small>';
    }
    $html .= $control;
    $html .= '<div class="bbf-field-error" id="' . bbf_e($ctx['id']) . '-error" role="alert">' . bbf_e($ctx['error'] ?? '') . '</div>';
    return $html . "</$tag>";
}

function bbf_render_class(array $parts): string {
    return implode(' ', array_filter($parts, static fn($part) => is_string($part) && $part !== ''));
}

/** Options as [value, label, show_if (JSON or null), checked, stored (kept from values, see bbf_keep_stored_options())]. */
function bbf_render_options(array $field): array {
    $out = [];
    foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $option) {
        if (is_array($option)) {
            if (!is_scalar($option['value'] ?? null)) continue;
            $out[] = ['value' => (string)$option['value'], 'label' => (string)(is_scalar($option['label'] ?? null) ? $option['label'] : $option['value']),
                'show_if' => !empty($option['show_if']) ? json_encode($option['show_if'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'checked' => !empty($option['checked']), 'stored' => !empty($option['_bbf_stored'])];
        } elseif (is_scalar($option)) {
            $out[] = ['value' => (string)$option, 'label' => (string)$option, 'show_if' => null, 'checked' => false, 'stored' => false];
        }
    }
    return $out;
}

/** The "Other…" text input, shown only while its option is chosen. */
function bbf_render_other_input(array $field, string $text, bool $shown, string $margin): string {
    $label = $field['other_label'] ?? bbf_t('optionOther');
    return '<input' . bbf_attrs(['type' => 'text', 'name' => $field['name'] . '_other', 'class' => 'bbf-input bbf-other-input',
        'value' => $text, 'placeholder' => ($field['type'] ?? '') === 'select' ? $label : null, 'aria-label' => $label,
        'style' => ($shown ? '' : 'display:none;') . "margin-top:$margin"]) . '>';
}

/**
 * A file field's current value as a list of [url, name, image]. Only http(s) and relative URLs are linked, so a
 * stored "javascript:" URL can never become a link.
 */
function bbf_render_existing_files(mixed $value): array {
    if (!is_array($value) || $value === []) return [];
    $list = array_is_list($value) ? $value : [$value];
    $out = [];
    foreach ($list as $file) {
        $url = is_array($file) && is_string($file['url'] ?? null) ? trim($file['url']) : '';
        if ($url === '' || (preg_match('/\A[a-z][a-z0-9+.-]*:/i', $url) && !preg_match('/\Ahttps?:/i', $url))) continue;
        $name = is_scalar($file['name'] ?? null) && (string)$file['name'] !== '' ? (string)$file['name'] : basename((string)parse_url($url, PHP_URL_PATH));
        $ext = strtolower(pathinfo($name !== '' ? $name : (string)parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        $out[] = ['url' => $url, 'name' => $name, 'image' => in_array($ext, BBF_RENDER_IMAGE_EXTENSIONS, true)];
    }
    return $out;
}
