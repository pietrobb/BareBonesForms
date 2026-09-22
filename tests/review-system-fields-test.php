<?php
/** Real GET/POST tests in an owned installation, with all outgoing delivery disabled. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/test-isolation-helper.php';
define('BBF_LOADED', true);
require_once dirname(__DIR__) . '/bbf_functions.php';
require_once dirname(__DIR__) . '/bbf_context.php';
require_once dirname(__DIR__) . '/bbf_versions.php';
$checks = $failures = 0;
function contextCheck(bool $ok, string $label): void {
    ++$GLOBALS['checks'];
    if (!$ok) ++$GLOBALS['failures'];
    print ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
}
$root = bbf_test_installation(dirname(__DIR__));
$server = null;
try {
    $names = ['gclid', 'gbraid', 'wbraid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'landing_url', 'referrer', 'touch_at'];
    $config = [
        'storage' => 'file', 'forms_dir' => "$root/forms", 'submissions_dir' => "$root/submissions",
        'logs_dir' => "$root/logs", 'templates_dir' => "$root/templates", 'csrf' => false,
        'sandbox' => false, 'honeypot_field' => '_hp', 'rate_limit' => 1000, 'lang' => 'en',
        'store_ip' => false, 'store_user_agent' => false, 'error_notify' => '',
        'mail' => ['method' => 'mail'], 'stripe' => [], 'api_token' => 'private-never-expose',
        'system_fields' => array_map(static fn($name) => ['name' => $name, 'type' => 'hidden', 'value' => ''], $names),
        'visit_context' => ['trigger_params' => ['gclid', 'gbraid', 'wbraid'], 'params' => array_slice($names, 3, 5), 'private' => 'not-public'],
        'analytics' => ['umami' => true],
    ];
    // Validation/defaults attached to system fields cannot make them required.
    $config['system_fields'][0] += ['required' => true, 'maxlength' => 3, 'pattern' => '^bad$'];
    $form = ['id' => 'context-fixture', 'fields' => [['name' => 'answer', 'type' => 'text', 'required' => true]], 'on_submit' => ['store' => true]];
    file_put_contents("$root/config.php", '<?php defined("BBF_LOADED") || exit; return ' . var_export($config, true) . ';');
    file_put_contents("$root/forms/context-fixture.json", json_encode($form, JSON_THROW_ON_ERROR));
    $server = bbf_test_start_server($root, '127.0.0.1', bbf_test_port());
    $url = 'http://127.0.0.1:' . $server['port'] . '/submit.php';
    $definition = bbf_test_http($server, "$url?form=context-fixture&action=definition");
    $fields = array_column($definition['json']['fields'] ?? [], null, 'name');
    contextCheck($definition['code'] === 200 && count($fields) === 12, 'definition injects all eleven system fields');
    contextCheck(($fields['gclid']['type'] ?? '') === 'hidden' && !isset($fields['gclid']['required']) && !isset($fields['gclid']['maxlength']), 'system input is hidden and has no user validation');
    contextCheck(!str_contains($definition['body'], 'private-never-expose') && !isset($definition['json']['on_submit']), 'definition never exposes server configuration');
    $public = bbf_test_http($server, "$url?action=context");
    contextCheck(($public['json']['visit_context']['trigger_params'] ?? []) === ['gclid', 'gbraid', 'wbraid']
        && !str_contains($public['body'], 'not-public') && !str_contains($public['body'], 'private-never-expose'), 'context endpoint exposes only public allowlists');
    $long = '  ' . str_repeat('á &=%? +', 90) . '  ';
    foreach ([['answer' => 'ok'], ['answer' => 'ok', 'gclid' => $long, 'utm_term' => $long, 'landing_url' => $long],
              ['answer' => 'ok', 'gclid' => ['bad' => ['array']], 'utm_campaign' => (object)['bad' => 1]]] as $i => $input) {
        $result = bbf_test_http($server, "$url?form=context-fixture", null, ['raw' => json_encode($input), 'headers' => ['Content-Type' => 'application/json']]);
        contextCheck($result['code'] === 200 && !empty($result['json']['submission_id']), "POST $i succeeds with optional or malformed tracking");
        $id = $result['json']['submission_id'] ?? '';
        $path = "$root/submissions/context-fixture/$id.json";
        $stored = is_file($path) ? json_decode(file_get_contents($path), true) : [];
        contextCheck(count(array_intersect($names, array_keys($stored['data'] ?? []))) === 11, "POST $i stores every system field");
        contextCheck(($stored['data']['gclid'] ?? null) === ($i === 1 ? $long : ''), "POST $i preserves long strings verbatim or blanks invalid input");
        if ($i === 1) contextCheck(($stored['data']['utm_term'] ?? '') === $long && ($stored['data']['landing_url'] ?? '') === $long, 'UTM and URL whitespace and special characters preserved');
        contextCheck(($stored['meta']['definition_version'] ?? '') === bbf_version_id(bbfSystemDefinition($form, $config))
            && count($stored['meta']['form_definition']['fields'] ?? []) === 12, 'effective fields included in definition identity and historical presentation');
    }
    $invalid = bbf_test_http($server, "$url?form=context-fixture", ['gclid' => 'valid-context']);
    contextCheck($invalid['code'] === 422, 'ordinary required validation still rejects invalid lead');
    $override = $form;
    $override['fields'][] = ['name' => 'gclid', 'type' => 'text', 'required' => true, 'maxlength' => 5];
    file_put_contents("$root/forms/context-fixture.json", json_encode($override));
    $defined = bbf_test_http($server, "$url?form=context-fixture&action=definition");
    $byName = array_column($defined['json']['fields'], null, 'name');
    contextCheck(count($byName) === 12 && $byName['gclid']['type'] === 'text' && !isset($byName['gclid']['_bbf_system']), 'local field wins without a duplicate');
    $rejected = bbf_test_http($server, "$url?form=context-fixture", ['answer' => 'ok']);
    contextCheck($rejected['code'] === 422, 'local override retains its validation');
    $nested = ['fields' => [['name' => 'group', 'type' => 'group', 'use' => 'fields', 'prefix' => 'utm_']],
        'templates' => ['fields' => [['name' => 'source', 'type' => 'text']]]];
    $injected = bbfSystemDefinition($nested, $config);
    $expanded = flattenFields(resolveTemplates($injected['fields'], $injected['templates']));
    contextCheck(count(array_filter($expanded, static fn($field) => ($field['name'] ?? '') === 'utm_source')) === 1, 'resolved prefixed template field overrides system field');
    $bad = $config;
    $bad['system_fields'] = [['name' => '_bbf_csrf'], ['name' => '_hp'], ['name' => 'bad[]'], ['name' => 'gclid'], ['name' => 'gclid']];
    contextCheck(count(bbfSystemDefinition($form, $bad)['fields']) === 2, 'reserved and invalid names ignored, duplicate config names deduplicated');
    contextCheck(bbfSystemInput([['name' => 'gclid', '_bbf_system' => true]], ['gclid' => "\xFF"])['gclid'] === '', 'invalid UTF-8 context becomes empty without JSON failure');
    contextCheck(bbfSystemDefinition($form, []) === $form, 'feature disabled by default preserves definitions');
} finally {
    bbf_test_stop_server($server);
    bbf_test_cleanup($root);
}
print "$checks checks, $failures failures\n";
exit($failures ? 1 : 0);
