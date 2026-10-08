<?php
/**
 * 2.2.0 host-application contract, over real HTTP on an owned fixture:
 *  - aeon.sk statutory withdrawal form (contract-aeon/withdrawal.mjs) keeps working in the default standalone mode;
 *  - 'standalone' => false closes every web entry point with 404 before sessions, storage or logs (R7).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/test-isolation-helper.php';
$checks = 0;
function eh_check(bool $ok, string $name, string $detail = ''): void {
    global $checks;
    if (!$ok) throw new RuntimeException($name . ($detail !== '' ? " — $detail" : ''));
    ++$checks; print "PASS $name\n";
}
$source = dirname(__DIR__);
$entryPoints = array_values(array_filter(array_map('basename', glob("$source/*.php") ?: []),
    static fn(string $f) => !str_starts_with($f, 'bbf_') && !str_starts_with($f, 'config')));
$root = bbf_test_installation($source);
$server = null;
try {
    foreach ($entryPoints as $file) {
        if (!is_file("$root/$file")) bbf_test_copy("$source/$file", "$root/$file");
    }
    foreach (glob("$source/bbf_*.php") ?: [] as $file) {
        if (!is_file("$root/" . basename($file))) bbf_test_copy($file, "$root/" . basename($file));
    }
    bbf_test_remove_dir("$root/forms"); mkdir("$root/forms", 0700);
    // Verbatim aeon definition (E:\Web\_work\aeon_return_bbf_20260803\odstupenie-od-zmluvy.json).
    $withdrawal = ['$schema' => 'form.schema.json', 'schema_version' => 1, 'id' => 'odstupenie-od-zmluvy',
        'name' => 'Odstúpenie od zmluvy', 'submit_label' => 'Potvrdiť odstúpenie od zmluvy', 'submitting_label' => 'Odosielam…',
        'success_message' => 'Odstúpenie bolo odoslané. Potvrdenie s dátumom a časom sme poslali na váš e-mail.',
        'label_position' => 'top', 'fields' => [
            ['name' => 'customer_name', 'type' => 'text', 'label' => 'Meno a priezvisko', 'required' => true, 'maxlength' => 160, 'autocomplete' => 'name'],
            ['name' => 'order_number', 'type' => 'text', 'label' => 'Číslo objednávky', 'required' => true, 'maxlength' => 100, 'autocomplete' => 'off'],
            ['name' => 'customer_email', 'type' => 'email', 'label' => 'E-mail na doručenie potvrdenia', 'required' => true, 'maxlength' => 254, 'autocomplete' => 'email'],
            ['name' => 'withdrawal_details', 'type' => 'textarea', 'label' => 'Rozsah odstúpenia (voliteľné)',
                'placeholder' => 'Ak odstupujete iba od časti objednávky, uveďte názvy a množstvá.', 'maxlength' => 2000, 'rows' => 4],
        ], 'on_submit' => ['store' => true, 'actions' => [['type' => 'eshop-withdrawal']]]];
    file_put_contents("$root/forms/odstupenie-od-zmluvy.json", json_encode($withdrawal, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    // Site-owned action, like aeon's vendor/bbf/actions/eshop-withdrawal.php.
    file_put_contents("$root/actions/eshop-withdrawal.php", '<?php file_put_contents($config["submissions_dir"] . "/withdrawal-action.json", json_encode($submission["data"] ?? null)); return null;');
    $base = ['api_token' => hash('sha256', 'fixture-admin-embedded-1'), 'forms_dir' => "$root/forms",
        'submissions_dir' => "$root/submissions", 'templates_dir' => "$root/templates", 'logs_dir' => "$root/logs",
        'storage' => 'file', 'lang' => 'sk', 'csrf' => true, 'rate_limit' => 1000, 'honeypot_field' => '_bbf_hp',
        'mail' => ['method' => 'mail', 'from_email' => 'sender@example.invalid']];
    $writeConfig = static function (array $config) use ($root): void {
        file_put_contents("$root/config.php", '<?php defined("BBF_LOADED") || exit; return ' . var_export($config, true) . ';');
        clearstatcache();
    };
    $writeConfig($base);
    $server = bbf_test_start_server($root, '127.0.0.1', bbf_test_port());
    $url = static function (string $path) use (&$server): string { return 'http://127.0.0.1:' . $server['port'] . '/' . $path; };
    $bbf = 'submit.php?form=odstupenie-od-zmluvy';

    // ── aeon contract, default standalone mode ─────────────────────
    $definition = bbf_test_http($server, $url("$bbf&action=definition"));
    eh_check($definition['code'] === 200 && ($definition['json']['id'] ?? null) === 'odstupenie-od-zmluvy'
        && array_column($definition['json']['fields'] ?? [], 'name') === ['customer_name', 'order_number', 'customer_email', 'withdrawal_details'],
        'aeon: action=definition returns the withdrawal form', substr($definition['body'], 0, 200));
    $csrf = bbf_test_http($server, $url("$bbf&action=csrf"));
    preg_match('/Set-Cookie:\s*([^=;\s]+=[^;\r\n]+)/i', $csrf['headers'], $cookie);
    eh_check($csrf['code'] === 200 && preg_match('/^[a-f0-9]{64}$/', (string)($csrf['json']['csrf_token'] ?? '')) === 1
        && isset($cookie[1]), 'aeon: action=csrf issues a 64-hex token with a session cookie');
    $post = bbf_test_http($server, $url($bbf), null, ['method' => 'POST', 'cookie' => $cookie[1],
        'headers' => ['Content-Type' => 'application/json'], 'raw' => json_encode([
            'customer_name' => 'Kontrakt Odstupenie', 'order_number' => 'AE-2026-0001', 'customer_email' => 'contract+wd@example.com',
            'withdrawal_details' => 'Kontrakt T5: odstúpenie od celej objednávky', '_bbf_csrf' => $csrf['json']['csrf_token']])]);
    eh_check($post['code'] === 200 && is_array($post['json']) && ($post['json']['success'] ?? null) !== false
        && !preg_match('/error/i', (string)($post['json']['status'] ?? '')), 'aeon: JSON POST is accepted (200, success !== false)',
        "HTTP {$post['code']} " . substr($post['body'], 0, 200));
    $action = json_decode((string)@file_get_contents("$root/submissions/withdrawal-action.json"), true);
    eh_check(($action['order_number'] ?? null) === 'AE-2026-0001', 'aeon: the site-owned eshop-withdrawal action received the submission');
    $bad = bbf_test_http($server, $url($bbf), null, ['method' => 'POST', 'cookie' => $cookie[1],
        'headers' => ['Content-Type' => 'application/json'], 'raw' => json_encode(['customer_name' => 'X', '_bbf_csrf' => $csrf['json']['csrf_token']])]);
    eh_check($bad['code'] === 422 && ($bad['json']['status'] ?? null) === 'error' && isset($bad['json']['errors']['order_number']), 'aeon: a missing required field is still refused with 422',
        "HTTP {$bad['code']} " . substr($bad['body'], 0, 200));

    // ── R7: 'standalone' => false closes every web entry point ─────
    bbf_test_stop_server($server); $server = null;
    bbf_test_remove_dir("$root/submissions"); mkdir("$root/submissions", 0700);
    $writeConfig($base + ['standalone' => false]);
    $logsBefore = glob("$root/logs/*") ?: [];
    $server = bbf_test_start_server($root, '127.0.0.1', bbf_test_port());
    eh_check(count($entryPoints) >= 10, 'every root entry point is enumerated', implode(',', $entryPoints));
    foreach ($entryPoints as $file) {
        foreach (["$file?form=odstupenie-od-zmluvy&action=definition", "$file?form=odstupenie-od-zmluvy&action=csrf"] as $path) {
            $r = bbf_test_http($server, $url($path));
            $allowed = $file === 'maintenance.php' ? [403, 404] : [404];
            eh_check(in_array($r['code'], $allowed, true) && !preg_match('/Set-Cookie:/i', $r['headers']),
                "standalone=false: GET $path answers {$r['code']} without a session", substr($r['body'], 0, 120));
        }
        $r = bbf_test_http($server, $url("$file?form=odstupenie-od-zmluvy"), null, ['method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'], 'raw' => json_encode(['customer_name' => 'X'])]);
        eh_check(in_array($r['code'], $file === 'maintenance.php' ? [403, 404] : [404], true) && !preg_match('/Set-Cookie:/i', $r['headers']),
            "standalone=false: POST $file answers {$r['code']} without a session");
    }
    eh_check((glob("$root/submissions/*") ?: []) === [], 'standalone=false: nothing was stored');
    $logsAfter = array_diff(glob("$root/logs/*") ?: [], $logsBefore, ["$root/logs/server-output.log", "$root/logs/server-error.log"]);
    eh_check($logsAfter === [], 'standalone=false: no rate-limit, alert or security log was written', implode(',', $logsAfter));
    $error = (string)@file_get_contents("$root/logs/server-error.log");
    eh_check(!preg_match('/PHP (Fatal|Warning|Deprecated|Notice)/', $error), 'no PHP warnings on the fixture server', substr($error, -300));
    print "Embedded host contract: $checks passed, 0 failed.\n";
} catch (Throwable $e) {
    print "FAIL " . $e->getMessage() . "\nEmbedded host contract: $checks passed, 1 failed.\n";
    exit(1);
} finally {
    if ($server) bbf_test_stop_server($server);
}
