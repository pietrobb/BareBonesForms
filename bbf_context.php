<?php
/** Shared runtime system fields and public visit-context configuration. */
defined('BBF_LOADED') || exit;

function bbfContextFieldName($name, array $config): bool {
    return is_string($name) && preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]{0,127}\z/D', $name)
        && $name !== ($config['honeypot_field'] ?? '_bbf_hp');
}

function bbfSystemDefinition(array $form, array $config): array {
    // Resolve only for collision detection; keep the published template structure intact.
    $fields = !empty($form['templates'])
        ? resolveTemplates($form['fields'] ?? [], $form['templates']) : ($form['fields'] ?? []);
    $names = [];
    $walk = static function (array $fields) use (&$walk, &$names): void {
        foreach ($fields as $field) {
            if (isset($field['name'])) $names[$field['name']] = true;
            if (isset($field['fields']) && is_array($field['fields'])) $walk($field['fields']);
        }
    };
    $walk($fields);
    foreach ($config['system_fields'] ?? [] as $field) {
        $name = is_array($field) ? ($field['name'] ?? null) : null;
        if (!bbfContextFieldName($name, $config) || isset($names[$name])) continue;
        // System fields are optional scalar context, never user-facing validation rules.
        $form['fields'][] = ['name' => $name, 'type' => 'hidden', 'value' => '', '_bbf_system' => true];
        $names[$name] = true;
    }
    return $form;
}

function bbfSystemInput(array $fields, array $input): array {
    foreach ($fields as $field) {
        if (empty($field['_bbf_system'])) continue;
        $name = $field['name'];
        $value = $input[$name] ?? '';
        $value = is_scalar($value) ? (string)$value : '';
        // Malformed context must not reject an otherwise valid lead or break JSON storage.
        $input[$name] = preg_match('//u', $value) === 1 ? $value : '';
    }
    return $input;
}

function bbfClientConfiguration(array $config): array {
    $context = $config['visit_context'] ?? [];
    $public = [];
    if (is_array($context) && $context !== []) {
        foreach (['trigger_params', 'params'] as $key) {
            $public[$key] = array_values(array_unique(array_filter(
                is_array($context[$key] ?? null) ? $context[$key] : [],
                static fn($name) => bbfContextFieldName($name, $config)
                    && !in_array($name, ['landing_url', 'referrer', 'touch_at'], true)
            )));
        }
    }
    return ['visit_context' => $public, 'analytics' => ['umami' => !empty($config['analytics']['umami'])]];
}
