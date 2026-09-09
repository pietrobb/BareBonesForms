<?php
/** G3/G2 integration: real HTTP routing with competing stores in owned private installations. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/test-isolation-helper.php';
$checks = 0; $failures = []; $skips = [];
function routing_check(bool $ok, string $label): void {
    global $checks, $failures;
    $checks++;
    if (!$ok) $failures[] = $label;
    print ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
}
function routing_json(string $path, array $data): void {
    file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
}
function routing_http(string $path, array $options = []): array {
    global $server;
    if (!isset($options['cookie']) && !isset($options['headers']['X-BBF-Token']))
        $options['headers']['X-BBF-Token'] ??= 'routing-fixture-all';
    return bbf_test_http($server, 'http://127.0.0.1:' . $server['port'] . '/' . $path, null, $options);
}
function routing_login(string $token = 'routing-fixture-all', string $page = 'viewer.php'): array {
    $r = routing_http($page, ['headers' => ['X-BBF-Token' => $token]]);
    preg_match('/Set-Cookie:\s*(PHPSESSID=[^;\r\n]+)/i', $r['headers'], $cookie);
    preg_match('/const TOKEN = ("[^"]+");/', $r['body'], $csrf);
    routing_check($r['code'] === 200 && isset($cookie[1], $csrf[1]), "session login $page $token");
    if (!isset($cookie[1], $csrf[1])) throw new RuntimeException('Cannot test mutations without real session/CSRF.');
    return ['cookie' => $cookie[1], 'csrf' => json_decode($csrf[1], true), 'body' => $r['body']];
}
function routing_mutate(string $action, string $form, array $session, array $ids = [], string $page = 'viewer.php'): array {
    return routing_http("$page?action=$action", ['cookie' => $session['cookie'], 'method' => 'POST',
        'headers' => ['Content-Type' => 'application/json', 'X-BBF-CSRF' => $session['csrf']],
        'raw' => json_encode(['form' => $form, 'id' => $ids[0] ?? $form, 'ids' => $ids])]);
}
function routing_editor_save(string $form, string $raw, array $session): array {
    $state = routing_http("editor.php?action=state&form=$form", ['cookie' => $session['cookie']]);
    $headers = ['Content-Type' => 'application/json', 'X-BBF-CSRF' => $session['csrf']];
    if (is_int($state['json']['revision'] ?? null)) $headers['X-BBF-Revision'] = (string)$state['json']['revision'];
    return routing_http("editor.php?action=save&form=$form", ['cookie' => $session['cookie'],
        'method' => 'POST', 'headers' => $headers, 'raw' => $raw]);
}
function routing_seed(string $root, PDO $pdo, string $form, string $backend, int $count, string $marker): void {
    $fp = null;
    if ($backend === 'file') mkdir("$root/submissions/$form", 0700);
    if ($backend === 'csv') {
        $fp = fopen("$root/submissions/$form.csv", 'w');
        fputcsv($fp, ['_id', '_submitted', '_ip', '_user_agent', 'answer'], ',', '"', '');
    }
    for ($i = 1; $i <= $count; $i++) {
        $id = "bbf_{$form}_$i";
        $stamp = gmdate('Y-m-d') . sprintf('T00:00:%02dZ', $i);
        $record = ['id' => $id, 'form' => $form, 'data' => ['answer' => "$marker-$i"], 'meta' => ['submitted' => $stamp]];
        if ($backend === 'file') routing_json("$root/submissions/$form/$id.json", $record);
        elseif ($backend === 'csv') fputcsv($fp, [$id, $stamp, '', '', "$marker-$i"], ',', '"', '');
        else $pdo->prepare('INSERT INTO bbf_submissions VALUES (?, ?, ?, ?, ?)')->execute([
            $id, $form, json_encode($record['data']), json_encode($record['meta']), $stamp]);
    }
    if ($fp) fclose($fp);
}
/** No links followed, and only caller-owned fixture data is inspected. */
function routing_snapshot(string $dir): array {
    $result = [];
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = "$dir/$entry";
        $result[$entry] = is_link($path) ? ['link' => readlink($path)] : (is_dir($path) ? routing_snapshot($path) : hash_file('sha256', $path));
    }
    return $result;
}
function routing_denied(string $form, array $session, string $tag): void {
    foreach (['viewer.php?action=submissions', 'viewer.php?action=detail', 'viewer.php?action=export',
              'viewer.php?action=print', 'viewer.php?action=stats', 'submissions.php?',
              'submissions.php?format=csv', 'submissions.php?id=bbf_' . strtolower($form) . '_1'] as $endpoint) {
        $r = routing_http($endpoint . '&form=' . $form . (str_contains($endpoint, 'action=detail') || str_contains($endpoint, 'action=print') ? '&id=bbf_' . strtolower($form) . '_1' : ''));
        routing_check($r['code'] === 403 && !str_contains($r['body'], 'ACTUAL-') && !str_contains($r['body'], 'DECOY-'), "$tag denied $endpoint HTTP {$r['code']}");
    }
    foreach (['delete', 'bulk_delete'] as $action) {
        $r = routing_mutate($action, $form, $session, ['bbf_' . strtolower($form) . '_1']);
        routing_check($r['code'] === 403, "$tag denied $action HTTP {$r['code']}");
    }
}
function routing_excluded(string $form, int $total, string $tag): void {
    $r = routing_http('viewer.php?action=dashboard');
    routing_check($r['code'] === 200 && ($r['json']['total'] ?? null) === $total
        && !in_array($form, array_column($r['json']['per_form'] ?? [], 'id'), true)
        && !str_contains($r['body'], 'ACTUAL-' . strtolower($form)), "$tag filtered before dashboard aggregation");
    $r = routing_http('viewer.php?action=list_forms');
    routing_check($r['code'] === 200 && !in_array($form, array_column($r['json'] ?? [], 'id'), true), "$tag excluded from enumeration");
    $r = routing_http('viewer.php');
    preg_match('/const CAN_DELETE = (\{[^\n]+?\});/', $r['body'], $m);
    $cap = isset($m[1]) ? json_decode($m[1], true) : [];
    routing_check($r['code'] === 200 && !array_key_exists($form, $cap['delete_forms'] ?? []), "$tag excluded from UI capabilities");
}
function routing_rename(string $from, string $to): void {
    // Two hops also change the actual stored spelling on case-insensitive Windows.
    if (!rename($from, $from . '.routing-temp') || !rename($from . '.routing-temp', $to))
        throw new RuntimeException('Cannot rename owned fixture.');
    clearstatcache();
}
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) throw new RuntimeException('SQLite is required, not an optional skipped backend.');
foreach (['file', 'csv', 'sqlite'] as $global) {
    $root = bbf_test_installation(dirname(__DIR__)); $server = null; $pdo = null;
    try {
        foreach (['viewer.php', 'editor.php'] as $file) bbf_test_copy(dirname(__DIR__) . '/' . $file, "$root/$file");
        bbf_test_copy(__FILE__, "$root/tests/review-storage-routing-test.php");
        bbf_test_remove_dir("$root/forms"); mkdir("$root/forms", 0700);
        $backends = ['alpha' => 'file', 'bravo' => 'csv', 'charlie' => 'sqlite'];
        $scope = ['alpha', 'bravo', 'charlie', 'ALPHA', 'BRAVO', 'CHARLIE', 'orphan', 'ORPHAN', 'newform'];
        $tokens = [];
        foreach (['all' => ['read', 'export', 'delete'], 'reader' => ['read']] as $id => $permissions)
            $tokens[] = ['id' => $id, 'token' => "routing-fixture-$id", 'forms' => $scope, 'permissions' => $permissions,
                'expires_at' => '2099-01-01T00:00:00Z', 'revoked' => false];
        $config = ['api_token' => 'routing-fixture-admin', 'access_tokens' => $tokens, 'storage' => $global,
            'forms_dir' => "$root/forms", 'submissions_dir' => "$root/submissions", 'logs_dir' => "$root/logs",
            'sqlite' => ['path' => "$root/submissions/bbf.sqlite"], 'lang' => 'en'];
        file_put_contents("$root/config.php", '<?php defined("BBF_LOADED") || exit; return ' . var_export($config, true) . ';');
        $pdo = new PDO('sqlite:' . $config['sqlite']['path'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        // Deliberately NOCASE: SQL scope must nevertheless use byte equality.
        $pdo->exec('CREATE TABLE bbf_submissions (id TEXT PRIMARY KEY, form_id TEXT COLLATE NOCASE, data TEXT, meta TEXT, created_at TEXT)');
        foreach ($backends as $form => $effective) {
            routing_json("$root/forms/$form.json", ['id' => $form, 'name' => $form, 'storage' => $effective,
                'fields' => [['name' => 'answer', 'type' => 'text', 'label' => 'Answer']]]);
            foreach (['file', 'csv', 'sqlite'] as $backend)
                routing_seed($root, $pdo, $form, $backend, $backend === $effective ? 3 : 5,
                    ($backend === $effective ? 'ACTUAL-' : 'DECOY-') . "$form-$backend");
        }
        routing_seed($root, $pdo, 'orphan', $global, 2, 'ORPHAN-exact');
        $pdo = null;
        $server = bbf_test_start_server($root, '127.0.0.1', bbf_test_port());
        routing_check(routing_http('tests/review-storage-routing-test.php')['code'] === 403, "$global HTTP CLI guard");
        $session = routing_login(); $reader = routing_login('routing-fixture-reader'); $editor = routing_login('routing-fixture-admin', 'editor.php'); $admin = ['cookie' => $editor['cookie']];
        preg_match('/const CAN_DELETE = (\{[^\n]+?\});/', $session['body'], $m);
        $cap = isset($m[1]) ? json_decode($m[1], true) : [];
        routing_check(($cap['delete_forms'] ?? null) === ['alpha' => true, 'bravo' => false, 'charlie' => true]
            && ($cap['permissions'] ?? []) === ['read', 'export', 'delete'], "$global per-form UI delete capability payload");
        preg_match('/const CAN_DELETE = (\{[^\n]+?\});/', $reader['body'], $m);
        $readerCap = isset($m[1]) ? json_decode($m[1], true) : [];
        routing_check(($readerCap['permissions'] ?? null) === ['read'] && ($readerCap['admin'] ?? null) === false,
            "$global read-only UI capability cannot grant delete");
        $r = routing_http('viewer.php?action=list_forms');
        routing_check($r['code'] === 200 && array_column($r['json'] ?? [], 'count', 'id') === ['alpha' => 3, 'bravo' => 3, 'charlie' => 3], "$global list counts effective stores, not five-row decoys");
        $r = routing_http('viewer.php?action=dashboard');
        routing_check($r['code'] === 200 && ($r['json']['total'] ?? null) === 9 && ($r['json']['today'] ?? null) === 9
            && ($r['json']['this_week'] ?? null) === 9 && count($r['json']['recent'] ?? []) === 9
            && !str_contains($r['body'], 'DECOY-') && !str_contains($r['body'], 'ORPHAN-'), "$global mixed dashboard aggregates and recents");
        foreach ($backends as $form => $effective) {
            $tag = "global-$global/form-$effective";
            foreach (["viewer.php?action=submissions&form=$form", "submissions.php?form=$form"] as $endpoint) {
                $r = routing_http($endpoint);
                routing_check($r['code'] === 200 && ($r['json']['total'] ?? null) === 3 && count($r['json']['submissions'] ?? []) === 3
                    && str_contains($r['body'], "ACTUAL-$form-$effective") && !str_contains($r['body'], 'DECOY-'), "$tag list $endpoint");
            }
            foreach (["viewer.php?action=detail&form=$form&id=bbf_{$form}_1", "submissions.php?form=$form&id=bbf_{$form}_1"] as $endpoint) {
                $r = routing_http($endpoint);
                routing_check($r['code'] === 200 && str_contains($r['body'], "ACTUAL-$form-$effective-1") && !str_contains($r['body'], 'DECOY-'), "$tag exact detail $endpoint");
            }
            foreach (["viewer.php?action=export&form=$form", "submissions.php?format=csv&form=$form"] as $endpoint) {
                $r = routing_http($endpoint);
                routing_check($r['code'] === 200 && substr_count($r['body'], "ACTUAL-$form-$effective-") === 3 && !str_contains($r['body'], 'DECOY-'), "$tag export $endpoint");
                routing_check(routing_http($endpoint, ['headers' => ['X-BBF-Token' => 'routing-fixture-reader']])['code'] === 403, "$tag read-only export denied");
            }
            $r = routing_http("viewer.php?action=stats&form=$form");
            routing_check($r['code'] === 200 && ($r['json'] ?? []) === ['total' => 3, 'today' => 3, 'this_week' => 3, 'this_month' => 3], "$tag stats effective backend");
            // Both spellings are in the token: denial must be identity, not scope filtering.
            $before = routing_snapshot("$root/submissions");
            routing_denied(strtoupper($form), $session, "$tag definition case alias");
            routing_check(routing_snapshot("$root/submissions") === $before, "$tag definition case denial preserves all stores"); routing_check(routing_http('editor.php?action=load&form=' . strtoupper($form), $admin)['code'] === 403, "$tag admin cannot load definition case alias"); routing_check(routing_http('editor.php?action=save&form=' . strtoupper($form), $admin + ['method' => 'POST', 'headers' => ['X-BBF-CSRF' => $editor['csrf']], 'raw' => '{}'])['code'] === 403, "$tag admin cannot save definition case alias");
            // Canonical definition, noncanonical EFFECTIVE data entry: the original G3/G2 gap.
            if ($effective !== 'sqlite') {
                $path = "$root/submissions/$form" . ($effective === 'csv' ? '.csv' : '');
                $alias = "$root/submissions/" . strtoupper($form) . ($effective === 'csv' ? '.csv' : '');
                routing_rename($path, $alias);
                $before = routing_snapshot("$root/submissions");
                routing_denied($form, $session, "$tag effective data case alias");
                routing_excluded($form, 6, "$tag effective data case alias");
                routing_check(routing_snapshot("$root/submissions") === $before, "$tag data alias denial preserves every backend"); $raw = file_get_contents("$root/forms/$form.json"); $r = routing_http("editor.php?action=load&form=$form", $admin); routing_check($r['code'] === 200 && $r['body'] === $raw, "$tag backend alias does not block definition load"); $r = routing_http('editor.php?action=list', $admin); routing_check($r['code'] === 200 && in_array($form, array_column($r['json'] ?? [], 'id'), true), "$tag backend alias does not hide editor definition"); $r = routing_editor_save($form, $raw, $editor); routing_check($r['code'] === 200 && routing_snapshot("$root/submissions") === $before, "$tag backend alias does not block safe definition save");
                routing_rename($alias, $path);
            }
            // All symlink targets stay INSIDE this owned fixture, including the definition target.
            foreach (['definition', 'data'] as $kind) {
                $path = $kind === 'definition' ? "$root/forms/$form.json" : ($effective === 'sqlite' ? $config['sqlite']['path'] : "$root/submissions/$form" . ($effective === 'csv' ? '.csv' : ''));
                $target = $path . '.routing-target';
                routing_rename($path, $target);
                if (@symlink($target, $path)) {
                    try {
                        $before = routing_snapshot("$root/submissions");
                        routing_denied($form, $session, "$tag $kind symlink");
                        routing_excluded($form, 6, "$tag $kind symlink");
                        routing_check(routing_snapshot("$root/submissions") === $before, "$tag $kind symlink denial preserves target and decoys");
                    } finally { unlink($path); routing_rename($target, $path); }
                } else {
                    routing_rename($target, $path);
                    $skips[] = "$tag $kind symlink: host lacks safe symlink capability";
                    print 'SKIP ' . end($skips) . "\n";
                }
            }
        }
        // Malformed existing definitions must not silently select a decoy global store or break aggregates.
        $original = file_get_contents("$root/forms/alpha.json");
        foreach (['{broken-secret', 'null', '42', '"not-a-definition"'] as $bad) {
            file_put_contents("$root/forms/alpha.json", $bad);
            $before = routing_snapshot("$root/submissions");
            routing_denied('alpha', $session, "$global invalid definition $bad");
            routing_excluded('alpha', 6, "$global invalid definition $bad");
            routing_check(routing_snapshot("$root/submissions") === $before, "$global invalid definition preserves data"); $r = routing_http('editor.php?action=load&form=alpha', $admin); routing_check($r['code'] === 200 && $r['body'] === $bad, "$global admin raw-loads malformed definition"); $r = routing_http('editor.php?action=list', $admin); routing_check($r['code'] === 200 && in_array('alpha', array_column($r['json'] ?? [], 'id'), true), "$global malformed definition remains repairable in list"); routing_check(routing_http('editor.php', $admin)['code'] === 200, "$global malformed definition does not break editor page"); $r = routing_http('editor.php?action=load&form=alpha'); routing_check($r['code'] === 403 && array_keys($r['json'] ?? []) === ['error'] && $r['body'] !== $bad, "$global scoped reader cannot obtain raw broken definition"); $r = routing_editor_save('alpha', $original, $editor); routing_check($r['code'] === 200 && file_get_contents("$root/forms/alpha.json") === $original && routing_snapshot("$root/submissions") === $before, "$global admin repairs JSON without response writes");
        }
        file_put_contents("$root/forms/alpha.json", $original);
        // A directory masquerading as an existing JSON definition also fails closed.
        unlink("$root/forms/alpha.json"); mkdir("$root/forms/alpha.json");
        routing_denied('alpha', $session, "$global nonregular definition");
        routing_excluded('alpha', 6, "$global nonregular definition"); routing_check(routing_http('editor.php?action=load&form=alpha', $admin)['code'] === 403, "$global editor refuses nonregular load"); routing_check(routing_http('editor.php?action=save&form=alpha', $admin + ['method' => 'POST', 'headers' => ['X-BBF-CSRF' => $editor['csrf']], 'raw' => $original])['code'] === 403 && is_dir("$root/forms/alpha.json") && !file_exists("$root/forms/alpha.json.tmp"), "$global editor refuses nonregular save without temp write");
        rmdir("$root/forms/alpha.json"); file_put_contents("$root/forms/alpha.json", $original);
        foreach (['viewer.php?action=submissions&form=orphan', 'viewer.php?action=detail&form=orphan&id=bbf_orphan_1',
                  'viewer.php?action=export&form=orphan', 'submissions.php?form=orphan', 'submissions.php?form=orphan&format=csv'] as $endpoint) {
            $r = routing_http($endpoint);
            routing_check($r['code'] === 200 && str_contains($r['body'], 'ORPHAN-exact'), "$global exact-scope orphan $endpoint");
        }
        $r = routing_http('submissions.php?form=ORPHAN');
        routing_check($global === 'sqlite' ? ($r['code'] === 200 && ($r['json']['total'] ?? null) === 0) : $r['code'] === 403, "$global orphan alias cannot expand exact storage identity");
        $r = routing_http('submissions.php?form=newform');
        routing_check($r['code'] === 200 && ($r['json']['total'] ?? null) === 0, "$global missing definition and data allowed without expansion");
        $admin = ['cookie' => $editor['cookie']];
        $r = routing_mutate('create', 'newform', $editor, [], 'editor.php');
        routing_check($r['code'] === 200 && is_file("$root/forms/newform.json"), "$global new editor definition can be created"); $bad = json_decode($original, true); $bad['storage'] = 'unavailable-backend'; routing_json("$root/forms/alpha.json", $bad); $r = routing_http('editor.php?action=load&form=alpha', $admin); routing_check($r['code'] === 200 && $r['json'] === $bad, "$global admin loads unavailable backend definition"); $r = routing_editor_save('alpha', $original, $editor); $draftState = routing_http('editor.php?action=state&form=alpha', ['cookie' => $editor['cookie']]); routing_check($r['code'] === 200 && ($draftState['json']['definition'] ?? null) == json_decode($original, true) && json_decode(file_get_contents("$root/forms/alpha.json"), true)['storage'] === 'unavailable-backend', "$global admin drafts change without publishing unavailable response backend"); file_put_contents("$root/forms/alpha.json", $original); routing_http('editor.php?action=state&form=alpha', ['cookie' => $editor['cookie']]);
        foreach ($backends as $form => $effective) {
            $tag = "global-$global/form-$effective";
            $before = routing_snapshot("$root/submissions");
            foreach (['delete', 'bulk_delete'] as $action)
                routing_check(routing_mutate($action, $form, $reader, ["bbf_{$form}_1"])['code'] === 403, "$tag read-only $action denied");
            routing_check(routing_snapshot("$root/submissions") === $before, "$tag permission denials preserve data");
            foreach (['delete' => ["bbf_{$form}_1"], 'bulk_delete' => ["bbf_{$form}_2", "bbf_{$form}_3", 'bbf_absent']] as $action => $ids) {
                $r = routing_mutate($action, $form, $session, $ids);
                routing_check($r['code'] === ($effective === 'csv' ? 400 : 200)
                    && ($effective === 'csv' || ($action === 'bulk_delete' ? ($r['json']['deleted'] ?? null) === 2 : ($r['json']['ok'] ?? null) === true)), "$tag $action capability and actual result HTTP {$r['code']}");
            }
            $r = routing_http("submissions.php?form=$form");
            routing_check($r['code'] === 200 && ($r['json']['total'] ?? null) === ($effective === 'csv' ? 3 : 0), "$tag independent API verifies deletion routing");
            foreach (['file', 'csv', 'sqlite'] as $backend) {
                if ($backend === $effective) continue;
                if ($backend === 'sqlite') {
                    $pdo = new PDO('sqlite:' . $config['sqlite']['path']);
                    $rows = $pdo->query("SELECT data FROM bbf_submissions WHERE form_id = '$form'")->fetchAll(PDO::FETCH_COLUMN);
                    routing_check(count($rows) === 5 && str_contains(implode('', $rows), "DECOY-$form-sqlite"), "$tag SQLite decoys preserved");
                    $pdo = null;
                } else {
                    $key = $form . ($backend === 'csv' ? '.csv' : '');
                    $after = routing_snapshot("$root/submissions");
                    routing_check($after[$key] === $before[$key], "$tag $backend decoys byte-for-byte preserved");
                }
            }
            if ($effective === 'csv') routing_check(routing_snapshot("$root/submissions") === $before, "$tag unsupported CSV mutations preserve all bytes");
        }
        $audit = file_get_contents("$root/logs/access-audit.php");
        routing_check(str_contains($audit, '"decision":"denied"') && str_contains($audit, '"action":"viewer_bulk_delete"')
            && !str_contains($audit, 'routing-fixture-') && !str_contains($audit, 'ACTUAL-') && !str_contains($audit, 'DECOY-') && !str_contains($audit, 'broken-secret'), "$global audit covers denials and mutations without secrets or records");
    } finally {
        $pdo = null;
        bbf_test_stop_server($server);
        bbf_test_cleanup($root);
    }
}
print 'Storage routing regression: ' . ($checks - count($failures)) . ' passed, ' . count($failures) . ' failed, ' . count($skips) . " optional symlink skips.\n";
foreach ($failures as $failure) print "FAILURE $failure\n";
exit($failures ? 1 : 0);
