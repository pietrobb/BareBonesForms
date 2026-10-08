<?php
/**
 * BareBonesForms — form library without side effects (2.2.0).
 *
 * Loading a definition, validating and normalizing input, checking uploaded files and resolving
 * options. No session, storage, e-mail, webhooks or configuration: submit.php uses these functions
 * for the standalone mode, and a host application can require this file alone (embedded mode,
 * docs/EMBEDDED.md). Only definitions; nothing runs when the file is loaded.
 */

// Safe string length (mbstring optional, falls back to strlen)
function bbf_strlen(string $s): int {
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
}

// Resolve template references ("use" + "prefix" on group fields).
// Clones template fields, prefixes their names, and adjusts internal show_if references.
function bbf_resolve_templates(array $fields, array $templates): array {
    $result = [];
    foreach ($fields as $field) {
        $type = $field['type'] ?? 'text';
        if ($type === 'group' && !empty($field['use']) && isset($templates[$field['use']])) {
            $prefix = $field['prefix'] ?? '';
            $tplFields = json_decode(json_encode($templates[$field['use']]), true);
            $tplNames = [];
            $pendingFields = $tplFields;
            while ($pendingFields) {
                $templateField = array_pop($pendingFields);
                $tplNames[$templateField['name']] = true;
                foreach ($templateField['fields'] ?? [] as $nestedField) {
                    $pendingFields[] = $nestedField;
                }
            }
            $field['fields'] = bbf_prefix_fields($tplFields, $prefix, $tplNames);
            unset($field['use'], $field['prefix']);
        } elseif ($type === 'group' && !empty($field['fields'])) {
            $field['fields'] = bbf_resolve_templates($field['fields'], $templates);
        }
        $result[] = $field;
    }
    return $result;
}

function bbf_prefix_fields(array $fields, string $prefix, array $tplNames): array {
    $result = [];
    foreach ($fields as $field) {
        $field['name'] = $prefix . $field['name'];
        if (!empty($field['show_if'])) {
            $field['show_if'] = bbf_prefix_condition($field['show_if'], $prefix, $tplNames);
        }
        if (is_array($field['options'] ?? null)) {
            foreach ($field['options'] as &$option) {
                if (is_array($option) && !empty($option['show_if'])) {
                    $option['show_if'] = bbf_prefix_condition($option['show_if'], $prefix, $tplNames);
                }
            }
            unset($option);
        }
        if (($field['type'] ?? '') === 'group' && !empty($field['fields'])) {
            $field['fields'] = bbf_prefix_fields($field['fields'], $prefix, $tplNames);
        }
        $result[] = $field;
    }
    return $result;
}

function bbf_prefix_condition(mixed $cond, string $prefix, array $tplNames): mixed {
    if (!is_array($cond)) return $cond; // malformed: left for bbf_definition_errors() to report
    if (bbfConditionList($cond['all'] ?? null)) {
        $cond['all'] = array_map(fn($c) => bbf_prefix_condition($c, $prefix, $tplNames), $cond['all']);
        return $cond;
    }
    if (bbfConditionList($cond['any'] ?? null)) {
        $cond['any'] = array_map(fn($c) => bbf_prefix_condition($c, $prefix, $tplNames), $cond['any']);
        return $cond;
    }
    if (is_string($cond['field'] ?? null) && isset($tplNames[$cond['field']])) {
        $cond['field'] = $prefix . $cond['field'];
    }
    return $cond;
}

// Flatten nested group fields into a single array.
// Propagates all ancestor show_if conditions so hidden groups skip their children server-side.
function bbf_flatten_fields(array $fields, ?array $parentShowIf = null): array {
    $result = [];
    foreach ($fields as $f) {
        $localShowIf = !empty($f['show_if']) && is_array($f['show_if']) ? $f['show_if'] : null;
        if ($parentShowIf && $localShowIf) {
            $f['show_if'] = ['all' => [$parentShowIf, $localShowIf]];
        } elseif ($parentShowIf) {
            $f['show_if'] = $parentShowIf;
        }
        $result[] = $f;
        if (($f['type'] ?? '') === 'group' && empty($f['repeatable']) && !empty($f['fields'])) {
            $groupShowIf = !empty($f['show_if']) && is_array($f['show_if']) ? $f['show_if'] : null;
            foreach (bbf_flatten_fields($f['fields'], $groupShowIf) as $child) {
                $result[] = $child;
            }
        }
    }
    return $result;
}

function bbfNormalizeInputValue($value, string $type = '') {
    if (is_array($value)) return $value;
    $value = trim((string)$value);
    if ($type === 'tel') $value = preg_replace('/\A[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+|[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+\z/u', '', $value) ?? $value;
    return $value;
}

// Evaluate a show_if condition against submitted data.
// Mirrors the client-side _evalCondition / _compareValues logic. A malformed condition ("all": {…}, a string,
// a non-string op) is ignored like bbf.js ignores it, never a 500; bbf_definition_errors() reports its shape.
function bbf_eval_condition(mixed $cond, array $input): bool {
    if (!is_array($cond)) return true;
    if (bbfConditionList($cond['all'] ?? null)) {
        foreach ($cond['all'] as $c) {
            if (!bbf_eval_condition($c, $input)) return false;
        }
        return true;
    }
    if (bbfConditionList($cond['any'] ?? null)) {
        foreach ($cond['any'] as $c) {
            if (bbf_eval_condition($c, $input)) return true;
        }
        return false;
    }
    $field = $cond['field'] ?? null;
    if ((is_string($field) || is_int($field)) && !empty($field)) {
        $val = bbfNormalizeInputValue($input[(string)$field] ?? '');
        $target = $cond['value'] ?? null;
        if (is_array($target)) $target = array_values(array_filter($target, 'is_scalar'));
        elseif (!is_scalar($target)) $target = null;
        return bbf_compare_values($val, $target, is_string($cond['op'] ?? null) ? $cond['op'] : '');
    }
    return true;
}

/** all/any apply only as a non-empty JSON list, as in bbf.js (Array.isArray && length). */
function bbfConditionList(mixed $list): bool {
    return is_array($list) && $list !== [] && array_is_list($list);
}

/**
 * Shape errors of a show_if condition: {field, op?, value?} or {all: [...]} / {any: [...]}. Only shapes that broke
 * submissions (500) or that bbf.js silently ignores are errors; an unknown op still means "equals" on both sides,
 * so forms that work today keep working after an upgrade.
 */
function bbfConditionErrors(mixed $cond, string $path, int $depth = 0): array {
    if ($depth === 0 && empty($cond)) return []; // "show_if": null / false / {} means no condition, as before
    if (!is_array($cond) || ($cond !== [] && array_is_list($cond))) return ["$path: Expected an object {field, op, value} or {all: [...]} / {any: [...]}."];
    if ($depth > 20) return ["$path: Conditions are nested too deeply."];
    // A key set to null counts as missing: generators and editors write null for "not set" (2.1.4 accepted it).
    $cond = array_filter($cond, static fn($v) => $v !== null);
    $errors = [];
    foreach (['all', 'any'] as $key) {
        if (!array_key_exists($key, $cond)) continue;
        if (!is_array($cond[$key]) || !array_is_list($cond[$key])) {
            $errors[] = "$path.$key: Expected a list of conditions, e.g. \"$key\": [{\"field\": \"…\", \"value\": \"…\"}].";
            continue;
        }
        foreach ($cond[$key] as $i => $sub) array_push($errors, ...bbfConditionErrors($sub, "$path.{$key}[$i]", $depth + 1));
    }
    if (array_key_exists('all', $cond) || array_key_exists('any', $cond)) return $errors;
    if (array_key_exists('field', $cond) && !is_string($cond['field']) && !is_int($cond['field'])) {
        $errors[] = "$path.field: Expected the name of a field.";
    }
    if (array_key_exists('op', $cond) && !is_string($cond['op'])) {
        $errors[] = "$path.op: Expected a string: not, contains, empty, not_empty, gt, gte, lt or lte.";
    }
    // An object or nested list is compared differently by bbf.js and the server, so the field would show in the
    // browser but be treated as hidden on submit (or the other way round).
    $value = $cond['value'] ?? null;
    if (is_array($value) && (!array_is_list($value) || array_filter($value, static fn($v) => !is_scalar($v)) !== [])) {
        $errors[] = "$path.value: Expected text, a number, true/false or a list of them, e.g. \"value\": [\"a\", \"b\"].";
    }
    return $errors;
}

/**
 * Input as the respondent saw it: values of conditionally hidden fields are removed, and a hidden
 * field counts as empty in every other condition, so chains (A → B → C) settle the same way as in
 * bbf.js (_conditionalHidden). A spoofed value for a hidden field can therefore not hide or show
 * anything else. $flatFields must come from bbf_flatten_fields() (ancestor conditions propagated).
 */
