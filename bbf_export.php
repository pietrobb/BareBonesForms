<?php
// Shared export schema and checked preparation before authorization audit completion.
defined('BBF_LOADED') || exit;
require_once __DIR__ . '/bbf_functions.php';

function bbf_export_validate_fields(array $fields): void {
    foreach ($fields as $field) {
        if (!is_array($field)) throw new RuntimeException('Invalid export field.');
        foreach (['name', 'type', 'use', 'prefix'] as $key) {
            if (isset($field[$key]) && !is_string($field[$key])) throw new RuntimeException('Invalid export field attribute.');
        }
        if (array_key_exists('fields', $field)) {
            if (!is_array($field['fields'])) throw new RuntimeException('Invalid export child fields.');
            bbf_export_validate_fields($field['fields']);
        }
    }
}

function bbf_export_field_keys(?array $form, iterable $submissions): array {
    $keys = [];
    if (isset($form['fields']) && !is_array($form['fields'])) throw new RuntimeException('Invalid export fields.');
    if (isset($form['templates']) && !is_array($form['templates'])) throw new RuntimeException('Invalid export templates.');
    bbf_export_validate_fields($form['fields'] ?? []);
    foreach ($form['templates'] ?? [] as $template) {
        if (!is_array($template)) throw new RuntimeException('Invalid export template.');
        bbf_export_validate_fields($template);
    }
    $fields = resolveTemplates($form['fields'] ?? [], $form['templates'] ?? []);
    foreach (flattenFields($fields) as $field) {
        $type = $field['type'] ?? 'text';
        if (in_array($type, ['section', 'page_break'], true) || ($type === 'group' && empty($field['repeatable']))) continue;
        $name = $field['name'] ?? '';
        if (is_string($name) && $name !== '') $keys[$name] = true;
    }
    foreach ($submissions as $submission) {
        foreach (array_keys($submission['data'] ?? []) as $key) $keys[$key] = true;
    }
    return array_keys($keys);
}

function bbf_export_repeatable_keys(?array $form): array {
    if ($form === null) return [];
    $fields = resolveTemplates($form['fields'] ?? [], $form['templates'] ?? []);
    $keys = [];
    foreach (flattenFields($fields) as $field) {
        if (($field['type'] ?? '') !== 'group' || empty($field['repeatable'])) continue;
        $name = $field['name'] ?? '';
        if (is_string($name) && $name !== '') $keys[$name] = true;
    }
    return $keys;
}

function bbf_export_cell($value, bool $structured = false): string {
    if (is_array($value)) {
        $nested = $structured || array_filter($value, 'is_array') !== [];
        $value = $nested
            ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            : implode(', ', array_map('strval', $value));
    } else {
        $value = (string)$value;
    }
    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) $value = "'" . $value;
    return $value;
}

function bbf_export_definition(string $path): ?array {
    if (!file_exists($path)) return null;
    $raw = file_get_contents($path);
    if ($raw === false) throw new RuntimeException('Cannot read export definition.');
    $form = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($form)) throw new RuntimeException('Invalid export definition.');
    return $form;
}

// Encode one record in memory with PHP's CSV rules and no proprietary backslash escape.
// fputcsv's return value on the disk spool can be zero/short, not just false;
// retain the full encoded bytes so write_all can retry every unwritten suffix.
function bbf_export_csv_record(array $cells): string {
    $record = fopen('php://memory', 'w+b');
    if ($record === false) throw new RuntimeException('Cannot encode CSV record.');
    try {
        $length = fputcsv($record, $cells, ',', '"', '');
        if ($length === false || $length === 0 || !rewind($record)) throw new RuntimeException('Cannot encode CSV record.');
        $bytes = stream_get_contents($record);
        if ($bytes === false || strlen($bytes) !== $length) throw new RuntimeException('Cannot read encoded CSV record.');
        return $bytes;
    } finally {
        fclose($record);
    }
}

// Spool the selected snapshot once: historical columns may first occur in its last row.
// Only release a prepared CSV after every read/encoding/write and rewind has succeeded.
function bbf_export_prepare(?array $form, iterable $submissions): array {
    $keys = array_fill_keys(bbf_export_field_keys($form, []), true);
    $repeatableKeys = bbf_export_repeatable_keys($form);
    $records = tmpfile();
    if ($records === false) throw new RuntimeException('Cannot prepare export snapshot.');
    $out = null;
    try {
        $count = 0;
        foreach ($submissions as $submission) {
            foreach (array_keys($submission['data'] ?? []) as $key) $keys[$key] = true;
            if (!bbf_storage_write_all($records, json_encode($submission, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n")) throw new RuntimeException('Cannot write export snapshot.');
            $count++;
        }
        if (!fflush($records) || !rewind($records)) throw new RuntimeException('Cannot read export snapshot.');
        $out = tmpfile();
        if ($out === false) throw new RuntimeException('Cannot prepare CSV export.');
        if ($count === 0) {
            if (!bbf_storage_write_all($out, "No submissions\n")) throw new RuntimeException('Cannot write empty export.');
        } else {
            $fieldKeys = array_keys($keys);
            $headers = array_map('bbf_export_cell', array_merge(['id', 'submitted'], $fieldKeys));
            if (!bbf_storage_write_all($out, bbf_export_csv_record($headers))) throw new RuntimeException('Cannot write CSV headers.');
            while (($line = fgets($records)) !== false) {
                $sub = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $row = [bbf_export_cell($sub['id']), bbf_export_cell($sub['meta']['submitted'] ?? '')];
                foreach ($fieldKeys as $key) {
                    $row[] = bbf_export_cell($sub['data'][$key] ?? '', isset($repeatableKeys[$key]));
                }
                if (!bbf_storage_write_all($out, bbf_export_csv_record($row))) throw new RuntimeException('Cannot write CSV row.');
            }
            if (!feof($records)) throw new RuntimeException('Cannot finish export snapshot.');
        }
        if (!fflush($out) || !rewind($out)) throw new RuntimeException('Cannot finish CSV export.');
        return ['stream' => $out, 'count' => $count];
    } catch (Throwable $error) {
        if (is_resource($out)) fclose($out);
        throw $error;
    } finally {
        fclose($records);
    }
}
