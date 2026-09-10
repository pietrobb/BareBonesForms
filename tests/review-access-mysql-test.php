<?php
/**
 * G2 real MariaDB authorization / legacy case-insensitive collation regression.
 * Run: php tests/review-access-mysql-test.php (Windows, MariaDB 12.1.2, pdo_mysql).
 * Missing prerequisites FAIL, never skip. Overrides are executable paths ONLY:
 * BBF_TEST_MYSQLD_EXE and BBF_TEST_NETSTAT_EXE. No external DSN/data/config accepted.
 *
 * Isolation evidence independently reviewed against tag mariadb-12.1.2:
 * https://github.com/MariaDB/server/blob/mariadb-12.1.2/scripts/mysql_install_db.sh
 *   mysqld_install_cmd_line / cat_sql feed system-table SQL to --bootstrap.
 * https://github.com/MariaDB/server/blob/mariadb-12.1.2/sql/mysql_install_db.cc
 *   Windows also uses bootstrap, but its initializer uses --defaults-file=my.ini,
 *   NOT --no-defaults. We do not invoke that initializer or its service/ACL code.
 * Installed mysqld --no-defaults --verbose --help verifies the first-option rule.
 * Only the hash-pinned, reviewed system-table DDL is needed here; omit default
 * root accounts, help/test databases, plugins and sys-schema extras. Insert our
 * random-password account offline, before any listener can exist.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/test-isolation-helper.php';

$checks = 0; $failures = []; $collect = false;
function mysql_access_check(bool $ok, string $label): void {
    global $checks, $failures, $collect;
    if (!$ok) { if (!$collect) throw new RuntimeException($label); $failures[] = $label; fwrite(STDERR, "FAIL $label\n"); return; }
    $checks++;
    print "PASS $label\n";
}
function mysql_access_exe(string $variable, string $default): string {
    $path = getenv($variable) ?: $default;
    if (!preg_match('~\A[A-Za-z]:[/\\\\]~', $path) || is_link($path) || !is_file($path)) {
        throw new RuntimeException("Required executable unavailable: $variable ($path)");
    }
    return str_replace('\\', '/', realpath($path));
}
function mysql_access_alive($proc): bool {
    return is_resource($proc) && proc_get_status($proc)['running'];
}
function mysql_access_stop(&$proc): void {
    if (!is_resource($proc)) return;
    if (mysql_access_alive($proc) && !proc_terminate($proc)) {
        throw new RuntimeException('Cannot terminate owned child handle; retain fixture.');
    }
    $until = microtime(true) + 10;
    while (mysql_access_alive($proc) && microtime(true) < $until) usleep(50000);
    if (mysql_access_alive($proc)) throw new RuntimeException('Owned child still alive; retain fixture.');
    proc_close($proc);
    $proc = null;
}
function mysql_access_stop_all(array &$workers): void {
    $failure = null;
    foreach ($workers as $key => &$worker) {
        try { mysql_access_stop($worker); } catch (Throwable $error) { $failure ??= $error; }
        if ($worker === null) unset($workers[$key]);
    }
    unset($worker);
    if ($failure) throw $failure;
}
/** File descriptors avoid pipe deadlocks; all outputs and cwd are private. */
function mysql_access_spawn(array $command, string $root, string $label, array $env, ?string $input = null) {
    $proc = proc_open($command, [0 => $input === null ? ['pipe', 'r'] : ['file', $input, 'r'],
        1 => ['file', "$root/logs/$label.out", 'w'], 2 => ['file', "$root/logs/$label.err", 'w']],
        $pipes, $root, $env, ['bypass_shell' => true]);
    if (!is_resource($proc)) throw new RuntimeException("Cannot launch owned $label child.");
    if (isset($pipes[0])) fclose($pipes[0]);
    return $proc;
}
function mysql_access_command(array $command, string $root, string $label, array $env, int $timeout = 15): string {
    $proc = mysql_access_spawn($command, $root, $label, $env);
    try {
        $until = microtime(true) + $timeout;
        do {
            $status = proc_get_status($proc);
            if (!$status['running']) break;
            usleep(25000);
        } while (microtime(true) < $until);
        if ($status['running'] || $status['exitcode'] !== 0) {
            throw new RuntimeException("$label failed or timed out: " . file_get_contents("$root/logs/$label.err"));
        }
        return file_get_contents("$root/logs/$label.out");
    } finally { mysql_access_stop($proc); }
}
/** OS ownership, not a SQL probe: no client connects until all three proofs pass. */
function mysql_access_verify(array $db, bool $wait = false): void {
    $until = microtime(true) + ($wait ? 25 : 0);
    do {
        if (!mysql_access_alive($db['proc'])) throw new RuntimeException('Owned MariaDB exited; refusing clients.');
        clearstatcache();
        $pid = is_file($db['pidfile']) ? trim(file_get_contents($db['pidfile'])) : '';
        if ($pid !== '' && $pid !== (string)$db['pid']) throw new RuntimeException('MariaDB PID-file identity mismatch.');
        if ($pid !== '') {
            $output = mysql_access_command([$db['netstat'], '-ano', '-p', 'tcp'], $db['root'], 'listener-proof', $db['env']);
            $listeners = [];
            foreach (preg_split('/\r?\n/', $output) as $line) {
                $fields = preg_split('/\s+/', trim($line));
                if (count($fields) !== 5 || $fields[0] !== 'TCP' || $fields[3] !== 'LISTENING') continue;
                if ($fields[4] === (string)$db['pid']) $listeners[] = $fields[1];
                if ($fields[1] === '127.0.0.1:' . $db['port'] && $fields[4] !== (string)$db['pid']) {
                    throw new RuntimeException('Foreign listener / port collision; refusing database clients.');
                }
            }
            if ($listeners === ['127.0.0.1:' . $db['port']] && mysql_access_alive($db['proc'])) return;
            if ($listeners) throw new RuntimeException('Owned DB has an unexpected/non-loopback listener.');
        }
        if (!$wait) break;
        usleep(50000);
    } while (microtime(true) < $until);
    throw new RuntimeException('Could not prove owned MariaDB loopback listener identity.');
}