function bbfVisibleInput(array $flatFields, array $input): array {
    $conditional = array_values(array_filter($flatFields, static fn($field) => is_array($field)
        && !empty($field['show_if']) && is_array($field['show_if']) && is_string($field['name'] ?? null)));
    $hidden = [];
    for ($pass = 0; $pass <= count($conditional); $pass++) {
        $changed = false;
        foreach ($conditional as $field) {
            $visible = bbf_eval_condition($field['show_if'], array_diff_key($input, $hidden));
            if ($visible === isset($hidden[$field['name']])) {
                if ($visible) unset($hidden[$field['name']]); else $hidden[$field['name']] = true;
                $changed = true;
            }
        }
        if (!$changed) break;
    }
    return array_diff_key($input, $hidden);
}

function bbf_compare_values($currentVal, $targetVal, string $op): bool {
    if ($op === 'empty') {
        return is_array($currentVal) ? count($currentVal) === 0 : ($currentVal === '' || $currentVal === null);
    }
    if ($op === 'not_empty') {
        return is_array($currentVal) ? count($currentVal) > 0 : ($currentVal !== '' && $currentVal !== null);
    }

    $targets = is_array($targetVal) ? array_map('strval', $targetVal) : ($targetVal !== null ? [(string)$targetVal] : []);

    if ($op === 'contains') {
        if (is_array($currentVal)) {
            foreach ($targets as $t) {
                foreach ($currentVal as $v) {
                    if (str_contains((string)$v, $t)) return true;
                }
            }
            return false;
        }
        foreach ($targets as $t) {
            if (str_contains((string)$currentVal, $t)) return true;
        }
        return false;
    }

    if (in_array($op, ['gt', 'gte', 'lt', 'lte'], true)) {
        $num = is_numeric($currentVal) ? (float)$currentVal : null;
        $tgt = !empty($targets) && is_numeric($targets[0]) ? (float)$targets[0] : null;
        if ($num === null || $tgt === null) return false;
        return match($op) {
            'gt'  => $num > $tgt,
            'gte' => $num >= $tgt,
            'lt'  => $num < $tgt,
            'lte' => $num <= $tgt,
        };
    }

    if ($op === 'not') {
        if (is_array($currentVal)) {
            foreach ($currentVal as $v) {
                if (in_array((string)$v, $targets, true)) return false;
            }
            return true;
        }
        return !in_array((string)$currentVal, $targets, true);
    }

    // Default: equals
    if (is_array($currentVal)) {
        foreach ($currentVal as $v) {
            if (in_array((string)$v, $targets, true)) return true;
        }
        return false;
    }
    return in_array((string)$currentVal, $targets, true);
}

// ─── Definitions ────────────────────────────────────────────────

function bbf_definition_errors(array $form): array {
    $errors = [];

    if (isset($form['schema_version']) && $form['schema_version'] !== 1) {
        $errors[] = "Unsupported schema_version: {$form['schema_version']}. Expected: 1.";
    }

    if (empty($form['id'])) {
        $errors[] = 'Missing required property: id.';
    } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $form['id'])) {
        $errors[] = 'Invalid id format. Use only a-z, 0-9, hyphens, underscores.';
    }

    if (empty($form['fields']) || !is_array($form['fields'])) {
        $errors[] = 'Missing or empty required property: fields.';
        return $errors;
    }

    // Resolve templates before validating field list
    $fieldsToValidate = $form['fields'];
    if (!empty($form['templates'])) {
        $fieldsToValidate = bbf_resolve_templates($fieldsToValidate, $form['templates']);
    }

    $fieldNames = [];
    bbf_definition_field_errors($fieldsToValidate, 'fields', $errors, $fieldNames);
    $declaredNames = [];
    foreach ($fieldNames as $fieldName) if (is_string($fieldName)) $declaredNames[$fieldName] = true;
    $checkOtherNames = function (array $fields) use (&$checkOtherNames, $declaredNames, &$errors): void {
        foreach ($fields as $field) {
            if (!is_array($field)) continue;
            if (!empty($field['other']) && is_string($field['name'] ?? null)) {
                $otherName = $field['name'] . '_other';
                if (isset($declaredNames[$otherName])) {
                    $errors[] = "Generated Other companion name collides with declared field: $otherName.";
                }
            }
            if (($field['type'] ?? '') === 'group' && is_array($field['fields'] ?? null)) {
                $checkOtherNames($field['fields']);
            }
        }
    };
    $checkOtherNames($fieldsToValidate);

    if (isset($form['validations'])) {
        if (!is_array($form['validations']) || !array_is_list($form['validations'])) {
            $errors[] = 'validations: Expected a list.';
        } else {
            foreach ($form['validations'] as $index => $rule) {
                $prefix = "validations[$index]";
                if (!is_array($rule) || array_is_list($rule)) {
                    $errors[] = "$prefix: Expected an object.";
                    continue;
                }
                if (!in_array($rule['type'] ?? null, ['min_sum', 'min_filled'], true)) {
                    $errors[] = "$prefix.type: Expected min_sum or min_filled.";
                }
                $names = $rule['fields'] ?? null;
                if (!is_array($names) || !array_is_list($names) || $names === []) {
                    $errors[] = "$prefix.fields: Expected a non-empty list.";
                } else {
                    foreach ($names as $fieldIndex => $name) {
                        if (!is_string($name) || !in_array($name, $fieldNames, true)) {
                            $errors[] = "$prefix.fields[$fieldIndex]: Unknown form field.";
                        }
                    }
                }
                $minimum = $rule['min'] ?? 1;
                if (!is_int($minimum) && !is_float($minimum) || $minimum < 0) {
                    $errors[] = "$prefix.min: Expected a non-negative number.";
                }
                if (isset($rule['message']) && !is_string($rule['message'])) {
                    $errors[] = "$prefix.message: Expected string.";
                }
            }
        }
    }

    return $errors;
}

function bbf_definition_field_errors(array $fields, string $path, array &$errors, array &$fieldNames, bool $insideRepeatable = false): void {
    $validTypes = ['text', 'email', 'tel', 'url', 'number', 'date', 'textarea', 'select', 'radio', 'checkbox', 'hidden', 'password', 'section', 'page_break', 'rating', 'group', 'file'];

    foreach ($fields as $i => $field) {
        $prefix = "{$path}[{$i}]";

        if (empty($field['name'])) {
            $errors[] = "$prefix: Missing required property: name.";
            continue;
        }

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $field['name'])) {
            $errors[] = "$prefix: Invalid name format: {$field['name']}.";
        }

        if (in_array($field['name'], $fieldNames, true)) {
            $errors[] = "$prefix: Duplicate field name: {$field['name']}.";
        }
        $fieldNames[] = $field['name'];

        $type = $field['type'] ?? 'text';
        if (is_string($type) && str_starts_with($type, 'x-')) {
            // Custom type (embedded mode): the host registers it; without a handler it is a definition error.
            if (bbf_custom_type($type) === null) {
                $errors[] = "$prefix: Custom type $type has no registered handler (bbf_register_type).";
            }
        } elseif (!in_array($type, $validTypes, true)) {
            $errors[] = "$prefix: Invalid type: $type.";
        }
        if (isset($field['sensitive']) && !is_bool($field['sensitive'])) {
            $errors[] = "$prefix.sensitive: Expected boolean.";
        }
        if (array_key_exists('show_if', $field)) array_push($errors, ...bbfConditionErrors($field['show_if'], "$prefix.show_if"));
        foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $o => $option) {
            if (is_array($option) && array_key_exists('show_if', $option)) {
                array_push($errors, ...bbfConditionErrors($option['show_if'], "$prefix.options[$o].show_if"));
            }
        }

        // Layout-only types — skip further validation
        if (in_array($type, ['section', 'page_break'], true)) continue;

        // Group — recurse into children and validate bounded repeatable rows.
        if ($type === 'group') {
            $repeatable = $field['repeatable'] ?? false;
            if (!is_bool($repeatable)) {
                $errors[] = "$prefix.repeatable: Expected boolean.";
                $repeatable = false;
            }
            if ($repeatable && $insideRepeatable) {
                $errors[] = "$prefix.repeatable: Nested repeatable groups are not supported.";
            }
            if ($repeatable) {
                $minItems = $field['min_items'] ?? 1;
                $maxItems = $field['max_items'] ?? 10;
                if (!is_int($minItems) || $minItems < 0 || $minItems > 100) {
                    $errors[] = "$prefix.min_items: Expected an integer from 0 through 100.";
                }
                if (!is_int($maxItems) || $maxItems < 1 || $maxItems > 100) {
                    $errors[] = "$prefix.max_items: Expected an integer from 1 through 100.";
                }
                if (is_int($minItems) && is_int($maxItems) && $minItems > $maxItems) {
                    $errors[] = "$prefix: min_items cannot exceed max_items.";
                }
            } elseif (array_key_exists('min_items', $field) || array_key_exists('max_items', $field)
                || array_key_exists('add_label', $field) || array_key_exists('remove_label', $field)) {
                $errors[] = "$prefix: Repeatable controls require repeatable: true.";
            }
            foreach (['add_label', 'remove_label'] as $labelKey) {
                if (isset($field[$labelKey]) && !is_string($field[$labelKey])) {
                    $errors[] = "$prefix.$labelKey: Expected string.";
                }
            }
            if ($repeatable && (empty($field['fields']) || !is_array($field['fields']))) {
                $errors[] = "$prefix: Repeatable group requires non-empty fields.";
            } elseif (!empty($field['fields']) && is_array($field['fields'])) {
                bbf_definition_field_errors($field['fields'], "$prefix.fields", $errors, $fieldNames, $insideRepeatable || $repeatable);
            }
            continue;
        }
        if (array_key_exists('repeatable', $field) || array_key_exists('min_items', $field) || array_key_exists('max_items', $field)
            || array_key_exists('add_label', $field) || array_key_exists('remove_label', $field)) {
            $errors[] = "$prefix: Repeatable properties require type 'group'.";
        }

        if ($type === 'file') {
            array_push($errors, ...bbf_uploads_definition_errors($field, $prefix, $insideRepeatable));
        } elseif (array_key_exists('accept', $field) || array_key_exists('max_size', $field) || array_key_exists('max_files', $field)) {
            $errors[] = "$prefix: accept, max_size and max_files require type 'file'.";
        }

        if (in_array($type, ['select', 'radio', 'checkbox'], true) && empty($field['options']) && empty($field['options_from'])) {
            $errors[] = "$prefix: Type '$type' requires options or options_from.";
        }

        // Validate regex patterns
        if (!empty($field['pattern'])) {
            if (!is_string($field['pattern']) || bbfFieldPatternRegex($field['pattern']) === null) {
                $errors[] = "$prefix: Invalid regex pattern: {$field['pattern']}";
            }
        }
    }
}

/** True when every string key and value (recursively) is valid UTF-8. */
function bbfValidUtf8Deep(mixed $value): bool {
    if (is_string($value)) return preg_match('//u', $value) === 1;
    if (!is_array($value)) return true;
    foreach ($value as $key => $item) {
        if ((is_string($key) && preg_match('//u', $key) !== 1) || !bbfValidUtf8Deep($item)) return false;
    }
    return true;
}

/**
 * Compile a field "pattern" (written like a JS RegExp source) into a delimited PCRE.
 * Unescaped "/" is escaped so it cannot end the pattern early. (*UTF) matches the client's
 * RegExp(pattern, 'u'): characters, not bytes, while \d, \w and \b stay ASCII as in JavaScript.
 * PHP's /u flag would also turn on Unicode properties, so \d would accept Arabic digits.
 * Returns null when the pattern is not a valid regex.
 */
function bbfFieldPatternRegex(string $pattern): ?string {
    $body = preg_replace('~(?<!\\\\)((?:\\\\\\\\)*)/~', '$1\\/', $pattern);
    $regex = '/(*UTF)' . bbfJsWhitespaceClasses($body) . '/';
    if (@preg_match($regex, '') !== false) return $regex;
    $regex = '/' . $body . '/';
    return @preg_match($regex, '') !== false ? $regex : null;
}

/**
 * JavaScript's \s also matches Unicode spaces (NBSP, U+2000–U+200A, U+3000, BOM …); PCRE's \s without
 * Unicode properties matches only ASCII whitespace. Spell the JavaScript set out so "Jana Nová" typed with
 * a no-break space passes on both sides. \S outside a character class becomes the complement. A class cannot
 * contain a negated class, so a class with \S is rewritten as an equivalent group:
 * [x\S] → (?:[x]|[^space]) and [^x\S] (e.g. [^\S\r\n], "whitespace but no line break") → (?:(?![x])[space]).
 */
function bbfJsWhitespaceClasses(string $body): string {
    $space = '\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';
    $out = '';
    $len = strlen($body);
    for ($i = 0; $i < $len; $i++) {
        $c = $body[$i];
        if ($c === '\\' && $i + 1 < $len) {
            $n = $body[++$i];
            $out .= match ($n) { 's' => "[$space]", 'S' => "[^$space]", default => '\\' . $n };
            continue;
        }
        if ($c !== '[') { $out .= $c; continue; }
        $negated = $i + 1 < $len && $body[$i + 1] === '^';
        $items = '';
        $hasNotSpace = false;
        $j = $i + ($negated ? 2 : 1);
        for (; $j < $len && $body[$j] !== ']'; $j++) {
            if ($body[$j] === '\\' && $j + 1 < $len) {
                $n = $body[++$j];
                if ($n === 'S') $hasNotSpace = true;
                else $items .= $n === 's' ? $space : '\\' . $n;
            } else {
                $items .= $body[$j];
            }
        }
        if ($j >= $len) return $body; // unterminated class: leave it to the regex compiler to reject
        $i = $j;
        if (!$hasNotSpace) $out .= '[' . ($negated ? '^' : '') . $items . ']';
        elseif ($items === '') $out .= $negated ? "[$space]" : "[^$space]";
        else $out .= $negated ? "(?:(?![$items])[$space])" : "(?:[$items]|[^$space])";
    }
    return $out;
}

function bbfRepeatableRowInput(array $fields, array $input, array $row): array {
    foreach ($fields as $field) {
        $type = $field['type'] ?? 'text';
        if (in_array($type, ['section', 'page_break', 'group'], true)) continue;
        $name = $field['name'] ?? '';
        if (!is_string($name) || $name === '') continue;
        unset($input[$name]);
        if (!empty($field['other'])) unset($input[$name . '_other']);
        if ($type === 'email' && !empty($field['confirm'])) unset($input[$name . '_confirm']);
    }
    return array_replace($input, $row);
}

function bbf_validate_shapes(array $fields, array $input): array {
    $errors = [];
    foreach ($fields as $field) {
        $type = $field['type'] ?? 'text';
        if (in_array($type, ['section', 'page_break'], true)) continue;

        $name = $field['name'];
        if ($type === 'group') {
            if (empty($field['repeatable'])) continue;
            $rows = $input[$name] ?? [];
            $valid = is_array($rows) && array_is_list($rows);
            $childFields = bbf_flatten_fields(is_array($field['fields'] ?? null) ? $field['fields'] : []);
            $allowedKeys = [];
            foreach ($childFields as $child) {
                $childType = $child['type'] ?? 'text';
                if (in_array($childType, ['section', 'page_break', 'group'], true)) continue;
                $childName = $child['name'] ?? '';
                if (!is_string($childName) || $childName === '') continue;
                $allowedKeys[$childName] = true;
                if (!empty($child['other'])) $allowedKeys[$childName . '_other'] = true;
                if ($childType === 'email' && !empty($child['confirm'])) $allowedKeys[$childName . '_confirm'] = true;
            }
            if ($valid) {
                foreach ($rows as $row) {
                    if (!is_array($row) || ($row !== [] && array_is_list($row))
                        || array_diff_key($row, $allowedKeys)
                        || bbf_validate_shapes($childFields, bbfRepeatableRowInput($childFields, $input, $row))) {
                        $valid = false;
                        break;
                    }
                }
            }
            if (!$valid) $errors[$name] = bbf_t('invalidFormat', ['label' => $field['label'] ?? $name]);
            continue;
        }
        if (bbf_is_custom_type($type)) continue; // structured values; the registered validate handler checks them
        // The client sends a single selection as a scalar, repeated selections as an array.
        $multi = $type === 'checkbox' || $type === 'file' || ($type === 'select' && !empty($field['multiple']));
        $inputs = [$name => $multi];
        if (!empty($field['other'])) $inputs[$name . '_other'] = false;
        if ($type === 'email' && !empty($field['confirm'])) $inputs[$name . '_confirm'] = false;

        foreach ($inputs as $key => $allowsArray) {
            $raw = $input[$key] ?? '';
            $valid = is_scalar($raw);
            if (is_array($raw) && $allowsArray) {
                $valid = true;
                foreach ($raw as $item) {
                    if (!is_scalar($item)) {
                        $valid = false;
                        break;
                    }
                }
            }
            if (!$valid) {
                $errors[$name] = bbf_t('invalidFormat', ['label' => $field['label'] ?? $name]);
            }
        }
    }
    return $errors;
}