$root = null; $server = null; $bootstrap = null; $dbProc = null; $pdo = null; $raceWorkers = [];
// Registered BEFORE helper cleanup: stop owned children before deleting their data.
$cleanup = static function () use (&$root, &$server, &$bootstrap, &$dbProc, &$pdo, &$raceWorkers): void {
    $pdo = null;
    mysql_access_stop_all($raceWorkers);
    bbf_test_stop_server($server); $server = null;
    mysql_access_stop($bootstrap);
    mysql_access_stop($dbProc);
    if ($root !== null) { bbf_test_cleanup($root); $root = null; }
};
register_shutdown_function($cleanup);
$exitCode = 0;
try {
    mysql_access_check(PHP_OS_FAMILY === 'Windows', 'Windows ownership verifier required; unsupported platforms fail');
    mysql_access_check(!getenv('BBF_TEST_LEASE') && !getenv('BBF_TEST_LEASE_KEY'), 'no borrowed/supervised fixture accepted');
    mysql_access_check(extension_loaded('pdo_mysql'), 'real pdo_mysql required; no skipped backend');
    $mysqld = mysql_access_exe('BBF_TEST_MYSQLD_EXE', 'C:/Program Files/MariaDB 12.1/bin/mysqld.exe');
    $netstat = mysql_access_exe('BBF_TEST_NETSTAT_EXE', 'C:/Windows/System32/netstat.exe');
    $basedir = dirname(dirname($mysqld));
    $sqlPath = "$basedir/share/mariadb_system_tables.sql";
    mysql_access_check(!is_link($sqlPath) && is_file($sqlPath)
        && hash_file('sha256', $sqlPath) === '5ab42be469fa457d4901763e284c796b04d7826fa02177f42a299d9cdc1f06df',
        'bundled system-table SQL matches independently reviewed MariaDB 12.1.2 bytes');
    $root = bbf_test_installation(dirname(__DIR__));
    $env = ['SystemRoot' => getenv('SystemRoot') ?: 'C:/Windows', 'WINDIR' => getenv('WINDIR') ?: 'C:/Windows',
        'TEMP' => $root, 'TMP' => $root, 'HOME' => $root, 'USERPROFILE' => $root, 'APPDATA' => $root, 'MYSQL_HOME' => $root];
    $version = mysql_access_command([$mysqld, '--no-defaults', '--version'], $root, 'version', $env);
    mysql_access_check(str_contains($version, '12.1.2-MariaDB'), 'verified MariaDB 12.1.2 executable; other versions require safety review');
    mysql_access_command([$netstat, '-ano', '-p', 'tcp'], $root, 'netstat-prerequisite', $env);
    $datadir = "$root/mariadb-data";
    foreach ([$datadir, "$root/db-tmp", "$root/db-files", "$root/empty-plugins"] as $dir) {
        if (!mkdir($dir, 0700)) throw new RuntimeException('Cannot create unique database directory.');
    }
    $dbUser = 'bbf_' . bin2hex(random_bytes(8));
    $dbPassword = bin2hex(random_bytes(32));
    $passwordHash = '*' . strtoupper(sha1(sha1($dbPassword, true)));
    $identity = bin2hex(random_bytes(32));
    $sql = "CREATE DATABASE mysql;\nUSE mysql;\nSET @auth_root_socket=NULL;\n" . file_get_contents($sqlPath)
        . "\nDELETE FROM mysql.proxies_priv;\n"
        . "INSERT INTO mysql.global_priv (Host,User,Priv) VALUES ('127.0.0.1','$dbUser',"
        . "'{\"access\":18446744073709551615,\"plugin\":\"mysql_native_password\",\"authentication_string\":\"$passwordHash\"}');\n"
        . "CREATE DATABASE bbf_fixture CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;\n"
        . "CREATE TABLE bbf_fixture.owned_identity (nonce VARCHAR(64) NOT NULL);\n"
        . "INSERT INTO bbf_fixture.owned_identity VALUES ('$identity');\n-- end.";
    file_put_contents("$root/bootstrap.sql", $sql);
    $common = [$mysqld, '--no-defaults', '--console', "--basedir=$basedir", "--datadir=$datadir",
        "--tmpdir=$root/db-tmp", "--pid-file=$root/mariadb.pid", "--log-error=$root/logs/mariadb.log",
        "--aria-log-dir-path=$datadir", "--innodb-data-home-dir=$datadir", "--innodb-log-group-home-dir=$datadir",
        "--secure-file-priv=$root/db-files", "--plugin-dir=$root/empty-plugins",
        '--skip-log-bin', '--skip-slave-start', '--event-scheduler=DISABLED', '--local-infile=0',
        '--skip-name-resolve', '--skip-named-pipe', '--gssapi=OFF', '--extra-port=0',
        '--skip-core-file', '--feedback=OFF', '--performance-schema=OFF', '--innodb-buffer-pool-size=32M'];
    $bootstrap = mysql_access_spawn([...$common, '--bootstrap', '--skip-networking'], $root, 'bootstrap', $env, "$root/bootstrap.sql");
    $until = microtime(true) + 60;
    do {
        $status = proc_get_status($bootstrap);
        if (!$status['running']) break;
        usleep(50000);
    } while (microtime(true) < $until);
    mysql_access_check(!$status['running'] && $status['exitcode'] === 0, 'offline no-defaults bootstrap completed successfully');
    mysql_access_stop($bootstrap);
    unlink("$root/bootstrap.sql");
    $port = bbf_test_port(); $serverId = random_int(1, 2147483647);
    $dbProc = mysql_access_spawn([...$common, '--bind-address=127.0.0.1', "--port=$port", "--server-id=$serverId"],
        $root, 'database', $env);
    $db = ['proc' => $dbProc, 'pid' => proc_get_status($dbProc)['pid'], 'root' => $root,
        'pidfile' => "$root/mariadb.pid", 'port' => $port, 'netstat' => $netstat, 'env' => $env];
    mysql_access_verify($db, true);
    mysql_access_check(true, "pre-client PID-file/live-child/OS listener proof: child {$db['pid']} on 127.0.0.1:$port");
    $pdo = new PDO("mysql:host=127.0.0.1;port=$port;dbname=bbf_fixture;charset=utf8mb4", $dbUser, $dbPassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
    $actual = $pdo->query('SELECT @@datadir AS dir, @@port AS port, @@server_id AS sid')->fetch(PDO::FETCH_ASSOC);
    mysql_access_check(strcasecmp(rtrim(str_replace('\\', '/', $actual['dir']), '/'), str_replace('\\', '/', $datadir)) === 0
        && (int)$actual['port'] === $port && (int)$actual['sid'] === $serverId
        && hash_equals($identity, $pdo->query('SELECT nonce FROM owned_identity')->fetchColumn()),
        'authenticated SQL identity agrees with owned datadir/random server ID/bootstrap nonce');
    mysql_access_check((int)$pdo->query("SELECT COUNT(*) FROM mysql.global_priv WHERE User NOT IN ('$dbUser','mariadb.sys')")->fetchColumn() === 0,
        'no root, anonymous or inherited database accounts');

    bbf_test_copy(dirname(__DIR__) . '/viewer.php', "$root/viewer.php");
    bbf_test_remove_dir("$root/forms"); mkdir("$root/forms", 0700);
    $tokens = [];
    foreach (['reader' => ['read'], 'exporter' => ['read', 'export'], 'deleter' => ['read', 'delete'],
        'expiring' => ['read', 'export', 'delete'], 'revocable' => ['read', 'export', 'delete']] as $id => $permissions) {
        $tokens[$id] = ['id' => $id, 'token' => bin2hex(random_bytes(24)), 'forms' => ['alpha'],
            'permissions' => $permissions, 'expires_at' => gmdate('c', time() + 3600), 'revoked' => false];
    }
    $config = ['storage' => 'mysql', 'api_token' => bin2hex(random_bytes(24)), 'access_tokens' => array_values($tokens),
        'forms_dir' => "$root/forms", 'submissions_dir' => "$root/submissions", 'logs_dir' => "$root/logs", 'lang' => 'en',
        // Production endpoints build a DSN from host and do not have a separate port setting.
        'mysql' => ['host' => "127.0.0.1;port=$port", 'database' => 'bbf_fixture', 'charset' => 'utf8mb4',
            'username' => $dbUser, 'password' => $dbPassword]];
    $writeConfig = static function () use (&$config, $root): void {
        file_put_contents("$root/config.php", '<?php defined("BBF_LOADED") || exit; return ' . var_export($config, true) . ';');
    };
    $writeConfig();
    foreach (['alpha', 'beta'] as $form) {
        file_put_contents("$root/forms/$form.json", json_encode(['id' => $form, 'name' => "$form-name",
            'fields' => [['name' => 'answer', 'label' => 'Answer', 'type' => 'text']]], JSON_THROW_ON_ERROR));
    }
    $pdo->exec('CREATE TABLE bbf_submissions (id VARCHAR(64) PRIMARY KEY, form_id VARCHAR(64) NOT NULL,
        data LONGTEXT, meta LONGTEXT, created_at DATETIME) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
    $insert = $pdo->prepare('INSERT INTO bbf_submissions VALUES (?, ?, ?, ?, ?)');
    foreach (['alpha' => ['bbf_alpha', 'bbf_alpha_bulk'], 'beta' => ['bbf_beta'], 'ALPHA' => ['bbf_alias']] as $form => $ids) {
        foreach ($ids as $id) $insert->execute([$id, $form, json_encode(['answer' => "$form-confidential-answer"]),
            json_encode(['submitted' => gmdate('c')]), gmdate('Y-m-d H:i:s')]);
    }
    $insert = null;
    mysql_access_check((int)$pdo->query("SELECT COUNT(*) FROM bbf_submissions WHERE form_id='alpha'")->fetchColumn() === 3
        && (int)$pdo->query("SELECT COUNT(*) FROM bbf_submissions WHERE BINARY form_id=BINARY 'alpha'")->fetchColumn() === 2,
        'real CI collation reproduces alias match; binary comparison excludes foreign ALPHA row');
    for ($attempt = 0; $attempt < 3; ++$attempt) {
        try {
            $server = bbf_test_start_server($root, '127.0.0.1', bbf_test_port());
            break;
        } catch (RuntimeException $error) {
            if (!str_contains($error->getMessage(), 'Foreign listener / port collision') || $attempt === 2) throw $error;
        }
    }
    $base = 'http://127.0.0.1:' . $server['port'] . '/';
    $http = static function (string $path, array $options = []) use ($db, $server, $base): array {
        mysql_access_verify($db); // No endpoint/client request after child loss or ownership mismatch.
        $r = bbf_test_http($server, $base . $path, null, $options);
        if (!mysql_access_alive($db['proc'])) throw new RuntimeException('Database child died during endpoint request.');
        return $r;
    };
    $header = static fn(string $id) => ['headers' => ['X-BBF-Token' => $tokens[$id]['token']]];
    $login = static function (string $id) use ($http, $header): array {
        $r = $http('viewer.php', $header($id));
        preg_match('/Set-Cookie:\s*(PHPSESSID=[^;\r\n]+)/i', $r['headers'], $cookie);
        preg_match('/const TOKEN = ("[^"]+");/', $r['body'], $csrf);
        mysql_access_check($r['code'] === 200 && isset($cookie[1], $csrf[1]), "$id real viewer session + CSRF");
        return ['cookie' => $cookie[1], 'headers' => ['Content-Type' => 'application/json', 'X-BBF-CSRF' => json_decode($csrf[1], true)]];
    };
    $mutate = static function (string $action, array $session, string $form, array $ids) use ($http): array {
        return $http("viewer.php?action=$action", array_replace($session, ['method' => 'POST',
            'raw' => json_encode(['form' => $form, 'id' => $ids[0], 'ids' => $ids])]));
    };
    $collect = true; $reads = ['viewer.php?action=submissions', 'viewer.php?action=detail', 'submissions.php'];
    foreach ($reads as $path) {
        $join = str_contains($path, '?') ? '&' : '?';
        $r = $http($path . $join . 'form=alpha&id=bbf_alpha', $header('reader'));
        mysql_access_check($r['code'] === 200 && str_contains($r['body'], 'alpha-confidential-answer')
            && !str_contains($r['body'], 'ALPHA-confidential-answer'), "allowed binary-scoped read $path (HTTP {$r['code']}: {$r['body']})");
        foreach (['beta' => 'bbf_beta', 'ALPHA' => 'bbf_alias'] as $form => $id) {
            $r = $http($path . $join . "form=$form&id=$id", $header('reader'));
            mysql_access_check($r['code'] === 403 && !str_contains($r['body'], 'confidential-answer'), "cross-form/case alias $form denied $path");
        }
    }
    foreach (['viewer.php?action=detail', 'submissions.php'] as $path) {
        $r = $http($path . (str_contains($path, '?') ? '&' : '?') . 'form=alpha&id=bbf_alias', $header('reader'));
        mysql_access_check($r['code'] === 404 && !str_contains($r['body'], 'confidential-answer'), "CI foreign row cannot be fetched using permitted form $path");
    }
    foreach (['viewer.php?action=submissions&form=alpha', 'submissions.php?form=alpha',
        'viewer.php?action=stats&form=alpha', 'viewer.php?action=dashboard'] as $path) {
        $r = $http($path, $header('reader'));
        mysql_access_check($r['code'] === 200 && ($r['json']['total'] ?? null) === 2
            && !str_contains($r['body'], 'ALPHA') && !str_contains($r['body'], 'beta') && (!str_contains($path, 'submissions') || str_contains($r['body'], 'alpha-confidential-answer')), "binary counts/lists/aggregates $path");
    }
    $r = $http('viewer.php?action=list_forms', $header('reader'));
    mysql_access_check($r['code'] === 200 && count($r['json']) === 1 && $r['json'][0]['id'] === 'alpha'
        && $r['json'][0]['count'] === 2, 'form list count excludes CI alias and forbidden form');
    foreach (['viewer.php?action=export&form=', 'submissions.php?format=csv&form='] as $path) {
        foreach (['reader', 'deleter'] as $id) mysql_access_check($http($path . 'alpha', $header($id))['code'] === 403, "$id cannot export $path");
        $r = $http($path . 'alpha', $header('exporter'));
        mysql_access_check($r['code'] === 200 && str_contains($r['body'], 'alpha-confidential-answer')
            && !str_contains($r['body'], 'ALPHA') && !str_contains($r['body'], 'beta'), "positive export excludes CI foreign row $path");
        foreach (['beta', 'ALPHA'] as $form) mysql_access_check($http($path . $form, $header('exporter'))['code'] === 403, "exporter cross-form $form denied $path");
    }
    $sessions = [];
    foreach (array_keys($tokens) as $id) $sessions[$id] = $login($id);
    foreach (['reader', 'exporter', 'deleter'] as $id) {
        foreach (['delete', 'bulk_delete'] as $action) {
            foreach (['beta' => 'bbf_beta', 'ALPHA' => 'bbf_alias'] as $form => $record) {
                mysql_access_check($mutate($action, $sessions[$id], $form, [$record])['code'] === 403, "$id cross-form $form $action denied");
            }
            if ($id !== 'deleter') mysql_access_check($mutate($action, $sessions[$id], 'alpha', ['bbf_alpha'])['code'] === 403, "$id lacks $action permission");
        }
    }
    mysql_access_check($mutate('delete', $sessions['deleter'], 'alpha', ['bbf_alias'])['code'] === 404, 'single delete cannot match CI foreign form row');
    $r = $mutate('bulk_delete', $sessions['deleter'], 'alpha', ['bbf_alias', 'bbf_beta']);
    mysql_access_check($r['code'] === 200 && ($r['json']['deleted'] ?? null) === 0, 'bulk delete cannot match CI foreign form rows');
    mysql_access_check((int)$pdo->query('SELECT COUNT(*) FROM bbf_submissions')->fetchColumn() === 4, 'all denied/no-match mutations preserve actual SQL rows');

    foreach (['expiring', 'revocable'] as $id) {
        foreach (['submissions.php?form=alpha', 'viewer.php?action=export&form=alpha'] as $path) {
            mysql_access_check($http($path, $header($id))['code'] === 200, "$id valid before expiry/revocation $path");
        }
        foreach ($config['access_tokens'] as &$token) {
            if ($token['id'] !== $id) continue;
            if ($id === 'expiring') $token['expires_at'] = gmdate('c', time() - 60);
            else $token['revoked'] = true;
        }
        unset($token); $writeConfig();
        foreach ([$header($id), $sessions[$id]] as $transport => $options) {
            foreach (['submissions.php?form=alpha', 'viewer.php?action=detail&form=alpha&id=bbf_alpha',
                'viewer.php?action=export&form=alpha', 'submissions.php?format=csv&form=alpha'] as $path) {
                $r = $http($path, $options);
                mysql_access_check(in_array($r['code'], [401, 403], true) && !str_contains($r['body'], 'confidential-answer'),
                    "$id rejected for transport $transport $path");
            }
            foreach (['delete', 'bulk_delete'] as $action) mysql_access_check(in_array($mutate($action, $options, 'alpha', ['bbf_alpha'])['code'], [401, 403], true),
                "$id transport $transport cannot $action after config change");
        }
        mysql_access_check($http('submissions.php?form=alpha', $header('reader'))['code'] === 200
            && $http('viewer.php?action=export&form=alpha', $sessions['exporter'])['code'] === 200,
            "$id change does not revoke unrelated token/session");
    }
    mysql_access_check($mutate('delete', $sessions['deleter'], 'alpha', ['bbf_alpha'])['code'] === 200, 'permitted single deletion succeeds');
    $r = $mutate('bulk_delete', $sessions['deleter'], 'alpha', ['bbf_alpha_bulk', 'bbf_alias', 'bbf_beta']);
    mysql_access_check($r['code'] === 200 && ($r['json']['deleted'] ?? null) === 1, 'permitted bulk deletion removes only exact-form record');
    mysql_access_check($http('submissions.php?form=alpha&id=bbf_alpha', $header('reader'))['code'] === 404
        && $http('submissions.php?form=alpha', $header('reader'))['json']['total'] === 0, 'independent real API verifies successful deletion effects');
    $remaining = $pdo->query('SELECT id,form_id FROM bbf_submissions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    mysql_access_check($remaining === [['id' => 'bbf_alias', 'form_id' => 'ALPHA'], ['id' => 'bbf_beta', 'form_id' => 'beta']],
        'SQL verifies forbidden beta and CI ALPHA records intact after all mutations');
    $r = $http('submissions.php?form=beta&id=bbf_beta', ['headers' => ['X-BBF-Token' => $config['api_token']]]);
    mysql_access_check($r['code'] === 200 && str_contains($r['body'], 'beta-confidential-answer'), 'administrator positive cross-form read control');

    $audit = file_get_contents("$root/logs/access-audit.php");
    mysql_access_check(str_starts_with($audit, '<?php http_response_code(404); exit; ?>'), 'audit is PHP-guarded');
    $events = [];
    foreach (explode("\n", $audit) as $line) {
        if (!str_starts_with($line, '{')) continue;
        $events[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    }
    foreach (['api_detail', 'viewer_export', 'viewer_delete', 'viewer_bulk_delete'] as $action) {
        foreach (['allowed', 'denied'] as $decision) {
            mysql_access_check((bool)array_filter($events, static fn($e) => ($e['action'] ?? '') === $action
                && ($e['decision'] ?? '') === $decision && ($decision === 'denied' || ($e['result'] ?? '') === 'completed')),
                "real $action $decision audit event");
        }
    }
    foreach ([$dbPassword, $config['api_token'], ...array_column($tokens, 'token'), 'confidential-answer'] as $secret) {
        mysql_access_check(!str_contains($audit, $secret), 'audit excludes generated credential/response secret');
    }
    // G3 extends this SAME verified disposable server. Keep all G2 rows/table intact.
    $g3Start = $checks;
    define('BBF_LOADED', true);
    require "$root/bbf_functions.php";
    require "$root/bbf_auth.php";
    require "$root/bbf_read.php";
    require "$root/bbf_export.php";
    require "$root/bbf_review.php";
    require_once "$root/bbf_outbox.php";
    require_once "$root/bbf_retention.php";
    require_once "$root/bbf_backup.php";
    file_put_contents("$root/tests/mysql-payment-worker.php", <<<'PHP'
<?php
if (PHP_SAPI !== 'cli') exit(2);
define('BBF_LOADED', true);
$config = require $argv[1];
require $argv[2] . '/bbf_functions.php';
$result = transitionSubmissionPayment($argv[3], $argv[4], $argv[5], [
    'id' => 'cs_mysql_race', 'payment_intent' => 'pi_mysql_race',
    'amount_total' => 1250, 'currency' => 'eur',
], $config);
echo json_encode($result, JSON_THROW_ON_ERROR);
exit(($result['ok'] ?? false) ? 0 : 1);
PHP);
    // Verbatim declarations only, as in review-storage-test.php: never execute an
    // endpoint bootstrap in this parent, and never load the operator config.
    $source = file_get_contents("$root/submit.php");
    $start = strpos($source, 'function store(array ');
    $end = $start === false ? false : strpos($source, '// sendEmail, sendSmtp', $start);
    $paymentSource = file_get_contents("$root/payment.php");
    $loadStart = strpos($paymentSource, 'function loadSubmission(');
    if ($start === false || $end === false || $loadStart === false) throw new RuntimeException('Storage/payment declaration boundaries changed.');
    file_put_contents("$root/tests/mysql-functions.php", '<?php if (PHP_SAPI !== "cli") exit; '
        . substr($source, $start, $end - $start) . substr($paymentSource, $loadStart));
    require "$root/tests/mysql-functions.php";
    mysql_access_verify($db);
    $pdo->exec('RENAME TABLE bbf_submissions TO g2_preserved_submissions');
    $config += ['templates_dir' => "$root/templates", 'csrf' => false, 'honeypot_field' => '_hp',
        'rate_limit' => 1000, 'error_notify' => '',
        'stripe' => ['secret_key' => 'sk_test_never_used', 'webhook_secret' => bin2hex(random_bytes(32))]];
    $config['storage'] = 'file'; // Every G3 route must honor the form's MySQL override.
    $writeConfig();
    $form = ['id' => 'persist', 'name' => 'Unicode persistence', 'storage' => 'mysql',
        'fields' => [['name' => 'answer', 'type' => 'text']], 'on_submit' => ['store' => true]];
    file_put_contents("$root/forms/persist.json", bbf_storage_json($form));
    $store = static function (array $record) use ($db, $config): bool {
        mysql_access_verify($db); return storeMysql($record, $config['mysql']);
    };
    $load = static function (string $id, string $formId = 'persist') use ($db, $config): ?array {
        mysql_access_verify($db); return loadSubmission($id, $formId, $config);
    };
    $pay = static function (string $id, array $stripe, string $formId = 'persist') use ($db, $config): bool {
        mysql_access_verify($db); return updateSubmissionPayment($id, $formId, 'paid', $stripe, $config);
    };
    $snapshot = static function () use ($db, $pdo): array {
        mysql_access_verify($db); return $pdo->query('SELECT * FROM bbf_submissions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    };
    $unicode = "Žluťoučký 日本語 😀, \"quoted\"\nnext \\ line";
    $record = ['id' => 'bbf_mysql_valid', 'form' => 'persist',
        'data' => ['answer' => $unicode, 'retired' => 'historical', 'nested' => ['zero' => 0, 'values' => ['č', '😀']]],
        'meta' => ['submitted' => '2020-01-01T12:00:00Z', 'ip' => '127.0.0.1', 'user_agent' => 'fixture', 'note' => '元😀']];
    mysql_access_check($store($record), 'G3 real storeMysql succeeds and auto-creates missing schema');
    $columns = $pdo->query('SHOW COLUMNS FROM bbf_submissions')->fetchAll(PDO::FETCH_ASSOC);
    $ddl = $pdo->query('SHOW CREATE TABLE bbf_submissions')->fetch(PDO::FETCH_NUM)[1];
    mysql_access_check(array_column($columns, 'Field') === ['id', 'form_id', 'data', 'meta', 'created_at']
        && str_contains($ddl, 'idx_form') && str_contains($ddl, 'idx_created') && str_contains($ddl, 'utf8mb4'),
        'G3 runtime-created schema has expected columns, indexes and four-byte charset');
    mysql_access_check(str_contains(strtoupper($ddl), 'ENGINE=INNODB'),
        'G4 payment transitions require and verify a transactional InnoDB table');
    $raceRecord = $record;
    $raceRecord['id'] = 'bbf_mysql_payment_race';
    $raceRecord['meta']['payment_status'] = 'pending';
    mysql_access_check($store($raceRecord), 'G4 real MySQL payment race fixture stored');
    mysql_access_verify($db);
    $paymentWorkers = [];
    foreach (array_merge(array_fill(0, 8, 'failed'), array_fill(0, 8, 'paid')) as $index => $status) {
        $label = "payment-worker-$index";
        $paymentWorkers[] = [$label, mysql_access_spawn([
            PHP_BINARY, "$root/tests/mysql-payment-worker.php", "$root/config.php", $root,
            $raceRecord['id'], 'persist', $status,
        ], $root, $label, $env)];
    }
    $paymentWorkersOk = 0;
    foreach ($paymentWorkers as [$label, $worker]) {
        $until = microtime(true) + 20;
        while (mysql_access_alive($worker) && microtime(true) < $until) usleep(25000);
        $result = json_decode((string)file_get_contents("$root/logs/$label.out"), true);
        if (($result['ok'] ?? false) && in_array($result['status'] ?? null, ['failed', 'paid'], true)) ++$paymentWorkersOk;
        mysql_access_stop($worker);
    }
    mysql_access_verify($db);
    $raceMeta = json_decode((string)$pdo->query("SELECT meta FROM bbf_submissions WHERE id = 'bbf_mysql_payment_race'")->fetchColumn(), true);
    mysql_access_check(count($paymentWorkers) === 16 && $paymentWorkersOk === 16
        && ($raceMeta['payment_status'] ?? null) === 'paid',
        'G4 concurrent real MySQL paid/failed transitions lock one InnoDB row and paid remains terminal');
    $pdo->exec("DELETE FROM bbf_submissions WHERE id = 'bbf_mysql_payment_race'");
    mysql_access_check($load($record['id']) === $record, 'G3 callback loader resolves MySQL over global file and round-trips Unicode/nested/meta');
    $before = $snapshot();
    mysql_access_check(!empty($before[0]['created_at']) && json_decode($before[0]['data'], true) === $record['data'],
        'G3 independent SQL verifies durable nonempty JSON and generated timestamp');
    foreach (['data', 'meta', 'id', 'form'] as $part) {
        $invalid = $record;
        if (in_array($part, ['id', 'form'], true)) $invalid[$part] = "invalid_\xff";
        else { $invalid['id'] = 'bbf_invalid_' . $part; $invalid[$part]['invalid'] = "\xff"; }
        mysql_access_check(!$store($invalid) && $snapshot() === $before, "G3 invalid UTF8 $part rejected with exact SQL snapshot preserved");
    }
    mysql_access_check(!$store($record) && $snapshot() === $before, 'G3 duplicate primary key fails without overwriting existing data');
    $form['fields'] = [['name' => 'details', 'type' => 'group', 'fields' => [['name' => 'answer', 'type' => 'text'], ['name' => 'new_field', 'type' => 'text']]]];
    file_put_contents("$root/forms/persist.json", bbf_storage_json($form));
    $post = static fn(array $data): array => ['raw' => http_build_query($data), 'method' => 'POST'];
    $r = $http('submit.php?form=persist', $post(['answer' => $unicode, 'new_field' => 'schema evolved č😀']));
    mysql_access_check($r['code'] === 200 && !empty($r['json']['submission_id']), "G3 real HTTP submit MySQL override succeeds (HTTP {$r['code']}: {$r['body']})");
    $submittedId = $r['json']['submission_id'] ?? 'bbf_not_created';
    $submitted = $load($submittedId);
    mysql_access_check(($submitted['data'] ?? null) === ['answer' => $unicode, 'new_field' => 'schema evolved č😀']
        && $load($record['id']) === $record && count($snapshot()) === 2,
        'G3 evolved form stores flattened group fields and preserves historical schema/data');
    mysql_access_check(preg_match('/\Av1-[a-f0-9]{64}\z/D', $submitted['meta']['definition_version'] ?? '') === 1
        && ($submitted['meta']['form_definition']['id'] ?? '') === 'persist'
        && !isset($submitted['meta']['form_definition']['on_submit']),
        'G8 real MySQL round-trips the immutable version and safe historical definition snapshot');
    mysql_access_check(!is_dir("$root/submissions/persist") && !file_exists("$root/submissions/persist.csv"),
        'G3 effective MySQL submit creates no global-file or CSV shadow records');

    $repeatableGroup = ['name' => 'items', 'type' => 'group', 'repeatable' => true, 'min_items' => 1, 'max_items' => 2,
        'fields' => [
            ['name' => 'sku', 'type' => 'text', 'required' => true],
            ['name' => 'kind', 'type' => 'select', 'options' => ['normal', 'special']],
            ['name' => 'detail', 'type' => 'text', 'required' => true, 'show_if' => ['field' => 'kind', 'value' => 'special']],
            ['name' => 'tags', 'type' => 'checkbox', 'options' => ['fragile', 'gift']],
        ]];
    $repeatableForm = $form;
    $repeatableForm['fields'] = [$repeatableGroup, ['name' => 'details', 'type' => 'group', 'fields' => [
        ['name' => 'new_field', 'type' => 'text'],
    ]]];
    file_put_contents("$root/forms/persist.json", bbf_storage_json($repeatableForm));
    $repeatableItems = [
        ['sku' => ' A-1 ', 'kind' => 'normal', 'detail' => '', 'tags' => ['gift']],
        ['sku' => 'B-2', 'kind' => 'special', 'detail' => 'Cold', 'tags' => []],
    ];
    $expectedRepeatableItems = [
        ['sku' => 'A-1', 'kind' => 'normal', 'tags' => ['gift']],
        ['sku' => 'B-2', 'kind' => 'special', 'detail' => 'Cold', 'tags' => []],
    ];
    $repeatableResponse = $http('submit.php?form=persist', [
        'method' => 'POST', 'headers' => ['Content-Type' => 'application/json'],
        'raw' => bbf_storage_json(['items' => $repeatableItems, 'new_field' => 'repeatable mysql č😀']),
    ]);
    $repeatableId = $repeatableResponse['json']['submission_id'] ?? 'bbf_not_created';
    mysql_access_check($repeatableResponse['code'] === 200 && ($repeatableResponse['json']['status'] ?? '') === 'ok',
        "F7 real HTTP repeatable MySQL submit succeeds (HTTP {$repeatableResponse['code']}: {$repeatableResponse['body']})");
    $repeatableStored = $load($repeatableId);
    mysql_access_check(($repeatableStored['data']['items'] ?? null) === $expectedRepeatableItems
        && ($repeatableStored['data']['new_field'] ?? null) === 'repeatable mysql č😀',
        'F7 callback loader round-trips stable repeatable row objects through real MySQL');
    $repeatableSql = $pdo->prepare('SELECT data FROM bbf_submissions WHERE id = ?');
    $repeatableSql->execute([$repeatableId]);
    $repeatableSqlData = json_decode((string)$repeatableSql->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    $repeatableSql->closeCursor();
    mysql_access_check(($repeatableSqlData['items'] ?? null) === $expectedRepeatableItems,
        'F7 independent SQL verifies durable nested repeatable JSON');
    $repeatableAdmin = ['headers' => ['X-BBF-Token' => $config['api_token']]];
    $repeatableDetail = $http('submissions.php?form=persist&id=' . rawurlencode($repeatableId), $repeatableAdmin);
    mysql_access_check($repeatableDetail['code'] === 200
        && ($repeatableDetail['json']['data']['items'] ?? null) === $expectedRepeatableItems,
        'F7 authenticated API detail returns structured repeatable MySQL rows');
    $repeatableExport = $http('submissions.php?format=csv&form=persist', $repeatableAdmin);
    $repeatableStream = fopen('php://temp', 'w+b');
    fwrite($repeatableStream, $repeatableExport['body']); rewind($repeatableStream);
    $repeatableHeader = fgetcsv($repeatableStream, 0, ',', '"', '');
    if (isset($repeatableHeader[0])) $repeatableHeader[0] = preg_replace('/^\xEF\xBB\xBF/', '', $repeatableHeader[0]);
    $repeatableRows = [];
    while (($repeatableRow = fgetcsv($repeatableStream, 0, ',', '"', '')) !== false) {
        if (count($repeatableRow) !== count($repeatableHeader)) throw new RuntimeException('Misaligned repeatable MySQL export row.');
        $repeatableRows[] = array_combine($repeatableHeader, $repeatableRow);
    }
    fclose($repeatableStream);
    $repeatableById = [];
    foreach ($repeatableRows as $repeatableRow) $repeatableById[$repeatableRow['id']] = $repeatableRow;
    mysql_access_check($repeatableExport['code'] === 200
        && ($repeatableById[$repeatableId]['items'] ?? null) === bbf_storage_json($expectedRepeatableItems)
        && ($repeatableById[$submittedId]['items'] ?? null) === '',
        'F7 HTTP MySQL export emits one deterministic JSON cell and preserves historical static row');
    $deleteRepeatable = $pdo->prepare('DELETE FROM bbf_submissions WHERE id = ?');
    $deleteRepeatable->execute([$repeatableId]);
    $deleteRepeatable = null;
    file_put_contents("$root/forms/persist.json", bbf_storage_json($form));
    mysql_access_check(count($snapshot()) === 2, 'F7 repeatable probe cleanup restores prior MySQL fixture cardinality');

    $before = $snapshot();
    $r = $http('submit.php?form=persist', $post(['answer' => "\xff", 'new_field' => 'must not persist']));
    mysql_access_check($r['code'] === 500 && ($r['json']['status'] ?? '') === 'error' && $snapshot() === $before,
        "G3 HTTP invalid UTF8 returns error and loses/adds no SQL data (HTTP {$r['code']})");
    // This action writes ONLY to the owned fixture; no payment creation, mail or webhook.
    file_put_contents("$root/actions/mysql-probe.php", '<?php file_put_contents(__DIR__ . "/../data/mysql-action.json", json_encode($submission, JSON_THROW_ON_ERROR));');
    $form['on_submit']['actions'] = [['type' => 'mysql-probe']];
    $form['on_submit']['payment'] = ['provider' => 'stripe', 'mode' => 'fixed', 'pricing_version' => 'mysql-fixture-v1',
        'currency' => 'eur', 'amount_minor' => 1250];
    file_put_contents("$root/forms/persist.json", bbf_storage_json($form));
    mysql_access_check(updateSubmissionPaymentMetadata($submittedId, 'persist', [
        'payment_status' => 'pending', 'payment_expected_amount_minor' => 1250,
        'payment_expected_currency' => 'eur', 'payment_checkout_session_id' => 'cs_local_fixture',
    ], $config), 'G4 MySQL callback fixture persists trusted payment expectation');
    $before = $snapshot();
    $stripe = ['id' => 'cs_local_fixture', 'payment_intent' => 'pi_local_fixture', 'amount_total' => 1250, 'currency' => 'eur',
        'payment_status' => 'paid', 'metadata' => ['bbf_submission_id' => $submittedId, 'bbf_form_id' => 'persist']];
    $callback = static function (array $session, bool $valid = true) use ($http, $config): array {
        $payload = bbf_storage_json(['id' => 'evt_mysql_fixture', 'type' => 'checkout.session.completed', 'data' => ['object' => $session]]);
        $time = time(); $sig = hash_hmac('sha256', "$time.$payload", $valid ? $config['stripe']['webhook_secret'] : 'wrong-local-secret');
        return $http('payment.php', ['raw' => $payload, 'headers' => ['Content-Type' => 'application/json', 'Stripe-Signature' => "t=$time,v1=$sig"]]);
    };
    $r = $callback($stripe, false);
    mysql_access_check($r['code'] === 400 && $snapshot() === $before && !file_exists("$root/data/mysql-action.json"),
        'G3 invalid locally signed callback cannot update SQL or run action');
    $r = $callback($stripe);
    $seen = is_file("$root/data/mysql-action.json") ? json_decode(file_get_contents("$root/data/mysql-action.json"), true) : null;
    $paid = $load($submittedId);
    mysql_access_check($r['code'] === 200 && ($r['json']['received'] ?? false) === true && $seen === $paid
        && ($paid['data'] ?? null) === $submitted['data'] && ($paid['meta']['payment_status'] ?? '') === 'paid',
        'G3 locally signed HTTP callback UPDATE then load uses effective MySQL and passes durable row to local action');
    mysql_access_check(($paid['meta']['payment_id'] ?? '') === 'pi_local_fixture' && ($paid['meta']['payment_amount'] ?? null) === 12.5
        && ($paid['meta']['payment_currency'] ?? '') === 'eur' && !empty($paid['meta']['payment_time'])
        && array_intersect_key($paid['meta'], $submitted['meta']) === $submitted['meta'] && $load($record['id']) === $record,
        'G3 payment metadata round-trips while original metadata/data and other row remain intact');
    $before = $snapshot();
    mysql_access_check(!$pay($submittedId, ['payment_intent' => "\xff"]) && $snapshot() === $before,
        'G3 invalid UTF8 payment metadata cannot overwrite durable paid row');
    mysql_access_check(!$pay('bbf_missing', $stripe) && $load('bbf_missing') === null && $snapshot() === $before,
        'G3 missing payment UPDATE/load fails without inserting rows');
    file_put_contents("$root/forms/PERSIST.json", bbf_storage_json(array_replace($form, ['id' => 'PERSIST'])));
    mysql_access_check(!$pay($submittedId, $stripe, 'PERSIST') && $load($submittedId, 'PERSIST') === null && $snapshot() === $before,
        'G3 payment UPDATE/load excludes case-insensitive foreign form match');
    if (is_file("$root/data/mysql-action.json")) unlink("$root/data/mysql-action.json");
    // Legacy malformed LONGTEXT remains readable by the DB: preserve it on failed callback.
    $pdo->exec('SET SESSION check_constraint_checks=OFF'); // This owned connection only; inject a legacy invalid row.
    $stmt = $pdo->prepare('UPDATE bbf_submissions SET meta = ? WHERE id = ?');
    try { $stmt->execute(['{broken', $submittedId]); } finally { $pdo->exec('SET SESSION check_constraint_checks=ON'); $stmt = null; }
    $before = $snapshot();
    $r = $callback($stripe);
    mysql_access_check($r['code'] === 500 && $snapshot() === $before && !file_exists("$root/data/mysql-action.json"),
        'G3 corrupt historical MySQL meta produces callback 500, preserves exact bytes and stops deferred action');

    // Independent historical oracle: 257 timestamp-distinct rows exceed old default 100.
    // Setup uses SQL, not the reader/filter/export code being tested.
    $historyForm = ['id' => 'history', 'name' => 'Historical stream', 'storage' => 'mysql',
        'fields' => [['name' => 'answer', 'type' => 'text'], ['type' => 'group', 'fields' => [['name' => 'new_field', 'type' => 'text']]]]];
    file_put_contents("$root/forms/history.json", bbf_storage_json($historyForm));
    $insert = $pdo->prepare('INSERT INTO bbf_submissions (id,form_id,data,meta,created_at) VALUES (?,?,?,?,?)');
    $allIds = []; $historyData = [];
    $pdo->beginTransaction();
    for ($i = 0; $i < 257; ++$i) {
        $id = sprintf('bbf_history_%04d', $i); $allIds[] = $id;
        $date = $i === 0 ? '2001-01-01 00:00:00' : ($i === 256 ? '2020-02-04 00:00:00' : '2020-02-03 ' . gmdate('H:i:s', $i));
        if ($i === 255) $date = '2020-02-03 23:59:59';
        $data = ['answer' => "ordinary-$i", 'field_name_only' => 'not a key match'];
        if ($i === 0) $data += ['retired_oldest' => $unicode];
        if ($i === 10) $data['answer'] = 'literal 100% done';
        if ($i === 20) $data['answer'] = 'literal under_score';
        if ($i === 30) $data['answer'] = 'literal back\\slash';
        if ($i === 40) $data['answer'] = 'ŽLUŤOUČKÝ';
        if ($i === 50) $data['answer'] = '"quoted"';
        if ($i === 60) $data['nested'] = ['inside' => ['deep needle']];
        if ($i === 70) { $data['left'] = 'split'; $data['right'] = 'value'; }
        $historyData[$id] = $data;
        $insert->execute([$id, 'history', bbf_storage_json($data), bbf_storage_json(['submitted' => str_replace(' ', 'T', $date) . 'Z']), $date]);
    }
    $insert->execute(['bbf_history_alias', 'HISTORY', '{"answer":"alias-only"}', '{}', '2025-01-01 00:00:00']);
    $pdo->commit(); $insert = null; $allIds = array_reverse($allIds);
    $admin = ['headers' => ['X-BBF-Token' => $config['api_token']]];
    $cases = [
        'all' => [[], $allIds],
        'percent literal' => [['q' => '%'], ['bbf_history_0010']],
        'underscore literal' => [['q' => '_'], ['bbf_history_0020']],
        'backslash literal' => [['q' => '\\'], ['bbf_history_0030', 'bbf_history_0000']],
        'unicode casefold' => [['q' => 'žluťoučký'], ['bbf_history_0040', 'bbf_history_0000']],
        'decoded quote' => [['q' => '"quoted"'], ['bbf_history_0050', 'bbf_history_0000']],
        'nested value' => [['q' => 'deep needle'], ['bbf_history_0060']],
        'keys excluded' => [['q' => 'field_name_only'], []],
        'separate fields not concatenated' => [['q' => 'split value'], []],
        'no SQL injection' => [['q' => "%' OR 1=1 --"], []],
        'whole day including final second' => [['from' => '2020-02-03', 'to' => '2020-02-03'], array_slice($allIds, 1, 255)],
        'ISO lower bound' => [['from' => '2020-02-03T23:59:59Z', 'to' => '2020-02-03'], ['bbf_history_0255']],
        'SQL lower bound' => [['from' => '2020-02-03 23:59:59', 'to' => '2020-02-03'], ['bbf_history_0255']],
        'next midnight excluded' => [['to' => '2020-02-02'], ['bbf_history_0000']],
        'q and dates intersect' => [['q' => '%', 'from' => '2020-02-04'], []],
    ];
    $csvRows = static function (string $body): array {
        if ($body === "No submissions\n") return [];
        $fp = fopen('php://temp', 'w+b'); fwrite($fp, $body); rewind($fp); $rows = [];
        try {
            $headers = fgetcsv($fp, 0, ',', '"', '');
            if (isset($headers[0])) $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
            while (($row = fgetcsv($fp, 0, ',', '"', '')) !== false) {
                if (count($row) !== count($headers)) throw new RuntimeException('Misaligned MySQL export row.');
                $rows[] = array_combine($headers, $row);
            }
        } finally { fclose($fp); }
        return $rows;
    };
    foreach ($cases as $label => [$filters, $expected]) {
        mysql_access_verify($db);
        $rows = iterator_to_array(bbf_read_db($pdo, 'history', $filters['from'] ?? null, $filters['to'] ?? null, $filters['q'] ?? null), false);
        mysql_access_check(array_column($rows, 'id') === $expected, "G3 shared real MySQL stream $label exact IDs/order");
        foreach (['viewer.php?action=submissions&', 'submissions.php?'] as $path) {
            $r = $http($path . http_build_query(['form' => 'history', 'limit' => 7, 'offset' => 1] + $filters), $admin);
            mysql_access_check($r['code'] === 200 && ($r['json']['total'] ?? null) === count($expected)
                && array_column($r['json']['submissions'] ?? [], 'id') === array_slice($expected, 1, 7),
                "G3 HTTP MySQL list/search $label $path total and page IDs");
        }
        foreach (['viewer.php?action=export&', 'submissions.php?format=csv&'] as $path) {
            $r = $http($path . http_build_query(['form' => 'history'] + $filters), $admin);
            $csv = $csvRows($r['body']);
            mysql_access_check($r['code'] === 200 && array_column($csv, 'id') === $expected,
                "G3 streamed HTTP MySQL export $label $path complete selected IDs/order");
            if ($label === 'all') {
                mysql_access_check(count($csv) === 257 && $csv[256]['retired_oldest'] === $unicode
                    && $csv[0]['retired_oldest'] === '' && array_key_exists('new_field', $csv[0]),
                    "G3 $path historical oldest-only field, Unicode/multiline/quotes and schema union survive full export");
            }
        }
    }
    foreach (['viewer.php?action=export&', 'submissions.php?format=csv&'] as $path) {
        $r = $http($path . 'form=history&last=2', $admin);
        mysql_access_check($r['code'] === 200 && array_column($csvRows($r['body']), 'id') === array_slice($allIds, 0, 2),
            "G3 explicit last=2 streamed MySQL export $path exact selection");
    }
    foreach (['viewer.php?action=submissions&', 'submissions.php?'] as $path) {
        $r = $http($path . 'form=history&limit=7&offset=254', $admin);
        mysql_access_check($r['code'] === 200 && ($r['json']['total'] ?? null) === 257
            && array_column($r['json']['submissions'] ?? [], 'id') === array_slice($allIds, 254),
            "G3 deep MySQL page includes oldest historical row $path");
    }
    $bufferAttribute = defined('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY') ? constant('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY') : constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY'); // PHP 8.5+ and older drivers.
    foreach ([true, false] as $initialBuffered) {
        foreach (['exhaust', 'early', 'consumer_exception', 'slice', 'prepare_failure'] as $mode) {
            mysql_access_verify($db);
            $pdo->setAttribute($bufferAttribute, $initialBuffered);
            $cursor = bbf_read_db($pdo, 'history');
            $cursor->rewind();
            mysql_access_check($cursor->current()['id'] === $allIds[0]
                && !$pdo->getAttribute($bufferAttribute),
                'G3 live cursor is unbuffered, mode=' . $mode . ' initial=' . (int)$initialBuffered);
            $blocked = false;
            try { $probe = $pdo->query('SELECT 1'); $probe->closeCursor(); }
            catch (PDOException $e) { $blocked = ($e->errorInfo[1] ?? null) === 2014; }
            mysql_access_check($blocked, "G3 pending unbuffered results genuinely block same-connection query $mode");
            if ($mode === 'exhaust') { foreach ($cursor as $_) {} }
            elseif ($mode === 'slice') {
                mysql_access_check(count(iterator_to_array(bbf_read_slice($cursor, 2, 1), false)) === 2, 'G3 early slice yields exact requested count');
            } elseif ($mode === 'consumer_exception') {
                try { foreach ($cursor as $_) { throw new RuntimeException('local consumer stopped'); } }
                catch (RuntimeException $e) { if ($e->getMessage() !== 'local consumer stopped') throw $e; }
            }
            unset($cursor);
            if ($mode === 'prepare_failure') {
                // MySQL now paginates eligible rows in PHP; temporarily hide ONLY our owned table to fail real SQL execution.
                $pdo->exec('RENAME TABLE bbf_submissions TO g3_cursor_failure'); try { iterator_to_array(bbf_read_db($pdo, 'history'), false); mysql_access_check(false, 'G3 missing owned SQL table must fail'); }
                catch (PDOException $e) { mysql_access_check(($e->errorInfo[1] ?? null) === 1146, 'G3 actual SQL failure exercised reader finally path'); } finally { $pdo->exec('RENAME TABLE g3_cursor_failure TO bbf_submissions'); }
            }
            $restored = (bool)$pdo->getAttribute($bufferAttribute) === $initialBuffered;
            $probe = $pdo->query('SELECT COUNT(*) FROM bbf_submissions'); $count = (int)$probe->fetchColumn(); $probe->closeCursor();
            mysql_access_check($restored && $count === 260, 'G3 cursor cleanup restores prior buffering and permits same-PDO query, mode=' . $mode . ' initial=' . (int)$initialBuffered);
        }
    }
    $pdo->setAttribute($bufferAttribute, true);
    mysql_access_check($pdo->query('SELECT id,form_id FROM g2_preserved_submissions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $remaining,
        'G3 original G2 legacy rows preserved unchanged in same owned database');

    // HTTP producer evidence, separate from the parent-PDO cursor checks above.
    // Only owned copies/config/SQL rows are used. Request-local peaks include endpoint
    // bootstrap, JSON encoding and CSV production, NOT fixture setup/client parsing.
    // This is PHP allocator memory, not MariaDB/OS RSS or a throughput benchmark.
    mysql_access_check(function_exists('memory_reset_peak_usage'), 'G3 producer measurement requires PHP 8.2+ peak reset; no skipped memory gate');
    $producerKey = bin2hex(random_bytes(32));
    $producerScript = <<<'PHP'
<?php
if (PHP_SAPI !== 'cli-server' || !hash_equals(__PRODUCER_KEY__, $_SERVER['HTTP_X_MYSQL_PRODUCER_KEY'] ?? '')) {
    http_response_code(403); exit;
}
$nonce = $_SERVER['HTTP_X_MYSQL_PRODUCER_NONCE'] ?? '';
$mode = $_GET['_mode'] ?? ''; $endpoint = $_GET['_endpoint'] ?? '';
if (!preg_match('/\A[a-f0-9]{32}\z/', $nonce) || !in_array($mode, ['normal', 'eager'], true)
    || !in_array($endpoint, ['viewer.php', 'submissions.php'], true) || ($_GET['form'] ?? '') !== 'producer') {
    http_response_code(400); exit;
}
unset($_GET['_mode'], $_GET['_endpoint']);
$_SERVER['SCRIPT_NAME'] = '/' . $endpoint;
$target = dirname(__DIR__) . ($mode === 'eager' ? '/mysql-eager/' : '/') . $endpoint;
ini_set('display_errors', '0'); error_reporting(E_ALL);
$metricsPath = __DIR__ . '/../logs/producer-' . $nonce . '.json';
http_response_code(200); gc_collect_cycles(); memory_reset_peak_usage();
$baseUsed = memory_get_usage(false); $baseAllocated = memory_get_usage(true);
register_shutdown_function(static function () use ($metricsPath, $nonce, $mode, $endpoint, $baseUsed, $baseAllocated): void {
    $metrics = ['nonce' => $nonce, 'pid' => getmypid(), 'mode' => $mode, 'endpoint' => $endpoint,
        'peak_used' => memory_get_peak_usage(false), 'peak_allocated' => memory_get_peak_usage(true),
        'delta_used' => memory_get_peak_usage(false) - $baseUsed,
        'delta_allocated' => memory_get_peak_usage(true) - $baseAllocated,
        'status' => http_response_code(), 'last_error' => error_get_last(), 'output_buffering' => ini_get('output_buffering'), 'gc_roots' => gc_status()['roots'],
        'eager_rows' => $GLOBALS['mysql_producer_eager_rows'] ?? 0];
    file_put_contents($metricsPath, json_encode($metrics, JSON_THROW_ON_ERROR));
});
require $target;
PHP;
    file_put_contents("$root/tests/mysql-producer.php", str_replace('__PRODUCER_KEY__', var_export($producerKey, true), $producerScript));
    // Negative control changes ONLY a private reader copy, never the real endpoints.
    // Fully decode the very iterator they consume before yielding its first row.
    // Unlike allocating unrelated ballast, this preserves all output and directly
    // models the eager-materialization regression the old HTTP checks could miss.
    mkdir("$root/mysql-eager", 0700);
    foreach (['viewer.php', 'submissions.php', 'bbf_functions.php', 'bbf_auth.php', 'bbf_storage.php', 'bbf_delivery.php', 'bbf_outbox.php', 'bbf_export.php', 'bbf_review.php', 'bbf_versions.php', 'config.php'] as $file) {
        bbf_test_copy("$root/$file", "$root/mysql-eager/$file");
    }
    mkdir("$root/mysql-eager/lang", 0700);
    bbf_test_copy("$root/lang/en.php", "$root/mysql-eager/lang/en.php");
    $readerSource = file_get_contents("$root/bbf_read.php"); printf("PRODUCER runtime PHP=%s reader_sha256=%s export_sha256=%s\n", PHP_VERSION, hash('sha256', $readerSource), hash_file('sha256', "$root/bbf_export.php"));
    $eagerSource = str_replace('function bbf_read_db(PDO $pdo,', 'function mysql_producer_lazy_db(PDO $pdo,', $readerSource, $replacements);
    if ($replacements !== 1) throw new RuntimeException('Expected exactly one real DB reader declaration for eager control.');
    $eagerSource .= <<<'PHP'

function bbf_read_db(PDO $pdo, string $formId, ?string $from = null, ?string $to = null, ?string $q = null, ?string $id = null, ?int $limit = null, int $offset = 0): Generator {
    $rows = iterator_to_array(mysql_producer_lazy_db($pdo, $formId, $from, $to, $q, $id, $limit, $offset), false);
    $GLOBALS['mysql_producer_eager_rows'] = max($GLOBALS['mysql_producer_eager_rows'] ?? 0, count($rows));
    yield from $rows;
}
PHP;
    file_put_contents("$root/mysql-eager/bbf_read.php", $eagerSource);
    $producerForm = ['id' => 'producer', 'name' => 'HTTP producer memory', 'storage' => 'mysql',
        'fields' => [['name' => 'payload', 'type' => 'text']]];
    file_put_contents("$root/forms/producer.json", bbf_storage_json($producerForm));
    $producerId = static fn(int $i): string => sprintf('bbf_producer_%05d', $i);
    $producerData = static fn(int $i): array => ['payload' => str_pad("payload-$i ", 8192, 'x')]
        + ($i === 0 ? ['retired_oldest' => "oldest-only č\n\"quoted\""] : []);
    $producerStamp = static fn(int $i): string => gmdate('Y-m-d\TH:i:s\Z', 1577836800 + $i);
    $producerMeasure = static function (string $endpoint, string $mode, array $query) use ($http, $admin, $producerKey, $root, $server): array {
        $nonce = bin2hex(random_bytes(16));
        $options = $admin;
        $options['timeout'] = 120;
        $options['headers'] += ['X-Mysql-Producer-Key' => $producerKey, 'X-Mysql-Producer-Nonce' => $nonce];
        $r = $http('tests/mysql-producer.php?' . http_build_query(['_endpoint' => $endpoint, '_mode' => $mode, 'form' => 'producer'] + $query), $options);
        $path = "$root/logs/producer-$nonce.json";
        // The file is created at request shutdown, before the HTTP socket closes.
        if (!is_file($path)) throw new RuntimeException("Missing HTTP producer telemetry: $endpoint/$mode HTTP {$r['code']}");
        $m = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); unlink($path);
        mysql_access_check(($m['nonce'] ?? null) === $nonce && is_int($m['pid'] ?? null) && $m['pid'] > 0
            && ($m['mode'] ?? null) === $mode && ($m['endpoint'] ?? null) === $endpoint
            && ($m['status'] ?? null) === 200 && $r['code'] === 200 && ($m['last_error'] ?? null) === null
            && isset($m['delta_used'], $m['peak_used'], $m['peak_allocated']),
            "G3 $endpoint/$mode HTTP producer nonce/PID/status/clean runtime evidence");
        return [$r, $m];
    };
    $producerMetrics = []; $producerHashes = []; $inserted = 0;
    foreach ([256, 4096] as $n) {
        mysql_access_verify($db);
        $insert = $pdo->prepare('INSERT INTO bbf_submissions (id,form_id,data,meta,created_at) VALUES (?,?,?,?,?)');
        $pdo->beginTransaction();
        try {
            for ($i = $inserted; $i < $n; ++$i) {
                $insert->execute([$producerId($i), 'producer', bbf_storage_json($producerData($i)),
                    bbf_storage_json(['submitted' => $producerStamp($i)]), gmdate('Y-m-d H:i:s', 1577836800 + $i)]);
            }
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        $insert = null; $inserted = $n;
        foreach (['viewer.php', 'submissions.php'] as $endpoint) {
            foreach (['first', 'deep', 'search', 'export', 'export-search'] as $case) {
                $export = str_starts_with($case, 'export');
                $query = $endpoint === 'viewer.php' ? ['action' => $export ? 'export' : 'submissions'] : ($export ? ['format' => 'csv'] : []);
                if (!$export) $query += ['limit' => 7, 'offset' => $case === 'deep' ? $n - 3 : 0];
                // All rows match: an eager searched iterator is just as costly as an unfiltered one.
                if (str_contains($case, 'search')) $query['q'] = 'payload-';
                foreach (['normal', 'eager'] as $mode) {
                    [$r, $m] = $producerMeasure($endpoint, $mode, $query);
                    $tag = "$endpoint/$case/$n/$mode";
                    if ($export) {
                        $csv = $csvRows($r['body']); $bad = 0;
                        foreach ($csv as $pos => $row) {
                            $i = $n - 1 - $pos; $data = $producerData($i);
                            if ($row !== ['id' => $producerId($i), 'submitted' => $producerStamp($i),
                                'payload' => $data['payload'], 'retired_oldest' => $data['retired_oldest'] ?? '']) $bad++;
                        }
                        mysql_access_check(count($csv) === $n && $bad === 0, "G3 $tag exact complete CSV IDs/order/payload/oldest history");
                        unset($csv);
                    } else {
                        $expected = [];
                        $offset = $case === 'deep' ? $n - 3 : 0;
                        for ($i = $n - 1 - $offset; $i >= max(0, $n - $offset - 7); --$i) {
                            $expected[] = ['id' => $producerId($i), 'form' => 'producer', 'data' => $producerData($i), 'meta' => ['submitted' => $producerStamp($i)]];
                        }
                        mysql_access_check(($r['json']['total'] ?? null) === $n && ($r['json']['submissions'] ?? null) === $expected,
                            "G3 $tag exact total/page IDs/order/payload");
                    }
                    $producerMetrics[$endpoint][$case][$mode][$n] = $m;
                    $hash = hash('sha256', $r['body']);
                    if ($mode === 'normal') {
                        $producerHashes[$endpoint][$case][$n] = $hash;
                        mysql_access_check($m['eager_rows'] === 0 && $m['delta_used'] < 8 * 1048576 && $m['peak_used'] < 12 * 1048576,
                            "G3 $tag bounded actual HTTP producer (<8 MiB incremental / <12 MiB peak)");
                    } else {
                        mysql_access_check($m['eager_rows'] >= $n && $hash === $producerHashes[$endpoint][$case][$n],
                            "G3 $tag eager control really materializes full selected rows yet preserves exact HTTP bytes");
                    }
                    printf("PRODUCER %s delta=%.3f MiB peak=%.3f MiB allocated=%.3f MiB eager_rows=%d output_buffering=%s gc_roots=%d\n",
                        $tag, $m['delta_used'] / 1048576, $m['peak_used'] / 1048576, $m['peak_allocated'] / 1048576, $m['eager_rows'], $m['output_buffering'], $m['gc_roots']);
                    unset($r);
                }
            }
        }
    }
    foreach ($producerMetrics as $endpoint => $casesMeasured) {
        foreach ($casesMeasured as $case => $modes) {
            $normalSmall = $modes['normal'][256]; $normalLarge = $modes['normal'][4096];
            $eagerSmall = $modes['eager'][256]; $eagerLarge = $modes['eager'][4096];
            mysql_access_check($normalLarge['delta_used'] <= $normalSmall['delta_used'] + 2 * 1048576,
                "G3 $endpoint/$case HTTP producer 16x rows adds at most 2 MiB");
            mysql_access_check($eagerLarge['delta_used'] >= 8 * 1048576 && $eagerLarge['peak_used'] >= 12 * 1048576
                && $eagerLarge['delta_used'] > $eagerSmall['delta_used'] + 2 * 1048576,
                "G3 $endpoint/$case output-equivalent eager negative control rejected by SAME absolute and growth memory gates");
        }
    }
    mysql_access_check(hash_file('sha256', "$root/bbf_read.php") === hash('sha256', $readerSource),
        'G3 normal HTTP reader remains byte-identical; eager mutation confined to private alternate copy');
    mysql_access_verify($db);
    $pdo->exec("DELETE FROM bbf_submissions WHERE BINARY form_id=BINARY 'producer'");
    mysql_access_check((int)$pdo->query('SELECT COUNT(*) FROM bbf_submissions')->fetchColumn() === 260
        && $pdo->query('SELECT id,form_id FROM g2_preserved_submissions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $remaining,
        'G3 producer-only rows removed; all 260 prior G3 rows and original G2 rows preserved');

    print 'G3 additions: ' . ($checks - $g3Start) . " passed; original G2 checks retained (113 including final cleanup).\n";

    $g7Start = $checks;
    $reviewForm = ['id' => 'review-race', 'name' => 'Review race', 'storage' => 'mysql', 'fields' => []];
    file_put_contents("$root/forms/review-race.json", bbf_storage_json($reviewForm));
    mysql_access_verify($db);
    // Earlier viewer deletion coverage now creates review tombstones; reset only this owned fixture to reproduce the historical schema.
    $pdo->exec('DROP TABLE IF EXISTS bbf_review_filter');
    $pdo->exec('DROP TABLE IF EXISTS bbf_submission_review');
    $pdo->exec('CREATE TABLE bbf_submission_review (
        form_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        submission_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        notes TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
        tags LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
        revision BIGINT UNSIGNED NOT NULL, updated_at VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        updated_by VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        PRIMARY KEY (form_id, submission_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
    $pdo->prepare('INSERT INTO bbf_submission_review VALUES (?,?,?,?,?,?,?,?)')->execute([
        'review-race', 'bbf_legacy', 'in-progress', 'legacy note', '["legacy"]', 1,
        '2026-09-08T00:00:00Z', 'reviewer']);
    $legacyReview = bbf_review_get($config, 'review-race', 'bbf_legacy');
    $reviewColumns = $pdo->query('SHOW COLUMNS FROM bbf_submission_review')->fetchAll(PDO::FETCH_ASSOC);
    mysql_access_check($legacyReview['notes'] === 'legacy note'
        && in_array('deleted', array_column($reviewColumns, 'Field'), true),
        'G7 real MySQL pre-tombstone schema migrates additively and preserves its historical row');
    $savedFilter = bbf_review_filter_save($config, 'review-race', 'reviewer', 'mine', 'My queue',
        ['q' => 'Žluťoučký 😀', 'status' => 'in-progress', 'tags' => ['urgent']], 0);
    mysql_access_check(($savedFilter['ok'] ?? false)
        && bbf_review_filters($config, 'review-race', 'reviewer') === [$savedFilter['filter']],
        'G7 real MySQL saved filter persists Unicode criteria');
    mysql_access_check(bbf_review_filters($config, 'review-race', 'other-reviewer') === []
        && bbf_review_filters($config, 'history', 'reviewer') === [],
        'G7 real MySQL saved filters are isolated by principal and form');
    $otherFormReview = bbf_review_update($config, 'history', 'bbf_legacy', 'reviewer', ['notes' => 'history only'], 0);
    mysql_access_check(($otherFormReview['ok'] ?? false)
        && bbf_review_get($config, 'history', 'bbf_legacy')['notes'] === 'history only'
        && bbf_review_get($config, 'review-race', 'bbf_legacy')['notes'] === 'legacy note',
        'G7 real MySQL same submission ID review metadata is isolated by exact form');
    $staleFilter = bbf_review_filter_save($config, 'review-race', 'reviewer', 'mine', 'stale', [], 0);
    mysql_access_check(!($staleFilter['ok'] ?? true) && ($staleFilter['reason'] ?? '') === 'conflict'
        && ($staleFilter['filter'] ?? null) === $savedFilter['filter'],
        'G7 real MySQL saved-filter CAS returns the durable winner');
    $changedFilter = bbf_review_filter_save($config, 'review-race', 'reviewer', 'mine', 'Done queue', ['status' => 'done'], 1);
    mysql_access_check(($changedFilter['ok'] ?? false) && $changedFilter['filter']['revision'] === 2,
        'G7 real MySQL saved-filter current revision updates');
    mysql_access_check((bbf_review_filter_delete($config, 'review-race', 'reviewer', 'mine', 1)['reason'] ?? '') === 'conflict'
        && (bbf_review_filter_delete($config, 'review-race', 'reviewer', 'mine', 2)['ok'] ?? false)
        && bbf_review_filters($config, 'review-race', 'reviewer') === [],
        'G7 real MySQL saved-filter stale delete is rejected and current delete persists');

    file_put_contents("$root/tests/mysql-review-worker.php", <<<'PHP'
<?php
if (PHP_SAPI !== 'cli') exit(2);
define('BBF_LOADED', true);
$config = require $argv[1];
require $argv[2] . '/bbf_review.php';
$mode = $argv[3]; $index = $argv[4]; $expected = (int)$argv[5];
file_put_contents($argv[7] . '.' . $index, 'ready');
$until = microtime(true) + 15;
while (!is_file($argv[6]) && microtime(true) < $until) usleep(1000);
if (!is_file($argv[6])) exit(3);
if ($mode === 'review') {
    $result = bbf_review_update($config, 'review-race', 'bbf_concurrent', 'worker_' . $index,
        ['notes' => 'candidate-' . $expected . '-' . $index], $expected);
} elseif ($mode === 'filter') {
    $result = bbf_review_filter_save($config, 'review-race', 'reviewer', 'concurrent',
        'candidate-' . $expected . '-' . $index, ['status' => 'new', 'tags' => ['race']], $expected);
} else exit(4);
echo json_encode($result, JSON_THROW_ON_ERROR);
exit(($result['ok'] ?? false) || ($result['reason'] ?? '') === 'conflict' ? 0 : 5);
PHP);
    $runReviewRace = static function (string $mode, int $expected) use ($db, $root, $env, $pdo, &$raceWorkers): array {
        mysql_access_verify($db);
        $barrier = "$root/review-race-$mode-$expected.go";
        $ready = "$root/review-race-$mode-$expected-ready";
        $labels = []; $results = [];
        try {
            if ($expected > 0) {
                $pdo->beginTransaction();
                $lockSql = $mode === 'review'
                    ? "SELECT revision FROM bbf_submission_review WHERE BINARY form_id=BINARY 'review-race' AND submission_id='bbf_concurrent' FOR UPDATE"
                    : "SELECT revision FROM bbf_review_filter WHERE BINARY form_id=BINARY 'review-race' AND BINARY principal_id=BINARY 'reviewer' AND filter_id='concurrent' FOR UPDATE";
                if ($pdo->query($lockSql)->fetchColumn() !== $expected) throw new RuntimeException("Cannot hold G7 $mode revision $expected lock.");
            }
            for ($i = 0; $i < 12; ++$i) {
                $label = "review-$mode-$expected-worker-$i";
                $raceWorkers[$label] = mysql_access_spawn([PHP_BINARY, "$root/tests/mysql-review-worker.php",
                    "$root/config.php", $root, $mode, (string)$i, (string)$expected, $barrier, $ready], $root, $label, $env);
                $labels[] = $label;
            }
            $until = microtime(true) + 10;
            do {
                $readyCount = 0;
                for ($i = 0; $i < 12; ++$i) if (is_file("$ready.$i")) $readyCount++;
                if ($readyCount === 12) break;
                usleep(10000);
            } while (microtime(true) < $until);
            if ($readyCount !== 12) throw new RuntimeException("Only $readyCount G7 $mode workers reached the simultaneous call barrier.");
            file_put_contents($barrier, 'go');
            if ($expected > 0) {
                usleep(200000);
                foreach ($labels as $label) {
                    if (!mysql_access_alive($raceWorkers[$label]) || filesize("$root/logs/$label.out") !== 0) {
                        throw new RuntimeException("G7 $label did not block behind the independently held row lock.");
                    }
                }
                $pdo->commit();
            }
            foreach ($labels as $label) {
                $until = microtime(true) + 20;
                do {
                    $status = proc_get_status($raceWorkers[$label]);
                    if (!$status['running']) break;
                    usleep(10000);
                } while (microtime(true) < $until);
                $output = (string)file_get_contents("$root/logs/$label.out");
                $result = json_decode($output, true);
                if ($status['running'] || $status['exitcode'] !== 0 || !is_array($result)) {
                    throw new RuntimeException("G7 $label failed: stdout=$output stderr=" . file_get_contents("$root/logs/$label.err"));
                }
                $results[] = $result;
            }
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            mysql_access_stop_all($raceWorkers);
            if (is_file($barrier)) unlink($barrier);
            foreach (glob($ready . '.*') ?: [] as $readyFile) unlink($readyFile);
        }
        mysql_access_check($raceWorkers === [] && !is_file($barrier) && (glob($ready . '.*') ?: []) === [],
            "G7 $mode race workers and barrier files are fully cleaned");
        mysql_access_verify($db);
        return $results;
    };
    $cleanupFailureExercised = false;
    try { $runReviewRace('invalid', 0); }
    catch (RuntimeException $error) { $cleanupFailureExercised = str_contains($error->getMessage(), 'review-invalid-0-worker'); }
    mysql_access_check($cleanupFailureExercised && $raceWorkers === []
        && !is_file("$root/review-race-invalid-0.go") && (glob("$root/review-race-invalid-0-ready.*") ?: []) === [],
        'G7 race failure path stops all workers and removes coordination files');
    $reviewRace = $runReviewRace('review', 0);
    $reviewWinner = bbf_review_get($config, 'review-race', 'bbf_concurrent');
    mysql_access_check(count(array_filter($reviewRace, static fn($r) => $r['ok'] ?? false)) === 1
        && count(array_filter($reviewRace, static fn($r) => ($r['reason'] ?? '') === 'conflict')) === 11
        && count(array_filter($reviewRace, static fn($r) => ($r['review'] ?? null) === $reviewWinner)) === 12
        && $reviewWinner['revision'] === 1,
        'G7 12 concurrent real MySQL first review writes produce one winner and eleven truthful conflicts');
    $reviewRace = $runReviewRace('review', 1);
    $reviewWinner = bbf_review_get($config, 'review-race', 'bbf_concurrent');
    mysql_access_check(count(array_filter($reviewRace, static fn($r) => $r['ok'] ?? false)) === 1
        && count(array_filter($reviewRace, static fn($r) => ($r['reason'] ?? '') === 'conflict')) === 11
        && count(array_filter($reviewRace, static fn($r) => ($r['review'] ?? null) === $reviewWinner)) === 12
        && $reviewWinner['revision'] === 2,
        'G7 12 concurrent real MySQL existing review edits lock one row and preserve one CAS winner');
    $filterRace = $runReviewRace('filter', 0);
    $filterWinner = bbf_review_filters($config, 'review-race', 'reviewer');
    mysql_access_check(count(array_filter($filterRace, static fn($r) => $r['ok'] ?? false)) === 1
        && count(array_filter($filterRace, static fn($r) => ($r['reason'] ?? '') === 'conflict')) === 11
        && count($filterWinner) === 1 && $filterWinner[0]['id'] === 'concurrent' && $filterWinner[0]['revision'] === 1
        && count(array_filter($filterRace, static fn($r) => ($r['filter'] ?? null) === $filterWinner[0])) === 12,
        'G7 12 concurrent real MySQL saved-filter creates produce one durable principal-scoped winner');
    $filterRace = $runReviewRace('filter', 1);
    $filterWinner = bbf_review_filters($config, 'review-race', 'reviewer');
    mysql_access_check(count(array_filter($filterRace, static fn($r) => $r['ok'] ?? false)) === 1
        && count(array_filter($filterRace, static fn($r) => ($r['reason'] ?? '') === 'conflict')) === 11
        && count($filterWinner) === 1 && $filterWinner[0]['revision'] === 2
        && count(array_filter($filterRace, static fn($r) => ($r['filter'] ?? null) === $filterWinner[0])) === 12,
        'G7 12 concurrent real MySQL saved-filter edits block on one row and preserve one CAS winner');
    $purged = bbf_review_delete_records($config, 'review-race', ['bbf_concurrent', 'bbf_never_reviewed']);
    $lateReview = bbf_review_update($config, 'review-race', 'bbf_concurrent', 'late-worker', ['notes' => 'resurrect'], 0);
    $lateCreate = bbf_review_update($config, 'review-race', 'bbf_never_reviewed', 'late-worker', ['notes' => 'create'], 0);
    $tombstones = $pdo->query("SELECT submission_id,notes,tags,revision,deleted FROM bbf_submission_review
        WHERE BINARY form_id=BINARY 'review-race' AND deleted=1 ORDER BY submission_id")->fetchAll(PDO::FETCH_ASSOC);
    mysql_access_check(($purged['ok'] ?? false) && ($purged['deleted'] ?? null) === 1
        && ($lateReview['reason'] ?? '') === 'not_found' && ($lateCreate['reason'] ?? '') === 'not_found'
        && bbf_review_get($config, 'review-race', 'bbf_concurrent') === bbf_review_default()
        && $tombstones === [
            ['submission_id' => 'bbf_concurrent', 'notes' => '', 'tags' => '[]', 'revision' => 0, 'deleted' => 1],
            ['submission_id' => 'bbf_never_reviewed', 'notes' => '', 'tags' => '[]', 'revision' => 0, 'deleted' => 1],
        ],
        'G7 real MySQL purge scrubs metadata and tombstones both existing and never-reviewed IDs against resurrection');
    $reviewDdl = $pdo->query('SHOW CREATE TABLE bbf_submission_review')->fetch(PDO::FETCH_NUM)[1];
    $filterDdl = $pdo->query('SHOW CREATE TABLE bbf_review_filter')->fetch(PDO::FETCH_NUM)[1];
    mysql_access_check(str_contains(strtoupper($reviewDdl), 'ENGINE=INNODB')
        && str_contains($reviewDdl, 'ascii_bin') && str_contains($reviewDdl, 'utf8mb4_bin')
        && str_contains(strtoupper($filterDdl), 'ENGINE=INNODB')
        && str_contains($filterDdl, 'ascii_bin') && str_contains($filterDdl, 'utf8mb4_bin'),
        'G7 real MySQL review/filter schemas are transactional with binary identifier/text collations');
    mysql_access_check((int)$pdo->query('SELECT COUNT(*) FROM bbf_submissions')->fetchColumn() === 260
        && (int)$pdo->query("SELECT COUNT(*) FROM bbf_submission_review WHERE form_id='review-race'")->fetchColumn() === 3
        && (int)$pdo->query("SELECT COUNT(*) FROM bbf_review_filter WHERE form_id='review-race'")->fetchColumn() === 1,
        'G7 review metadata stays in dedicated tables and leaves all response rows unchanged');
    print 'G7 MySQL additions: ' . ($checks - $g7Start) . " passed.\n";

    $g9Start = $checks;
    $retentionForm = ['id' => 'retention-mysql', 'name' => 'Retention MySQL', 'storage' => 'mysql',
        'fields' => [['name' => 'answer', 'type' => 'text']]];
    file_put_contents("$root/forms/retention-mysql.json", bbf_storage_json($retentionForm));
    $retentionInsert = $pdo->prepare('INSERT INTO bbf_submissions (id,form_id,data,meta,created_at) VALUES (?,?,?,?,?)');
    foreach ([
        ['bbf_retention_old_a', 'retention-mysql', '2020-01-01T00:00:00Z'],
        ['bbf_retention_old_b', 'retention-mysql', '2020-01-02T00:00:00Z'],
        ['bbf_retention_recent', 'retention-mysql', '2026-09-09T00:00:01Z'],
        ['bbf_retention_alias', 'RETENTION-MYSQL', '2020-01-01T00:00:00Z'],
    ] as [$id, $formId, $submitted]) {
        $retentionInsert->execute([$id, $formId, bbf_storage_json(['answer' => "private-$id"]),
            bbf_storage_json(['submitted' => $submitted, 'definition_version' => 'v1-' . str_repeat('a', 64)]),
            str_replace('T', ' ', rtrim($submitted, 'Z'))]);
    }
    $retentionInsert = null;
    $retentionArchive = dirname($root) . '/bbf mysql retention ' . bin2hex(random_bytes(8));
    $GLOBALS['bbf_test_roots'][$retentionArchive] = true;
    register_shutdown_function(static function () use ($retentionArchive): void { bbf_test_cleanup($retentionArchive); });
    $retentionConfig = $config;
    $retentionConfig['retention'] = ['enabled' => true, 'days' => 30,
        'archive_dir' => $retentionArchive, 'batch_limit' => 100];
    $retentionReview = bbf_review_update($retentionConfig, 'retention-mysql', 'bbf_retention_old_a',
        'mysql-reviewer', ['notes' => 'mysql archived note'], 0);
    $retentionOutboxPath = bbf_outbox_path($retentionConfig, 'retention-mysql', 'bbf_retention_old_a');
    bbf_outbox_init($retentionOutboxPath, 'retention-mysql:bbf_retention_old_a', [], 3, time());
    $retentionNow = strtotime('2026-09-10T00:00:00Z');
    $retentionPlan = bbf_retention_plan($retentionConfig, 'retention-mysql', $retentionNow);
    mysql_access_check(($retentionReview['ok'] ?? false) && $retentionPlan['backend'] === 'mysql'
        && $retentionPlan['ids'] === ['bbf_retention_old_a', 'bbf_retention_old_b'],
        'G9 real MySQL plan is deterministic and excludes recent and case-alias rows');
    $retentionApplied = bbf_retention_apply($retentionConfig, 'retention-mysql',
        $retentionPlan['confirmation'], $retentionNow);
    $retentionArchiveDocument = json_decode(file_get_contents($retentionApplied['archive'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
    mysql_access_check(($retentionApplied['ok'] ?? false) && ($retentionApplied['deleted'] ?? 0) === 2
        && hash_equals($retentionArchiveDocument['sha256'],
            hash('sha256', bbf_storage_json($retentionArchiveDocument['payload'])))
        && ($retentionArchiveDocument['payload']['reviews']['bbf_retention_old_a']['notes'] ?? '') === 'mysql archived note'
        && (int)$pdo->query("SELECT COUNT(*) FROM bbf_submissions WHERE BINARY form_id=BINARY 'retention-mysql'")->fetchColumn() === 1
        && (int)$pdo->query("SELECT COUNT(*) FROM bbf_submissions WHERE BINARY form_id=BINARY 'RETENTION-MYSQL'")->fetchColumn() === 1
        && bbf_review_get($retentionConfig, 'retention-mysql', 'bbf_retention_old_a') === bbf_review_default()
        && (bbf_outbox_read($retentionOutboxPath)['ledger']['deleted'] ?? false) === true,
        'G9 real MySQL archive-before-delete atomically tombstones review and delivery with exact form isolation');
    bbf_test_cleanup($retentionArchive);

    $unsafeEngineRejected = false; $renamedPrimary = false;
    try {
        $pdo->exec('RENAME TABLE bbf_submissions TO bbf_submissions_retention_innodb');
        $renamedPrimary = true;
        $pdo->exec('CREATE TABLE bbf_submissions (id VARCHAR(64) PRIMARY KEY, form_id VARCHAR(64) NOT NULL,
            data LONGTEXT, meta LONGTEXT, created_at DATETIME) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
        try { bbf_retention_plan($retentionConfig, 'retention-mysql', $retentionNow); }
        catch (RuntimeException $error) { $unsafeEngineRejected = str_contains($error->getMessage(), 'InnoDB'); }
    } finally {
        if ($renamedPrimary) {
            $pdo->exec('DROP TABLE IF EXISTS bbf_submissions');
            $pdo->exec('RENAME TABLE bbf_submissions_retention_innodb TO bbf_submissions');
        }
    }
    mysql_access_check($unsafeEngineRejected,
        'G9 real MySQL retention fails closed before planning against a nontransactional primary table');

    mysql_access_verify($db);
    $backupForm = ['id' => 'backup-mysql', 'name' => 'Backup MySQL', 'storage' => 'mysql',
        'fields' => [['name' => 'answer', 'type' => 'text']]];
    $backupHistoricalForm = ['id' => 'backup-mysql', 'name' => 'Historical Backup MySQL', 'storage' => 'mysql',
        'fields' => [['name' => 'answer', 'type' => 'text'], ['name' => 'retired_answer', 'type' => 'text']]];
    file_put_contents("$root/forms/backup-mysql.json", bbf_storage_json($backupForm));
    $backupRecord = ['id' => 'bbf_backup', 'form' => 'backup-mysql',
        'data' => ['answer' => 'mysql-private', 'retired_answer' => 'historical-only'],
        'meta' => ['submitted' => '2026-09-09T03:04:05Z',
            'definition_version' => bbf_version_id($backupHistoricalForm), 'form_definition' => $backupHistoricalForm]];
    $backupInsert = $pdo->prepare('INSERT INTO bbf_submissions (id,form_id,data,meta,created_at) VALUES (?,?,?,?,?)');
    $backupInsert->execute([$backupRecord['id'], $backupRecord['form'], bbf_storage_json($backupRecord['data']),
        bbf_storage_json($backupRecord['meta']), '2026-09-09 03:04:05']);
    $backupInsert->execute(['bbf_backup_alias', 'BACKUP-MYSQL', bbf_storage_json(['answer' => 'alias-private']),
        bbf_storage_json($backupRecord['meta']), '2026-09-09 03:04:05']);
    $backupInsert = null;
    $backupDirectory = dirname($root) . '/bbf mysql backups ' . bin2hex(random_bytes(8));
    $GLOBALS['bbf_test_roots'][$backupDirectory] = true;
    register_shutdown_function(static function () use ($backupDirectory): void { bbf_test_cleanup($backupDirectory); });
    $backupConfig = $config; $backupConfig['storage'] = 'mysql';
    $backupConfig['backup'] = ['directory' => $backupDirectory];
    bbf_version_state($backupConfig, 'backup-mysql');
    $backupReview = bbf_review_update($backupConfig, 'backup-mysql', 'bbf_backup', 'mysql-reviewer',
        ['notes' => 'mysql backup note'], 0);
    $backupFilter = bbf_review_filter_save($backupConfig, 'backup-mysql', 'mysql-reviewer', 'mine',
        'Mine', ['status' => 'new'], 0);
    $backupTombstone = bbf_review_delete_records($backupConfig, 'backup-mysql', ['bbf_deleted']);
    $backupOutboxPath = bbf_outbox_path($backupConfig, 'backup-mysql', 'bbf_backup');
    bbf_outbox_init($backupOutboxPath, 'backup-mysql:bbf_backup', [], 3, 10);
    bbf_audit_write($backupConfig, ['id' => 'fixture'], 'viewer_read', 'backup-mysql', ['bbf_backup'], 'allowed', 'completed', 1);
    $mysqlBundle = bbf_backup_create($backupConfig, 'backup-mysql', strtotime('2026-09-09T12:00:00Z'));
    $mysqlDocument = bbf_backup_bundle_read($backupConfig, $mysqlBundle['path']);
    mysql_access_check(($backupReview['ok'] ?? false) && ($backupFilter['ok'] ?? false) && ($backupTombstone['ok'] ?? false)
        && array_keys($mysqlDocument['payload']['records']) === ['bbf_backup'],
        'G9 real MySQL backup preserves exact-form records and review relationships while excluding case aliases');

    $pdo->exec('CREATE DATABASE bbf_restore_fixture CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
    foreach (["$root/backup-target", "$root/backup-target/forms", "$root/backup-target/submissions", "$root/backup-target/logs"] as $dir) {
        if (!mkdir($dir, 0700)) throw new RuntimeException('Cannot create MySQL restore target directory.');
    }
    $restoreConfig = $backupConfig;
    $restoreConfig['mysql']['database'] = 'bbf_restore_fixture';
    $restoreConfig['api_token'] = bin2hex(random_bytes(24));
    $restoreConfig['forms_dir'] = "$root/backup-target/forms";
    $restoreConfig['submissions_dir'] = "$root/backup-target/submissions";
    $restoreConfig['logs_dir'] = "$root/backup-target/logs";
    $mysqlPlan = bbf_backup_restore_plan($restoreConfig, $mysqlBundle['path']);
    mysql_access_check(($mysqlPlan['empty'] ?? false) === true && is_string($mysqlPlan['confirmation'])
        && !$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='bbf_restore_fixture'")->fetchColumn(),
        'G9 real MySQL restore dry-run proves an empty target without creating schema');
    mkdir("$root/backup-target/logs/access-audit.php", 0700);
    $mysqlFaulted = bbf_backup_restore($restoreConfig, $mysqlBundle['path'], $mysqlPlan['confirmation']);
    $restorePdo = bbf_read_db_connect($restoreConfig);
    $restoredSqlRows = 0;
    foreach (['bbf_submissions', 'bbf_submission_review', 'bbf_review_filter'] as $table) {
        $restoredSqlRows += (int)$restorePdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    }
    $restorePdo = null;
    mysql_access_check(($mysqlFaulted['reason'] ?? null) === 'storage' && $restoredSqlRows === 0
        && !is_file("$root/backup-target/forms/backup-mysql.json")
        && !file_exists("$root/backup-target/forms/.versions/backup-mysql")
        && !file_exists("$root/backup-target/submissions/.delivery/backup-mysql")
        && is_dir("$root/backup-target/logs/access-audit.php"),
        'G9 real MySQL late publication failure compensates committed SQL and removes created relationship files');
    rmdir("$root/backup-target/logs/access-audit.php");
    $mysqlRestored = bbf_backup_restore($restoreConfig, $mysqlBundle['path'], $mysqlPlan['confirmation']);
    $mysqlRows = iterator_to_array(bbf_read_export('backup-mysql', $restoreConfig, PHP_INT_MAX, 0, null, null), false);
    mysql_access_check(($mysqlRestored['ok'] ?? false) === true && $mysqlRows === [$backupRecord]
        && bbf_backup_review_capture($restoreConfig, 'backup-mysql') === $mysqlDocument['payload']['review']
        && (bbf_outbox_read(bbf_outbox_existing_path($restoreConfig, 'backup-mysql', 'bbf_backup'))['ledger'] ?? null)
            === ($mysqlDocument['payload']['delivery']['bbf_backup']['ledger'] ?? null),
        'G9 real MySQL confirmed restore preserves submission, review/filter/tombstone and delivery relationships');
    $mysqlOccupied = bbf_backup_restore_plan($restoreConfig, $mysqlBundle['path']);
    mysql_access_check(($mysqlOccupied['empty'] ?? true) === false && ($mysqlOccupied['confirmation'] ?? null) === null,
        'G9 real MySQL restore refuses its populated exact-form target');
    bbf_test_cleanup($backupDirectory);
    print 'G9 MySQL additions: ' . ($checks - $g9Start) . " passed.\n";
} catch (Throwable $error) {
    $exitCode = 1;
    fwrite(STDERR, 'FAIL MariaDB access suite: ' . $error->getMessage() . "\n");
    if ($root !== null) foreach (['bootstrap.err', 'database.err', 'mariadb.log', 'php-error.log', 'server-output.log', 'server-error.log'] as $log) {
        $path = "$root/logs/$log";
        if (is_file($path)) fwrite(STDERR, "$log\n" . file_get_contents($path) . "\n");
    }
} finally {
    $ownedRoot = $root; if ($failures && $root !== null && is_file("$root/logs/php-error.log")) fwrite(STDERR, file_get_contents("$root/logs/php-error.log"));
    $cleanup();
    if ($ownedRoot !== null) mysql_access_check(!is_dir($ownedRoot) && !is_resource($dbProc) && !is_resource($bootstrap),
        'owned database/PHP children stopped and unique fixture removed');
}
if ($failures) $exitCode = 1; print "Real MariaDB access matrix: $checks passed, " . count($failures) . " behavioral failures; exit=$exitCode. No existing DB/config/service used.\n";
exit($exitCode);