function bbf_validate_fields(array $fields, array $input): array {
    // Check all data shapes before conditions, casts, or optional/hidden-field skips.
    $errors = bbf_validate_shapes($fields, $input);
    if ($errors) return $errors;
    $input = bbfVisibleInput($fields, $input);
    foreach ($fields as $field) {
        $type = $field['type'] ?? 'text';

        // Skip non-data field types; repeatable groups are structured data fields.
        if (in_array($type, ['section', 'page_break'], true)) continue;

        $name  = $field['name'];
        if ($type === 'group') {
            if (empty($field['repeatable'])) continue;
            if (!empty($field['show_if']) && !bbf_eval_condition($field['show_if'], $input)) continue;
            $rows = $input[$name] ?? [];
            $count = count($rows);
            $minItems = $field['min_items'] ?? 1;
            $maxItems = $field['max_items'] ?? 10;
            $label = $field['label'] ?? $field['title'] ?? $name;
            if ($count < $minItems) {
                $errors[$name] = bbf_t('repeatableMin', ['label' => $label, 'min' => $minItems]);
                continue;
            }
            if ($count > $maxItems) {
                $errors[$name] = bbf_t('repeatableMax', ['label' => $label, 'max' => $maxItems]);
                continue;
            }
            $childFields = bbf_flatten_fields($field['fields'] ?? []);
            foreach ($rows as $index => $row) {
                foreach (bbf_validate_fields($childFields, bbfRepeatableRowInput($childFields, $input, $row)) as $childName => $message) {
                    $errors[$name . '.' . $index . '.' . $childName] = $message;
                }
            }
            continue;
        }

        // Skip conditionally hidden fields — evaluate the condition server-side
        if (!empty($field['show_if']) && !bbf_eval_condition($field['show_if'], $input)) {
            continue;
        }

        $raw   = $input[$name] ?? '';
        $value = bbfNormalizeInputValue($raw, $type);
        $label = $field['label'] ?? $name;

        // Custom types get the raw value: a name[] array stays an array.
        if (bbf_is_custom_type($type)) {
            if (bbfCustomValueEmpty($raw)) {
                if (!empty($field['required'])) $errors[$name] = bbf_t('required', ['label' => $label]);
                continue;
            }
            $handler = bbf_custom_type($type);
            $message = is_callable($handler['validate'] ?? null) ? ($handler['validate'])($raw, $field, $input) : null;
            if ($handler === null) $message = bbf_t('invalidFormat', ['label' => $label]);
            if (is_string($message) && $message !== '') $errors[$name] = $message;
            continue;
        }

        // Required
        if (!empty($field['required'])) {
            if ((is_array($value) && count($value) === 0) || (!is_array($value) && $value === '')) {
                $errors[$name] = bbf_t('required', ['label' => $label]);
                continue;
            }
        }

        // Optional and empty — skip further checks
        if (!is_array($value) && $value === '') continue;
        if (is_array($value) && count($value) === 0) continue;

        // File fields carry upload tokens; the files themselves are checked in the submit transaction.
        if ($type === 'file') {
            $tokens = is_array($value) ? $value : [$value];
            if (!array_is_list($tokens)) {
                $errors[$name] = bbf_t('invalidFormat', ['label' => $label]);
            } elseif (count($tokens) > bbf_uploads_field_max_files($field)) {
                $errors[$name] = bbf_t('tooManyFiles', ['label' => $label, 'max' => bbf_uploads_field_max_files($field)]);
            }
            continue;
        }

        // Type-based validation (only for scalar values)
        if (!is_array($value)) {
            switch ($type) {
                case 'email':
                    // Quoted local parts ("a,b@x,c"@example.com) are valid RFC 5322 but would be split
                    // into several recipients when used as an address list; no real respondent needs them.
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL) || preg_match('/[",;<>\s]/', $value)) {
                        $errors[$name] = bbf_t('invalidEmail', ['label' => $label]);
                    }
                    // Email confirmation
                    if (!empty($field['confirm'])) {
                        $confirmVal = trim((string)($input[$name . '_confirm'] ?? ''));
                        if ($value !== $confirmVal) {
                            $errors[$name] = bbf_t('emailMismatch', ['label' => $label]);
                        }
                    }
                    break;
                case 'url':
                    if (!filter_var($value, FILTER_VALIDATE_URL)) {
                        $errors[$name] = bbf_t('invalidUrl', ['label' => $label]);
                    }
                    break;
                case 'number':
                case 'rating':
                    $number = is_numeric($value) ? (float)$value : null;
                    if ($number === null || !is_finite($number)
                        || ($type === 'rating' && floor($number) !== $number)) {
                        $errors[$name] = bbf_t('invalidNumber', ['label' => $label]);
                        break;
                    }
                    $minimum = $type === 'rating' ? max(1, $field['min'] ?? 1) : ($field['min'] ?? null);
                    $maximum = $type === 'rating' ? ($field['max'] ?? 5) : ($field['max'] ?? null);
                    if ($minimum !== null && $number < $minimum) {
                        $errors[$name] = bbf_t('numberMin', ['label' => $label, 'min' => $minimum]);
                    }
                    if ($maximum !== null && $number > $maximum) {
                        $errors[$name] = bbf_t('numberMax', ['label' => $label, 'max' => $maximum]);
                    }
                    break;
                case 'tel':
                    if (!preg_match('/^[+]?[0-9\s\-().]{6,20}$/u', $value)) {
                        $errors[$name] = bbf_t('invalidTel', ['label' => $label]);
                    }
                    break;
                case 'date':
                    if (!preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/D', $value, $dateParts)
                        || !checkdate((int)$dateParts[2], (int)$dateParts[3], (int)$dateParts[1])) {
                        $errors[$name] = bbf_t('invalidFormat', ['label' => $label]);
                        break;
                    }
                    if (!empty($field['min']) && $value < $field['min']) {
                        $errors[$name] = bbf_t('dateMin', ['label' => $label, 'min' => $field['min']]);
                    }
                    if (!empty($field['max']) && $value > $field['max']) {
                        $errors[$name] = bbf_t('dateMax', ['label' => $label, 'max' => $field['max']]);
                    }
                    break;
            }

            // Pattern (regex)
            if (!empty($field['pattern']) && preg_match((string)bbfFieldPatternRegex((string)$field['pattern']), $value) !== 1) {
                $errors[$name] = $field['pattern_message'] ?? bbf_t('invalidFormat', ['label' => $label]);
            }

            // Min/max length
            if (isset($field['minlength']) && bbf_strlen($value) < $field['minlength']) {
                $errors[$name] = bbf_t('tooShort', ['label' => $label, 'min' => $field['minlength']]);
            }
            if (isset($field['maxlength']) && bbf_strlen($value) > $field['maxlength']) {
                $errors[$name] = bbf_t('tooLong', ['label' => $label, 'max' => $field['maxlength']]);
            }
        }

        // Options (select, radio, checkbox). options_from counts only with the options the server resolved
        // (bbf_resolve_options; static options are the fallback, as in bbf.js). A source the server could not
        // load leaves no valid value, so a forged one is refused instead of stored (before 2.2.0 it was accepted).
        if (!empty($field['options']) || !empty($field['options_from'])) {
            $field['options'] = is_array($field['options'] ?? null) ? $field['options'] : [];
            // Support both string options ["A","B"] and object options [{value:"a",label:"A"}]
            $validOptions = [];
            foreach ($field['options'] as $opt) {
                if (is_array($opt) && !empty($opt['show_if']) && !bbf_eval_condition($opt['show_if'], $input)) continue;
                $validOptions[] = is_array($opt) ? (string)($opt['value'] ?? '') : (string)$opt;
            }
            // Allow __other__ sentinel when field has "other": true
            if (!empty($field['other'])) {
                $validOptions[] = '__other__';
            }
            $selected = is_array($value) ? $value : [$value];
            foreach ($selected as $sel) {
                if (!in_array((string)$sel, $validOptions, true)) {
                    $errors[$name] = bbf_t('invalidOption', ['label' => $label]);
                }
            }
        }
    }
    return $errors;
}

function bbfCrossFieldFilled($value): bool {
    if (is_array($value)) {
        foreach ($value as $item) if (bbfCrossFieldFilled($item)) return true;
        return false;
    }
    return is_string($value) ? trim($value) !== '' : $value !== null;
}

function bbf_validate_cross(array $rules, array $data): array {
    $errors = [];
    foreach ($rules as $rule) {
        $fields = is_array($rule['fields'] ?? null) ? $rule['fields'] : [];
        $type = $rule['type'] ?? '';
        $minimum = $rule['min'] ?? 1;
        $valid = true;
        if ($type === 'min_sum') {
            $sum = 0.0;
            foreach ($fields as $name) {
                $value = $data[$name] ?? null;
                if (is_scalar($value) && is_numeric($value)) $sum += (float)$value;
            }
            $valid = $sum >= $minimum;
        } elseif ($type === 'min_filled') {
            $filled = 0;
            foreach ($fields as $name) {
                $value = $data[$name] ?? null;
                if (bbfCrossFieldFilled($value)) $filled++;
            }
            $valid = $filled >= $minimum;
        }
        if (!$valid) {
            $errors['_cross_' . implode('_', $fields)] = is_string($rule['message'] ?? null) && $rule['message'] !== '' ? $rule['message'] : bbf_t('crossFieldInvalid');
        }
    }
    return $errors;
}


function bbfCustomValueEmpty(mixed $raw): bool {
    return is_array($raw) ? $raw === [] : ($raw === null || trim((string)(is_scalar($raw) ? $raw : '')) === '');
}

/** Visible fields' values; $errors (from bbf_validate_fields) keeps a failed custom-type value from normalize. */
function bbf_collect_data(array $fields, array $input, array $errors = []): array {
    $data = [];
    $input = bbfVisibleInput($fields, $input);
    foreach ($fields as $field) {
        $type = $field['type'] ?? 'text';
        // Skip non-data fields; repeatable groups preserve structured rows.
        if (in_array($type, ['section', 'page_break'], true)) continue;

        $name = $field['name'];
        if ($type === 'group') {
            if (empty($field['repeatable'])) continue;
            if (!empty($field['show_if']) && !bbf_eval_condition($field['show_if'], $input)) continue;
            $childFields = bbf_flatten_fields($field['fields'] ?? []);
            $rows = [];
            foreach ($input[$name] ?? [] as $index => $row) {
                $rowErrors = [];
                foreach ($errors as $key => $message) {
                    if (str_starts_with((string)$key, "$name.$index.")) $rowErrors[substr((string)$key, strlen("$name.$index."))] = $message;
                }
                $rows[] = bbf_collect_data($childFields, bbfRepeatableRowInput($childFields, $input, $row), $rowErrors);
            }
            $data[$name] = $rows;
            continue;
        }

        // Skip conditionally hidden fields — evaluate the condition server-side
        if (!empty($field['show_if']) && !bbf_eval_condition($field['show_if'], $input)) {
            continue;
        }

        if (bbf_is_custom_type($type)) {
            if (isset($errors[$name])) continue; // a value that failed validate is never normalized
            $raw = $input[$name] ?? '';
            $normalize = bbf_custom_type($type)['normalize'] ?? null;
            $data[$name] = is_callable($normalize) && !bbfCustomValueEmpty($raw) ? $normalize($raw, $field) : $raw;
            continue;
        }
        $value = !empty($field['_bbf_system']) ? ($input[$name] ?? '') : bbfNormalizeInputValue($input[$name] ?? '', $type);

        // Resolve "other" option: if value is __other__, use the _other text field
        if (!empty($field['other']) && $value === '__other__') {
            $otherValue = trim((string)($input[$name . '_other'] ?? ''));
            $value = $otherValue !== '' ? $otherValue : 'Other';
        }
        // For checkbox arrays with __other__
        if (is_array($value) && !empty($field['other'])) {
            $value = array_map(function($v) use ($input, $name) {
                if ($v === '__other__') {
                    $ov = trim((string)($input[$name . '_other'] ?? ''));
                    return $ov !== '' ? $ov : 'Other';
                }
                return $v;
            }, $value);
        }

        $data[$name] = $value;
    }
    return $data;
}

// ─── Uploaded files (rules only; storage is in bbf_uploads.php) ──

/** extension => [canonical MIME, accepted finfo types, needs ZipArchive] */
const BBF_UPLOAD_TYPES = [
    'pdf'  => ['application/pdf', ['application/pdf'], false],
    'jpg'  => ['image/jpeg', ['image/jpeg'], false],
    'jpeg' => ['image/jpeg', ['image/jpeg'], false],
    'png'  => ['image/png', ['image/png'], false],
    'webp' => ['image/webp', ['image/webp'], false],
    'gif'  => ['image/gif', ['image/gif'], false],
    'txt'  => ['text/plain', ['text/plain'], false],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'], true],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'], true],
    'odt'  => ['application/vnd.oasis.opendocument.text', ['application/vnd.oasis.opendocument.text', 'application/zip'], true],
    'ods'  => ['application/vnd.oasis.opendocument.spreadsheet', ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'], true],
    'heic' => ['image/heic', ['image/heic', 'image/heif'], false],
    'csv'  => ['text/csv', ['text/csv', 'text/plain', 'application/csv'], false],
    'zip'  => ['application/zip', ['application/zip'], false],
    'doc'  => ['application/msword', ['application/msword', 'application/CDFV2', 'application/x-ole-storage', 'application/vnd.ms-office'], false],
    'xls'  => ['application/vnd.ms-excel', ['application/vnd.ms-excel', 'application/CDFV2', 'application/x-ole-storage', 'application/vnd.ms-office'], false],
];
const BBF_UPLOAD_DEFAULT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'txt', 'docx', 'xlsx', 'odt', 'ods'];
const BBF_UPLOAD_HARD_DENY = ['phtml', 'phar', 'pht', 'shtml', 'cgi', 'pl', 'py', 'sh', 'jsp', 'inc',
    'html', 'htm', 'xhtml', 'xht', 'svg', 'svgz', 'xml', 'xsl', 'xslt', 'js', 'mjs', 'swf', 'htaccess', 'user.ini',
    'exe', 'dll', 'bat', 'cmd', 'com', 'scr', 'msi', 'msc', 'jar', 'vbs', 'vbe', 'jse', 'ps1', 'wsf', 'wsh', 'hta',
    'cpl', 'pif', 'reg', 'scf', 'lnk', 'url', 'app', 'dmg', 'docm', 'xlsm', 'pptm', 'dotm', 'xltm', 'iso', 'img', 'vhd', 'vhdx'];

function bbf_uploads_config(array $config): array {
    $u = is_array($config['uploads'] ?? null) ? $config['uploads'] : [];
    return $u + [
        'enabled' => false,
        'dir' => dirname(__DIR__) . '/barebonesforms-private/uploads',
        'allow_inside_web_root' => false,
        'max_file_size' => 10 * 1024 * 1024,
        'max_submission_size' => 25 * 1024 * 1024,
        'allowed_extensions' => BBF_UPLOAD_DEFAULT_EXTENSIONS,
        'staging_ttl' => 7200,
        'max_staging_bytes' => 500 * 1024 * 1024,
        'max_staging_entries' => 2000,
        'per_ip_staging_bytes' => 100 * 1024 * 1024,
        'per_ip_staging_entries' => 40,
        'max_stored_bytes' => 5 * 1024 * 1024 * 1024,
        'max_stored_files' => 50000,
        'min_free_disk' => 200 * 1024 * 1024,
        'rate_limit' => ['max' => 60, 'window' => 600],
    ];
}

function bbf_uploads_hard_denied(string $ext, string $name = ''): bool {
    $ext = strtolower($ext);
    if (str_starts_with($ext, 'php') || str_starts_with($ext, 'asp') || in_array($ext, BBF_UPLOAD_HARD_DENY, true)) return true;
    $lower = strtolower($name);
    return in_array($lower, ['.htaccess', 'htaccess', '.user.ini', 'user.ini'], true) || str_ends_with($lower, '.user.ini');
}

/** A definition's accept entry ('.pdf' or 'pdf') as a bare lowercase extension. */
function bbf_uploads_normalize_ext($value): ?string {
    if (!is_string($value)) return null;
    $ext = strtolower(ltrim(trim($value), '.'));
    return preg_match('/\A[a-z0-9]{1,10}\z/', $ext) ? $ext : null;
}

/** Integer bytes or "<n>KB" / "<n>MB", binary units. */
function bbf_uploads_parse_size($value): ?int {
    if (is_int($value)) return $value > 0 ? $value : null;
    if (!is_string($value) || !preg_match('/\A(\d{1,9})\s*(KB|MB)?\z/i', trim($value), $m)) return null;
    $bytes = (int)$m[1] * match (strtoupper($m[2] ?? '')) { 'KB' => 1024, 'MB' => 1048576, default => 1 };
    return $bytes > 0 ? $bytes : null;
}

function bbf_uploads_ini_bytes($value): int {
    $value = trim((string)$value);
    if ($value === '' || !preg_match('/\A(\d+)\s*([KMG]?)/i', $value, $m)) return 0;
    return (int)$m[1] * match (strtoupper($m[2])) { 'K' => 1024, 'M' => 1048576, 'G' => 1073741824, default => 1 };
}

/** Definition errors for one file field (validateFieldList). */
function bbf_uploads_definition_errors(array $field, string $prefix, bool $insideRepeatable): array {
    $errors = [];
    if ($insideRepeatable) $errors[] = "$prefix: File fields are not supported inside repeatable groups.";
    if (array_key_exists('accept', $field)) {
        if (!is_array($field['accept']) || !array_is_list($field['accept']) || $field['accept'] === []) {
            $errors[] = "$prefix.accept: Expected a non-empty list of extensions.";
        } else {
            foreach ($field['accept'] as $i => $entry) {
                $ext = bbf_uploads_normalize_ext($entry);
                if ($ext === null || !isset(BBF_UPLOAD_TYPES[$ext]) || bbf_uploads_hard_denied($ext)) {
                    $errors[] = "$prefix.accept[$i]: Extension is not in the server allowlist.";
                }
            }
        }
    }
    if (array_key_exists('max_size', $field) && bbf_uploads_parse_size($field['max_size']) === null) {
        $errors[] = "$prefix.max_size: Expected bytes or a size such as \"5MB\".";
    }
    if (array_key_exists('max_files', $field) && (!is_int($field['max_files']) || $field['max_files'] < 1 || $field['max_files'] > 20)) {
        $errors[] = "$prefix.max_files: Expected an integer from 1 through 20.";
    }
    return $errors;
}

/** Extensions the server accepts right now: configured, known, not denied, dependencies present. */
function bbf_uploads_effective_extensions(array $config): array {
    $u = bbf_uploads_config($config);
    $out = [];
    foreach ((array)$u['allowed_extensions'] as $entry) {
        $ext = bbf_uploads_normalize_ext($entry);
        if ($ext === null || !isset(BBF_UPLOAD_TYPES[$ext]) || bbf_uploads_hard_denied($ext)) continue;
        if (BBF_UPLOAD_TYPES[$ext][2] && !class_exists('ZipArchive')) continue;
        $out[$ext] = true;
    }
    return array_keys($out);
}

function bbf_uploads_field_accept(array $config, array $field): array {
    $effective = bbf_uploads_effective_extensions($config);
    if (!is_array($field['accept'] ?? null)) return $effective;
    $wanted = array_filter(array_map('bbf_uploads_normalize_ext', $field['accept']));
    return array_values(array_intersect(array_unique($wanted), $effective));
}

function bbf_uploads_field_max_size(array $config, array $field): int {
    $u = bbf_uploads_config($config);
    $limits = [max(1, (int)$u['max_file_size'])];
    $fieldMax = bbf_uploads_parse_size($field['max_size'] ?? null);
    if ($fieldMax !== null) $limits[] = $fieldMax;
    $uploadMax = bbf_uploads_ini_bytes(ini_get('upload_max_filesize'));
    if ($uploadMax > 0) $limits[] = $uploadMax;
    $postMax = bbf_uploads_ini_bytes(ini_get('post_max_size'));
    if ($postMax > 0) $limits[] = max(1, $postMax - 65536);
    return min($limits);
}

function bbf_uploads_field_max_files(array $field): int {
    return is_int($field['max_files'] ?? null) ? max(1, $field['max_files']) : 1;
}

function bbf_uploads_human_size(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}

/** Sanitized display name: basename, valid UTF-8, no controls or bidi overrides, <= 200 bytes, extension kept. */
function bbf_uploads_sanitize_name(string $name): string {
    $name = (string)preg_replace('~\A.*[/\\\\]~s', '', $name);
    if (function_exists('mb_scrub')) {
        $name = mb_scrub($name, 'UTF-8');
    } elseif (!preg_match('//u', $name)) {
        $name = (string)preg_replace('/[\x80-\xFF]/', '_', $name);
    }
    $name = (string)preg_replace('/[\p{Cc}\p{Cf}]/u', '', $name);
    if (class_exists('Normalizer')) {
        $normalized = Normalizer::normalize($name, Normalizer::FORM_C);
        if (is_string($normalized)) $name = $normalized;
    }
    $name = trim($name);
    if ($name === '' || $name === '.' || $name === '..') $name = 'file';
    if (strlen($name) <= 200) return $name;
    $dot = strrpos($name, '.');
    $ext = $dot !== false && $dot > 0 && strlen($name) - $dot <= 21 ? substr($name, $dot) : '';
    $stem = $ext === '' ? $name : substr($name, 0, $dot);
    $budget = 200 - strlen($ext) - 3;
    if (function_exists('mb_strcut')) {
        $stem = mb_strcut($stem, 0, $budget, 'UTF-8');
    } else {
        $stem = substr($stem, 0, $budget);
        while ($stem !== '' && !preg_match('//u', $stem)) $stem = substr($stem, 0, -1);
    }
    $name = $stem . "\u{2026}" . $ext;
    return preg_match('//u', $name) ? $name : 'file' . $ext;
}

function bbf_uploads_name_ext(string $name): string {
    $dot = strrpos($name, '.');
    return $dot === false ? '' : strtolower(substr($name, $dot + 1));
}

/** Respondent-facing text in the current language (bbf_t). */
function bbf_uploads_t(string $key, array $params = []): string {
    return bbf_t($key, $params);
}

function bbf_uploads_error_message(int $error): string {
    return bbf_uploads_t(match ($error) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'uploadServerLimit',
        UPLOAD_ERR_PARTIAL => 'uploadInterrupted',
        UPLOAD_ERR_NO_FILE => 'uploadNoFile',
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'uploadCannotStore',
        UPLOAD_ERR_EXTENSION => 'uploadRefused',
        default => 'uploadFailed',
    });
}

/** Structural plausibility filter for OOXML and ODF (section 6). Null = plausible, else the reason. */
function bbf_uploads_office_check(string $path, string $ext): ?string {
    if (!class_exists('ZipArchive')) return 'This file type needs the ZipArchive extension on the server.';
    $zip = new ZipArchive();
    if ($zip->open($path, defined('ZipArchive::RDONLY') ? ZipArchive::RDONLY : 0) !== true) return bbf_uploads_t('uploadInvalidDocument');
    try {
        $count = $zip->numFiles;
        if ($count < 1 || $count > 2000) return bbf_uploads_t('uploadInvalidDocument');
        $found = [];
        for ($i = 0; $i < $count; $i++) {
            $name = (string)$zip->getNameIndex($i);
            $lower = strtolower($name);
            if (str_ends_with($lower, 'vbaproject.bin')) return bbf_uploads_t('uploadMacros');
            if (in_array($ext, ['odt', 'ods'], true) && (str_starts_with($name, 'Basic/') || str_starts_with($name, 'Scripts/'))) {
                return bbf_uploads_t('uploadMacros');
            }
            $found[$name] = true;
        }
        $required = match ($ext) {
            'docx' => ['[Content_Types].xml', 'word/document.xml'],
            'xlsx' => ['[Content_Types].xml', 'xl/workbook.xml'],
            default => ['mimetype'],
        };
        foreach ($required as $name) if (!isset($found[$name])) return bbf_uploads_t('uploadInvalidDocument');
        if (in_array($ext, ['odt', 'ods'], true)) {
            $expected = $ext === 'odt' ? 'application/vnd.oasis.opendocument.text' : 'application/vnd.oasis.opendocument.spreadsheet';
            if (trim((string)$zip->getFromName('mimetype', 100)) !== $expected) return bbf_uploads_t('uploadInvalidDocument');
        }
        return null;
    } finally {
        $zip->close();
    }
}

/** Step 8 on PHP's temporary file: name, size, extension, content. */
function bbf_uploads_validate_file(array $config, array $field, string $tmp, string $clientName, int $size): array {
    $name = bbf_uploads_sanitize_name($clientName);
    $ext = bbf_uploads_name_ext($name);
    if ($size <= 0) return ['ok' => false, 'code' => 422, 'message' => bbf_uploads_t('uploadEmpty')];
    $max = bbf_uploads_field_max_size($config, $field);
    if ($size > $max) return ['ok' => false, 'code' => 413, 'message' => bbf_uploads_t('uploadTooLarge', ['max' => bbf_uploads_human_size($max)])];
    if ($ext === '' || bbf_uploads_hard_denied($ext, $name) || !in_array($ext, bbf_uploads_field_accept($config, $field), true)) {
        return ['ok' => false, 'code' => 422, 'message' => bbf_uploads_t('uploadType')];
    }
    if (!class_exists('finfo')) {
        error_log('BareBonesForms: uploads refused, the PHP fileinfo extension is missing.');
        return ['ok' => false, 'code' => 503, 'message' => bbf_uploads_t('uploadCannotStore')];
    }
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    // A password-protected OOXML file is an OLE compound file, not a ZIP.
    if (in_array($ext, ['docx', 'xlsx'], true) && (str_starts_with($mime, 'application/CDFV2')
        || in_array($mime, ['application/encrypted', 'application/x-ole-storage'], true))) {
        return ['ok' => false, 'code' => 422, 'message' => bbf_uploads_t('uploadEncrypted')];
    }
    if (!in_array($mime, BBF_UPLOAD_TYPES[$ext][1], true)) {
        return ['ok' => false, 'code' => 422, 'message' => bbf_uploads_t('uploadMismatch')];
    }
    if (BBF_UPLOAD_TYPES[$ext][2]) {
        $reason = bbf_uploads_office_check($tmp, $ext);
        if ($reason !== null) return ['ok' => false, 'code' => 422, 'message' => $reason];
    }
    $sha = hash_file('sha256', $tmp);
    if (!is_string($sha)) return ['ok' => false, 'code' => 503, 'message' => bbf_uploads_t('uploadUnreadable')];
    return ['ok' => true, 'name' => $name, 'ext' => $ext, 'size' => $size, 'type' => BBF_UPLOAD_TYPES[$ext][0], 'sha256' => $sha];
}

// ─── Messages ───────────────────────────────────────────────────

/**
 * Message $key in the active language: inside bbf_validate() / bbf_render_html() the language chosen there,
 * in the standalone mode submit.php's msg(), otherwise English.
 */
function bbf_t(string $key, array $params = []): string {
    $handler = $GLOBALS['_bbf_t'] ?? null;
    if (is_callable($handler)) return $handler($key, $params);
    if (defined('BBF_LOADED') && function_exists('msg')) return msg($key, $params);
    return bbf_format_message(bbf_messages('en')[$key] ?? $key, $params);
}

/** "{label} is required." with $params; one pass, so a "{max}" inside a label stays literal (bbf.js _t does the same). */
function bbf_format_message(string $text, array $params): string {
    return (string)preg_replace_callback('/\{(\w+)\}/', static fn($m) => array_key_exists($m[1], $params) ? (string)$params[$m[1]] : $m[0], $text);
}

/** Server messages for $lang ('sk', 'pt-br' …): English fills keys the pack lacks, then the host's overrides apply. */
function bbf_messages(string $lang = 'en', array $overrides = []): array {
    static $packs = [];
    $lang = strtolower(trim($lang));
    if (!preg_match('/\A[a-z]{2,3}(-[a-z]{2})?\z/D', $lang)) $lang = 'en';
    $load = static function (string $code) use (&$packs): array {
        if (!array_key_exists($code, $packs)) {
            $file = __DIR__ . "/lang/$code.php";
            $pack = is_file($file) ? (static fn() => require $file)() : [];
            $packs[$code] = is_array($pack) ? $pack : [];
        }
        return $packs[$code];
    };
    $messages = $lang === 'en' ? $load('en') : array_replace($load('en'), $load($lang));
    foreach ($overrides as $key => $text) if (is_string($key) && is_string($text)) $messages[$key] = $text;
    return $messages;
}

/** Run $fn with bbf_t() answering in $opts['lang'] (default 'en') with $opts['messages'] overrides. */
function bbf_with_messages(array $opts, callable $fn): mixed {
    $messages = bbf_messages(is_string($opts['lang'] ?? null) ? $opts['lang'] : 'en', is_array($opts['messages'] ?? null) ? $opts['messages'] : []);
    $previous = $GLOBALS['_bbf_t'] ?? null;
    $GLOBALS['_bbf_t'] = static fn(string $key, array $params = []): string => bbf_format_message($messages[$key] ?? $key, $params);
    try {
        return $fn();
    } finally {
        $GLOBALS['_bbf_t'] = $previous;
    }
}

// ─── Custom field types (x-…) ───────────────────────────────────

function &bbf_type_registry(): array {
    static $types = [];
    return $types;
}

/**
 * Register a custom field type for the embedded mode. $type starts with "x-" (x-category-tree). Handlers, all optional:
 *   validate(mixed $value, array $field, array $input): ?string   error message or null; runs for non-empty values
 *   normalize(mixed $value, array $field): mixed                   the value bbf_validate() returns in data
 *   render(array $field, mixed $value, array $ctx): string         control HTML for bbf_render_html() (ctx as in templates.control)
 * The value is the raw input: a string, or an array for name[] / structured inputs (never cast to a string). "required"
 * means not empty: a non-blank string or a non-empty array. Order: required → validate(raw) → only when that passed
 * and the value is not empty, normalize(raw), whose result is the field's data (without normalize: the raw value).
 * A form that uses an unregistered x- type is a definition error, so it never silently loses a value. Registering the
 * same type twice throws: nothing is silently replaced.
 */
function bbf_register_type(string $type, array $handlers): void {
    if (!preg_match('/\Ax-[a-z0-9][a-z0-9-]*\z/D', $type)) {
        throw new InvalidArgumentException("Custom field types are named x-…, e.g. x-category-tree: $type");
    }
    foreach ($handlers as $key => $handler) {
        if (!in_array($key, ['validate', 'normalize', 'render'], true) || !is_callable($handler)) {
            throw new InvalidArgumentException("bbf_register_type($type): '$key' is not a callable validate, normalize or render handler.");
        }
    }
    if (isset(bbf_type_registry()[$type])) {
        throw new InvalidArgumentException("bbf_register_type($type): the type is already registered; a second registration never replaces it.");
    }
    bbf_type_registry()[$type] = $handlers;
}

function bbf_is_custom_type(mixed $type): bool {
    return is_string($type) && str_starts_with($type, 'x-');
}

function bbf_custom_type(mixed $type): ?array {
    return bbf_is_custom_type($type) ? (bbf_type_registry()[$type] ?? null) : null;
}

// ─── Options from a source (options_from) ───────────────────────

/**
 * Fields with options_from get the options the server resolved: $resolver($source, $field) returns a list of options
 * ("a" or {"value": …, "label": …}) or null when the source is unavailable. Resolved fields are marked
 * _bbf_options_resolved. On failure static options stay as the fallback (as in bbf.js); without them no value is valid
 * (bbf_validate_fields). Recurses into groups, repeatable rows included. Each source is asked once ($cache);
 * $failed collects the sources that could not be resolved.
 */
function bbf_resolve_options(array $fields, ?callable $resolver, ?array &$cache = [], ?array &$failed = []): array {
    $cache ??= [];
    $failed ??= [];
    foreach ($fields as $i => $field) {
        if (!is_array($field)) continue;
        if (is_array($field['fields'] ?? null)) {
            $fields[$i]['fields'] = bbf_resolve_options($field['fields'], $resolver, $cache, $failed);
        }
        $source = $field['options_from'] ?? null;
        if (!is_string($source) || $source === '' || !empty($field['_bbf_options_resolved'])) continue;
        if (!array_key_exists($source, $cache)) {
            $options = null;
            if ($resolver !== null) {
                try {
                    $options = $resolver($source, $field);
                } catch (Throwable $e) {
                    $options = null;
                }
            }
            $cache[$source] = bbf_options_list($options);
        }
        if ($cache[$source] === null) {
            $failed[$source] = true;
            continue;
        }
        $fields[$i]['options'] = $cache[$source];
        $fields[$i]['_bbf_options_resolved'] = true;
    }
    return $fields;
}

/**
 * The record's stored values ($values = opts.values, trusted: from the host's database) that are no longer among a
 * select/radio/checkbox field's options (a deleted category, a retired status) become extra options marked
 * _bbf_stored, so editing a record never silently changes them. Fields with "other" keep their own handling.
 * Recurses into non-repeatable groups.
 */
function bbf_keep_stored_options(array $fields, array $values): array {
    foreach ($fields as $i => $field) {
        if (!is_array($field)) continue;
        $type = $field['type'] ?? 'text';
        if ($type === 'group') {
            if (empty($field['repeatable']) && is_array($field['fields'] ?? null)) $fields[$i]['fields'] = bbf_keep_stored_options($field['fields'], $values);
            continue;
        }
        $name = $field['name'] ?? null;
        if (!in_array($type, ['select', 'radio', 'checkbox'], true) || !is_string($name) || !array_key_exists($name, $values) || !empty($field['other'])) continue;
        $options = is_array($field['options'] ?? null) ? $field['options'] : [];
        $known = array_map(static fn($o) => is_array($o) ? (string)(is_scalar($o['value'] ?? null) ? $o['value'] : '') : (string)$o, $options);
        foreach (is_array($values[$name]) ? $values[$name] : [$values[$name]] as $stored) {
            if (!is_scalar($stored) || is_bool($stored)) continue;
            $stored = (string)$stored;
            if ($stored === '' || $stored === '__other__' || in_array($stored, $known, true)) continue;
            $options[] = ['value' => $stored, 'label' => $stored, '_bbf_stored' => true];
            $known[] = $stored;
        }
        $fields[$i]['options'] = $options;
    }
    return $fields;
}

/** A valid options list (scalars or objects with a scalar value), else null. */
function bbf_options_list(mixed $options): ?array {
    if (!is_array($options) || !array_is_list($options)) return null;
    foreach ($options as $option) {
        if (is_string($option) || is_int($option) || is_float($option)) continue;
        if (is_array($option) && is_scalar($option['value'] ?? null)) continue;
        return null;
    }
    return $options;
}

// ─── Embedded mode: load and validate ───────────────────────────

/** A definition or library usage error; ->errors lists the definition errors when there are any. */
class BbfFormException extends RuntimeException {
    public array $errors;

    public function __construct(string $message, array $errors = []) {
        parent::__construct($message);
        $this->errors = $errors;
    }
}

/**
 * Load "$formsDir/$id.json" (the host's own forms directory works). The id inside the file must match.
 * Returns the checked definition with templates resolved; throws BbfFormException otherwise.
 */
function bbf_load_form(string $id, string $formsDir): array {
    if (!preg_match('/\A[a-zA-Z0-9_-]+\z/D', $id)) throw new BbfFormException("Invalid form id: $id");
    $file = rtrim($formsDir, '/\\') . "/$id.json";
    if (!is_file($file)) throw new BbfFormException("Form '$id' was not found in $formsDir.");
    $form = json_decode((string)file_get_contents($file), true);
    if (!is_array($form)) throw new BbfFormException("Form '$id' is not valid JSON: " . json_last_error_msg() . '.');
    if (($form['id'] ?? null) !== $id) throw new BbfFormException("Form '$id': the id inside the file does not match the file name.");
    return bbf_prepare_form($form);
}

/** Check a definition (field rules, custom types) and resolve its templates; throws BbfFormException on errors. */
function bbf_prepare_form(array $form): array {
    $errors = bbf_definition_errors($form);
    if ($errors) {
        throw new BbfFormException("Form '" . (is_string($form['id'] ?? null) ? $form['id'] : '?') . "' has definition errors: "
            . implode(' ', array_slice($errors, 0, 5)), $errors);
    }
    if (!empty($form['templates'])) $form['fields'] = bbf_resolve_templates($form['fields'], $form['templates']);
    unset($form['templates']);
    return $form;
}

/**
 * Validate and normalize input against a form like submit.php does, with no side effects (no session, storage,
 * e-mail, webhooks, configuration).
 *   $input  the submitted values ($_POST or a decoded JSON body)
 *   $files  $_FILES (only file fields are read)
 *   $opts   lang ('en'), messages ([key => text]), options_resolver (callable($source, $field): ?array),
 *           values (the record's stored values when editing, from the host's database, never the posted input: an
 *           existing file satisfies a required file field, and a stored select/radio/checkbox value that is no longer
 *           among the options stays valid, so saving never silently changes it; any other value outside the list is refused),
 *           uploads (['allowed_extensions' => [...], 'max_file_size' => bytes]), check_uploaded (true: is_uploaded_file())
 * Returns ['errors' => [field => message], 'data' => normalized values of visible fields]. A file field's data is
 * ['action' => 'keep'|'remove'|'replace', 'files' => [['tmp_name', 'name', 'ext', 'size', 'type', 'sha256'], …]];
 * the field's "<name>__remove" checkbox asks for remove. Nothing is moved or stored: that is the host's job.
 */
function bbf_validate(array $form, array $input, array $files = [], array $opts = []): array {
    $form = bbf_prepare_form($form);
    return bbf_with_messages($opts, static function () use ($form, $input, $files, $opts): array {
        $resolver = $opts['options_resolver'] ?? null;
        if ($resolver !== null && !is_callable($resolver)) throw new BbfFormException('options_resolver must be callable.');
        $values = is_array($opts['values'] ?? null) ? $opts['values'] : [];
        $fields = bbf_keep_stored_options(bbf_resolve_options(bbf_flatten_fields($form['fields']), $resolver), $values);
        // File fields are checked from $files below; a value posted under their name is never data.
        $plainFields = [];
        $plainInput = $input;
        foreach ($fields as $field) {
            if (($field['type'] ?? '') === 'file') {
                unset($plainInput[$field['name']]);
                $field['required'] = false;
            }
            $plainFields[] = $field;
        }
        $errors = bbf_validate_fields($plainFields, $plainInput);
        $shapeErrors = bbf_validate_shapes($plainFields, $plainInput);
        $data = $shapeErrors ? [] : bbf_collect_data($plainFields, $plainInput, $errors);
        if (!$shapeErrors) $errors = array_replace($errors, bbf_validate_cross($form['validations'] ?? [], $data));
        $visible = bbfVisibleInput($fields, $plainInput);
        foreach ($fields as $field) {
            if (($field['type'] ?? '') !== 'file') continue;
            $name = $field['name'];
            unset($data[$name]);
            if (!empty($field['show_if']) && !bbf_eval_condition($field['show_if'], $visible)) continue;
            $error = null;
            $result = bbf_validate_file_field($field, $files, $input, $opts, $error);
            if ($error !== null) $errors[$name] = $error;
            elseif (!$shapeErrors) $data[$name] = $result;
        }
        return ['errors' => $errors, 'data' => $data];
    });
}

/** $_FILES[field] (single or name[] multiple) or a list of such entries, as a list of uploads. */
function bbf_normalize_files(mixed $entry): array {
    if (!is_array($entry)) return [];
    if (array_is_list($entry)) {
        $out = [];
        foreach ($entry as $item) array_push($out, ...bbf_normalize_files($item));
        return $out;
    }
    if (is_array($entry['name'] ?? null)) {
        $out = [];
        foreach (array_keys($entry['name']) as $key) {
            $out[] = ['name' => $entry['name'][$key] ?? '', 'tmp_name' => $entry['tmp_name'][$key] ?? '',
                'error' => $entry['error'][$key] ?? UPLOAD_ERR_NO_FILE, 'size' => $entry['size'][$key] ?? 0];
        }
        return $out;
    }
    return [['name' => (string)($entry['name'] ?? ''), 'tmp_name' => (string)($entry['tmp_name'] ?? ''),
        'error' => (int)($entry['error'] ?? UPLOAD_ERR_NO_FILE), 'size' => (int)($entry['size'] ?? 0)]];
}

/** One file field in the embedded mode: type, size and content of each upload, then keep / remove / replace. */
function bbf_validate_file_field(array $field, array $files, array $input, array $opts, ?string &$error): array {
    $name = $field['name'];
    $label = $field['label'] ?? $name;
    $keep = ['action' => 'keep', 'files' => []];
    $existing = !empty($opts['values'][$name]);
    $config = ['uploads' => is_array($opts['uploads'] ?? null) ? $opts['uploads'] : []];
    $accepted = [];
    foreach (bbf_normalize_files($files[$name] ?? null) as $upload) {
        $code = (int)$upload['error'];
        if ($code === UPLOAD_ERR_NO_FILE) continue;
        if ($code !== UPLOAD_ERR_OK) {
            $error = bbf_uploads_error_message($code);
            return $keep;
        }
        $tmp = (string)$upload['tmp_name'];
        if (($opts['check_uploaded'] ?? true) && !is_uploaded_file($tmp)) {
            $error = bbf_t('uploadFailed');
            return $keep;
        }
        $check = bbf_uploads_validate_file($config, $field, $tmp, (string)$upload['name'], (int)$upload['size']);
        if (!$check['ok']) {
            $error = $check['message'];
            return $keep;
        }
        unset($check['ok']);
        $accepted[] = ['tmp_name' => $tmp] + $check;
    }
    $maxFiles = bbf_uploads_field_max_files($field);
    if (count($accepted) > $maxFiles) {
        $error = bbf_t('tooManyFiles', ['label' => $label, 'max' => $maxFiles]);
        return $keep;
    }
    if ($accepted) return ['action' => 'replace', 'files' => $accepted];
    $removeFlag = $input[$name . '__remove'] ?? '';
    if ($existing && is_scalar($removeFlag) && !in_array((string)$removeFlag, ['', '0'], true)) {
        if (!empty($field['required'])) $error = bbf_t('required', ['label' => $label]);
        return ['action' => 'remove', 'files' => []];
    }
    if (!empty($field['required']) && !$existing) $error = bbf_t('required', ['label' => $label]);
    return $keep;
}
