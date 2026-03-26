<?php
/**
 * BareBonesForms — Integration Test Suite
 *
 * Tests REAL storage (not sandbox) across all backends: file, csv, sqlite.
 * Catches bugs that sandbox-only smoke tests miss:
 *   - Group fields stored as columns instead of their children
 *   - Conditional fields causing column misalignment in CSV
 *   - CSV header drift when form definition changes between submissions
 *   - Special characters corrupted in storage/retrieval
 *
 * Usage:
 *   C:\WebDesign\php\php.exe tests/integration-test.php            Run all tests
 *   C:\WebDesign\php\php.exe tests/integration-test.php csv        Run one backend only
 *
 * WARNING: This test creates real submissions and deletes them afterward.
 *          It uses a temporary submissions directory, NOT your production data.
 */

// ─── Config ─────────────────────────────────────────────────────
$phpBinary   = 'C:\\WebDesign\\php\\php.exe';
$projectDir  = realpath(__DIR__ . '/..');
$testFormsDir = __DIR__ . '/test-forms';
$configFile  = $projectDir . '/config.php';
$host        = '127.0.0.1';
$port        = 9800 + rand(0, 190); // random port to avoid conflicts
$baseUrl     = "http://$host:$port";
$filterBackend = $argv[1] ?? null;

// Temporary directory for test submissions (isolated from production)
$testSubmissionsDir = $projectDir . '/tests/_test_submissions_' . getmypid();

// ─── Output helpers ─────────────────────────────────────────────
$passed = 0; $failed = 0; $warnings = 0;
$backendResults = []; // per-backend tracking

function out(string $msg): void { echo $msg . "\n"; }
function pass(string $msg): void { global $passed; $passed++; echo "  \033[32m✓\033[0m $msg\n"; }
function fail(string $msg, string $detail = ''): void { global $failed; $failed++; echo "  \033[31m✗\033[0m $msg\n"; if ($detail) echo "    \033[90m$detail\033[0m\n"; }
function warn(string $msg): void { global $warnings; $warnings++; echo "  \033[33m!\033[0m $msg\n"; }
function section(string $msg): void { echo "\n\033[1m── $msg\033[0m\n"; }

function assertEqual($expected, $actual, string $msg): bool {
    if ($expected === $actual) {
        pass($msg);
        return true;
    }
    $expStr = is_array($expected) ? json_encode($expected) : var_export($expected, true);
    $actStr = is_array($actual) ? json_encode($actual) : var_export($actual, true);
    fail($msg, "expected: $expStr, got: $actStr");
    return false;
}

function assertNotEmpty($value, string $msg): bool {
    if (!empty($value)) {
        pass($msg);
        return true;
    }
    fail($msg, 'value is empty');
    return false;
}

function assertKeyMissing(array $data, string $key, string $msg): bool {
    if (!array_key_exists($key, $data)) {
        pass($msg);
        return true;
    }
    fail($msg, "key '$key' should not exist but has value: " . var_export($data[$key], true));
    return false;
}

// ─── Cleanup ────────────────────────────────────────────────────
$configBackup = null;
$createdConfig = false;
$serverProc = null;

function cleanup(): void {
    global $configFile, $configBackup, $createdConfig, $serverProc, $testSubmissionsDir, $projectDir, $testFormsDir;

    // Kill PHP server
    killServer();

    // Restore config
    if ($createdConfig && !$configBackup) {
        @unlink($configFile);
    } elseif ($configBackup !== null) {
        file_put_contents($configFile, $configBackup);
    }

    // Remove test submissions directory
    if (is_dir($testSubmissionsDir)) {
        removeDir($testSubmissionsDir);
    }

    // Remove symlinked test forms from forms dir
    $formsDir = $projectDir . '/forms';
    foreach (glob($testFormsDir . '/*.json') as $tf) {
        $target = $formsDir . '/' . basename($tf);
        if (file_exists($target)) @unlink($target);
    }
}

function removeDir(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            removeDir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function killServer(): void {
    global $serverProc;
    if (isset($serverProc) && is_resource($serverProc)) {
        $status = proc_get_status($serverProc);
        if ($status['running']) {
            if (stripos(PHP_OS, 'WIN') === 0) {
                exec("taskkill /F /T /PID {$status['pid']} 2>nul");
            } else {
                posix_kill($status['pid'], SIGTERM);
            }
        }
        proc_close($serverProc);
        $serverProc = null;
    }
}

register_shutdown_function('cleanup');

// ─── Backup existing config ─────────────────────────────────────
if (file_exists($configFile)) {
    $configBackup = file_get_contents($configFile);
}

// ─── Copy test forms to forms dir ───────────────────────────────
$formsDir = $projectDir . '/forms';
foreach (glob($testFormsDir . '/*.json') as $tf) {
    copy($tf, $formsDir . '/' . basename($tf));
}

// ─── HTTP helpers ───────────────────────────────────────────────
function httpPost(string $url, array $data): array {
    $postData = http_build_query($data);
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\nConnection: close\r\nContent-Length: " . strlen($postData) . "\r\n",
            'content' => $postData,
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);
    $http_response_header = null;
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $code = (int)$m[1];
    }
    $error = ($body === false) ? 'Request failed' : '';
    return ['code' => $code, 'body' => $body ?: '', 'error' => $error, 'json' => @json_decode($body ?: '', true)];
}

function httpGet(string $url): array {
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => "Connection: close\r\n",
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);
    $http_response_header = null;
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $code = (int)$m[1];
    }
    $error = ($body === false) ? 'Request failed' : '';
    return ['code' => $code, 'body' => $body ?: '', 'error' => $error, 'json' => @json_decode($body ?: '', true)];
}

function getSubmissions(string $formId): ?array {
    global $baseUrl;
    $url = "$baseUrl/submissions.php?form=$formId&token=test-token";
    $result = httpGet($url);
    if ($result['code'] === 0) {
        // Server may have died — restart and retry once
        warn("server unresponsive on GET, restarting...");
        if (restartServer()) {
            usleep(300000);
            $result = httpGet($url);
        }
    }
    if ($result['code'] !== 200 || empty($result['json'])) {
        return null;
    }
    return $result['json']['submissions'] ?? [];
}

function submitForm(string $formId, array $data): array {
    global $baseUrl;
    $url = "$baseUrl/submit.php?form=$formId";
    usleep(150000); // small delay to avoid rate limiting on fast loops
    $result = httpPost($url, $data);
    if ($result['code'] === 0) {
        // Server may have died — restart and retry once
        warn("server unresponsive on POST, restarting...");
        if (restartServer()) {
            usleep(300000);
            $result = httpPost($url, $data);
        }
    }
    return $result;
}

// ─── Server management ──────────────────────────────────────────
function startServer(): bool {
    global $host, $port, $serverProc, $projectDir, $phpBinary;

    $serverCmd = "$phpBinary -S $host:$port -t " . escapeshellarg($projectDir);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $serverProc = proc_open($serverCmd, $descriptors, $pipes, $projectDir);

    if (!is_resource($serverProc)) {
        return false;
    }

    // Wait for server to be ready
    for ($i = 0; $i < 30; $i++) {
        usleep(200000);
        $conn = @fsockopen($host, $port, $errno, $errstr, 1);
        if ($conn) {
            fclose($conn);
            // HTTP warm-up: make sure server actually processes requests
            usleep(300000);
            for ($w = 0; $w < 5; $w++) {
                $r = @file_get_contents("http://$host:$port/submit.php?form=_ping&action=definition", false,
                    stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]));
                if ($r !== false) return true;
                usleep(500000);
            }
            return true; // socket open, assume OK even if warm-up failed
        }
    }
    return false;
}

function restartServer(): bool {
    killServer();
    usleep(2000000); // wait for port to free (Windows needs more time)
    return startServer();
}

// ─── Write test config ──────────────────────────────────────────
function writeConfig(string $storage): void {
    global $configFile, $testSubmissionsDir, $projectDir;

    $testConfig = "<?php\n"
        . "defined('BBF_LOADED') || exit;\n"
        . "return [\n"
        . "    'storage'          => '$storage',\n"
        . "    'sandbox'          => false,\n"
        . "    'csrf'             => false,\n"
        . "    'rate_limit'       => 9999,\n"
        . "    'store_ip'         => false,\n"
        . "    'store_user_agent' => false,\n"
        . "    'honeypot_field'   => '_bbf_hp',\n"
        . "    'mail'             => [\n"
        . "        'method'     => 'mail',\n"
        . "        'from_email' => 'test@example.com',\n"
        . "        'from_name'  => 'BBF Test',\n"
        . "    ],\n"
        . "    'webhook_secret' => 'test-secret',\n"
        . "    'api_token'      => 'test-token',\n"
        . "    'forms_dir'      => '" . addslashes($projectDir) . "/forms',\n"
        . "    'submissions_dir' => '" . addslashes($testSubmissionsDir) . "',\n"
        . "    'templates_dir'  => '" . addslashes($projectDir) . "/templates',\n"
        . "    'logs_dir'       => '" . addslashes($testSubmissionsDir) . "/logs',\n"
        . "    'sqlite'         => ['path' => '" . addslashes($testSubmissionsDir) . "/bbf_test.sqlite'],\n"
        . "    'lang'           => 'en',\n"
        . "    'error_notify'   => '',\n"
        . "];\n";

    file_put_contents($configFile, $testConfig);
}

// ═════════════════════════════════════════════════════════════════
// Banner
// ═════════════════════════════════════════════════════════════════

out("\n\033[1;36m╔═══════════════════════════════════════════════╗\033[0m");
out("\033[1;36m║   BareBonesForms — Integration Test Suite     ║\033[0m");
out("\033[1;36m╚═══════════════════════════════════════════════╝\033[0m");

// ═════════════════════════════════════════════════════════════════
// Validate test forms (Phase 0)
// ═════════════════════════════════════════════════════════════════

section("Phase 0: Validate test forms");

$testFormFiles = glob($testFormsDir . '/*.json');
if (empty($testFormFiles)) {
    fail("No test forms found in $testFormsDir");
    exit(1);
}
pass("Found " . count($testFormFiles) . " test form(s)");

foreach ($testFormFiles as $tf) {
    $name = basename($tf, '.json');
    $form = json_decode(file_get_contents($tf), true);
    if ($form === null) {
        fail("$name.json: invalid JSON — " . json_last_error_msg());
    } else {
        pass("$name.json: valid JSON (id: {$form['id']})");
    }
}

// ═════════════════════════════════════════════════════════════════
// Run tests for each storage backend
// ═════════════════════════════════════════════════════════════════

$backends = ['file', 'csv', 'sqlite'];
if ($filterBackend) {
    if (!in_array($filterBackend, $backends, true)) {
        out("Unknown backend '$filterBackend'. Available: " . implode(', ', $backends));
        exit(1);
    }
    $backends = [$filterBackend];
}

foreach ($backends as $backend) {
    $backendPassed = 0;
    $backendFailed = 0;
    $prevPassed = $passed;
    $prevFailed = $failed;

    out("\n\033[1;35m╔═══════════════════════════════════════════════╗\033[0m");
    out("\033[1;35m║   Storage backend: $backend" . str_repeat(' ', 28 - strlen($backend)) . "║\033[0m");
    out("\033[1;35m╚═══════════════════════════════════════════════╝\033[0m");

    // Clean submissions dir
    if (is_dir($testSubmissionsDir)) {
        removeDir($testSubmissionsDir);
    }
    mkdir($testSubmissionsDir, 0755, true);
    mkdir($testSubmissionsDir . '/logs', 0755, true);

    // Write config and restart server
    writeConfig($backend);

    section("Starting PHP dev server ($backend)");
    if (!restartServer()) {
        fail("Cannot start PHP dev server on $host:$port for backend '$backend'");
        continue;
    }
    pass("PHP dev server running on $host:$port (backend: $backend)");

    // ─────────────────────────────────────────────────────────────
    // Test 1: Group Fields
    // ─────────────────────────────────────────────────────────────
    section("Test 1: Group Fields ($backend)");

    // Submit first entry
    $result1 = submitForm('test-groups', [
        'first_name' => 'Peter',
        'last_name'  => 'Novák',
        'email'      => 'peter@test.sk',
        'phone'      => '+421903111222',
        'message'    => 'Ahoj',
    ]);

    if ($result1['code'] === 200 && ($result1['json']['status'] ?? '') === 'ok') {
        pass("group form submit 1: OK (id: {$result1['json']['submission_id']})");
    } else {
        fail("group form submit 1: unexpected response (HTTP {$result1['code']})", substr($result1['body'], 0, 300));
    }

    // Submit second entry
    $result2 = submitForm('test-groups', [
        'first_name' => 'Jana',
        'last_name'  => 'Kováčová',
        'email'      => 'jana@test.sk',
        'phone'      => '+421903222333',
        'message'    => 'Dobrý deň',
    ]);

    if ($result2['code'] === 200 && ($result2['json']['status'] ?? '') === 'ok') {
        pass("group form submit 2: OK (id: {$result2['json']['submission_id']})");
    } else {
        fail("group form submit 2: unexpected response (HTTP {$result2['code']})", substr($result2['body'], 0, 300));
    }

    // Read back submissions
    usleep(300000);
    $subs = getSubmissions('test-groups');

    if ($subs === null) {
        fail("group form: cannot read submissions via API");
    } else {
        assertEqual(2, count($subs), "group form: 2 submissions returned");

        // Check both submissions (API returns newest first)
        foreach ($subs as $sub) {
            $data = $sub['data'] ?? [];
            $subId = $sub['id'] ?? '?';

            // CRITICAL: group container names must NOT appear as keys
            assertKeyMissing($data, 'name_row', "[$subId] no 'name_row' key in stored data");
            assertKeyMissing($data, 'address_row', "[$subId] no 'address_row' key in stored data");
        }

        // Find the Peter submission and Jana submission
        $peter = null; $jana = null;
        foreach ($subs as $sub) {
            if (($sub['data']['first_name'] ?? '') === 'Peter') $peter = $sub;
            if (($sub['data']['first_name'] ?? '') === 'Jana') $jana = $sub;
        }

        if ($peter) {
            assertEqual('Peter', $peter['data']['first_name'] ?? '', "Peter: first_name correct");
            assertEqual('Novák', $peter['data']['last_name'] ?? '', "Peter: last_name correct (unicode)");
            assertEqual('peter@test.sk', $peter['data']['email'] ?? '', "Peter: email correct");
            assertEqual('+421903111222', $peter['data']['phone'] ?? '', "Peter: phone correct");
            assertEqual('Ahoj', $peter['data']['message'] ?? '', "Peter: message correct");
        } else {
            fail("group form: Peter submission not found in results");
        }

        if ($jana) {
            assertEqual('Jana', $jana['data']['first_name'] ?? '', "Jana: first_name correct");
            assertEqual('Kováčová', $jana['data']['last_name'] ?? '', "Jana: last_name correct (unicode)");
            assertEqual('jana@test.sk', $jana['data']['email'] ?? '', "Jana: email correct");
        } else {
            fail("group form: Jana submission not found in results");
        }
    }

    // CSV-specific: directly check CSV file headers
    if ($backend === 'csv') {
        $csvFile = $testSubmissionsDir . '/test-groups.csv';
        if (file_exists($csvFile)) {
            $fp = fopen($csvFile, 'r');
            $headers = fgetcsv($fp, 0, ',', '"', '\\');
            fclose($fp);
            if (is_array($headers)) {
                if (!in_array('name_row', $headers, true) && !in_array('address_row', $headers, true)) {
                    pass("CSV headers: no group container names (name_row, address_row)");
                } else {
                    fail("CSV headers contain group container names", implode(', ', $headers));
                }
                if (in_array('first_name', $headers, true) && in_array('last_name', $headers, true)) {
                    pass("CSV headers: child fields present (first_name, last_name)");
                } else {
                    fail("CSV headers missing child fields", implode(', ', $headers));
                }
                if (in_array('street', $headers, true) && in_array('city', $headers, true)) {
                    pass("CSV headers: address child fields present (street, city)");
                } else {
                    fail("CSV headers missing address child fields", implode(', ', $headers));
                }
            }
        } else {
            fail("CSV file not created: $csvFile");
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Test 2: Conditional Fields
    // ─────────────────────────────────────────────────────────────
    section("Test 2: Conditional Fields ($backend)");

    // Submit 1: Email contact method
    $cond1 = submitForm('test-conditionals', [
        'contact_method' => 'Email',
        'email_address'  => 'a@b.com',
        'name'           => 'Alice',
        'priority'       => 'High',
        'notes'          => 'Test note',
    ]);
    if ($cond1['code'] === 200) {
        pass("conditional submit 1 (Email): OK");
    } else {
        fail("conditional submit 1 (Email): HTTP {$cond1['code']}", substr($cond1['body'], 0, 300));
    }

    // Submit 2: Phone contact method
    $cond2 = submitForm('test-conditionals', [
        'contact_method' => 'Phone',
        'phone_number'   => '+421123456789',
        'name'           => 'Bob',
        'priority'       => 'Low',
        'notes'          => '',
    ]);
    if ($cond2['code'] === 200) {
        pass("conditional submit 2 (Phone): OK");
    } else {
        fail("conditional submit 2 (Phone): HTTP {$cond2['code']}", substr($cond2['body'], 0, 300));
    }

    // Submit 3: Post contact method
    $cond3 = submitForm('test-conditionals', [
        'contact_method' => 'Post',
        'postal_address' => '123 Main St',
        'name'           => 'Charlie',
        'priority'       => 'Medium',
        'notes'          => 'Urgent',
    ]);
    if ($cond3['code'] === 200) {
        pass("conditional submit 3 (Post): OK");
    } else {
        fail("conditional submit 3 (Post): HTTP {$cond3['code']}", substr($cond3['body'], 0, 300));
    }

    // Read back all three
    usleep(300000);
    $condSubs = getSubmissions('test-conditionals');

    if ($condSubs === null) {
        fail("conditional form: cannot read submissions via API");
    } else {
        assertEqual(3, count($condSubs), "conditional form: 3 submissions returned");

        // Find each submission by name
        $alice = $bob = $charlie = null;
        foreach ($condSubs as $sub) {
            $name = $sub['data']['name'] ?? '';
            if ($name === 'Alice') $alice = $sub;
            if ($name === 'Bob') $bob = $sub;
            if ($name === 'Charlie') $charlie = $sub;
        }

        // Alice: Email method
        if ($alice) {
            assertEqual('Email', $alice['data']['contact_method'] ?? '', "Alice: contact_method = Email");
            assertEqual('a@b.com', $alice['data']['email_address'] ?? '', "Alice: email_address correct");
            assertEqual('Alice', $alice['data']['name'] ?? '', "Alice: name correct");
            assertEqual('High', $alice['data']['priority'] ?? '', "Alice: priority = High");
            assertEqual('Test note', $alice['data']['notes'] ?? '', "Alice: notes correct");
        } else {
            fail("conditional form: Alice submission not found");
        }

        // Bob: Phone method
        if ($bob) {
            assertEqual('Phone', $bob['data']['contact_method'] ?? '', "Bob: contact_method = Phone");
            assertEqual('+421123456789', $bob['data']['phone_number'] ?? '', "Bob: phone_number correct");
            assertEqual('Bob', $bob['data']['name'] ?? '', "Bob: name correct");
            assertEqual('Low', $bob['data']['priority'] ?? '', "Bob: priority = Low");
            // Bob's email_address should be empty (not shown)
            $bobEmail = $bob['data']['email_address'] ?? '';
            assertEqual('', $bobEmail, "Bob: email_address empty (conditional hidden)");
        } else {
            fail("conditional form: Bob submission not found");
        }

        // Charlie: Post method
        if ($charlie) {
            assertEqual('Post', $charlie['data']['contact_method'] ?? '', "Charlie: contact_method = Post");
            assertEqual('123 Main St', $charlie['data']['postal_address'] ?? '', "Charlie: postal_address correct");
            assertEqual('Charlie', $charlie['data']['name'] ?? '', "Charlie: name correct");
            assertEqual('Medium', $charlie['data']['priority'] ?? '', "Charlie: priority = Medium");
            assertEqual('Urgent', $charlie['data']['notes'] ?? '', "Charlie: notes correct");
        } else {
            fail("conditional form: Charlie submission not found");
        }

        // CRITICAL alignment test: verify name/priority/notes columns are correct
        // across ALL submissions regardless of which conditional was active.
        // This is the bug that causes column shifts in CSV.
        if ($alice && $bob && $charlie) {
            $allAligned = true;
            foreach ([$alice, $bob, $charlie] as $sub) {
                $data = $sub['data'];
                $n = $data['name'] ?? null;
                $p = $data['priority'] ?? null;
                // name should never be empty — it's required
                if (empty($n)) {
                    fail("ALIGNMENT: name is empty for submission {$sub['id']}");
                    $allAligned = false;
                }
                // priority should never be empty — it's required
                if (empty($p)) {
                    fail("ALIGNMENT: priority is empty for submission {$sub['id']}");
                    $allAligned = false;
                }
                // name should be a name, not a conditional field value leaked in
                if (in_array($n, ['a@b.com', '123456', '123 Main St', 'Email', 'Phone', 'Post'], true)) {
                    fail("ALIGNMENT: name column contains wrong value '$n' (column shift detected!)");
                    $allAligned = false;
                }
                // priority should be a priority value, not something else
                if (!in_array($p, ['Low', 'Medium', 'High'], true)) {
                    fail("ALIGNMENT: priority column contains wrong value '$p' (column shift detected!)");
                    $allAligned = false;
                }
            }
            if ($allAligned) {
                pass("ALIGNMENT: name, priority, notes correctly aligned across all 3 conditional variants");
            }
        }
    }

    // CSV-specific: check column alignment in raw CSV
    if ($backend === 'csv') {
        $csvFile = $testSubmissionsDir . '/test-conditionals.csv';
        if (file_exists($csvFile)) {
            $fp = fopen($csvFile, 'r');
            $headers = fgetcsv($fp, 0, ',', '"', '\\');
            $rows = [];
            while (($row = fgetcsv($fp, 0, ',', '"', '\\')) !== false) {
                $rows[] = $row;
            }
            fclose($fp);

            if (is_array($headers)) {
                // All rows must have same number of columns as header
                $headerCount = count($headers);
                $columnMismatch = false;
                foreach ($rows as $i => $row) {
                    if (count($row) !== $headerCount) {
                        fail("CSV row " . ($i + 1) . " has " . count($row) . " columns, header has $headerCount");
                        $columnMismatch = true;
                    }
                }
                if (!$columnMismatch) {
                    pass("CSV: all " . count($rows) . " data rows match header column count ($headerCount)");
                }

                // Check that name column has name values in every row
                $nameIdx = array_search('name', $headers, true);
                $priorityIdx = array_search('priority', $headers, true);
                if ($nameIdx !== false && $priorityIdx !== false) {
                    $csvAligned = true;
                    $expectedNames = ['Alice', 'Bob', 'Charlie'];
                    foreach ($rows as $i => $row) {
                        $csvName = $row[$nameIdx] ?? '';
                        $csvPriority = $row[$priorityIdx] ?? '';
                        if (!in_array($csvName, $expectedNames, true)) {
                            fail("CSV row " . ($i + 1) . ": name column has '$csvName' (expected one of: " . implode(', ', $expectedNames) . ")");
                            $csvAligned = false;
                        }
                        if (!in_array($csvPriority, ['Low', 'Medium', 'High'], true)) {
                            fail("CSV row " . ($i + 1) . ": priority column has '$csvPriority' (expected Low/Medium/High)");
                            $csvAligned = false;
                        }
                    }
                    if ($csvAligned) {
                        pass("CSV raw: name and priority columns correctly aligned in all rows");
                    }
                } else {
                    fail("CSV: 'name' or 'priority' column not found in headers", implode(', ', $headers));
                }
            }
        } else {
            fail("CSV file not created: $csvFile");
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Test 3: All Field Types
    // ─────────────────────────────────────────────────────────────
    section("Test 3: All Field Types ($backend)");

    $allTypesData = [
        'text_field'     => 'Hello World',
        'email_field'    => 'all@types.test',
        'tel_field'      => '+421900123456',
        'url_field'      => 'https://example.com/page?q=1&b=2',
        'number_field'   => '42',
        'date_field'     => '2026-03-17',
        'textarea_field' => "Line one\nLine two\nLine three",
        'select_field'   => 'opt_b',
        'radio_field'    => 'maybe',
        'checkbox_field' => ['red', 'blue'],
        'rating_field'   => '4',
        'hidden_field'   => 'secret-42',
    ];

    $atResult = submitForm('test-all-types', $allTypesData);
    if ($atResult['code'] === 200) {
        pass("all-types submit: OK (id: {$atResult['json']['submission_id']})");
    } else {
        fail("all-types submit: HTTP {$atResult['code']}", substr($atResult['body'], 0, 300));
    }

    usleep(300000);
    $atSubs = getSubmissions('test-all-types');

    if ($atSubs === null || empty($atSubs)) {
        fail("all-types: cannot read submissions");
    } else {
        $atData = $atSubs[0]['data'] ?? [];

        assertEqual('Hello World', $atData['text_field'] ?? '', "all-types: text");
        assertEqual('all@types.test', $atData['email_field'] ?? '', "all-types: email");
        assertEqual('+421900123456', $atData['tel_field'] ?? '', "all-types: tel");
        assertEqual('https://example.com/page?q=1&b=2', $atData['url_field'] ?? '', "all-types: url");
        assertEqual('42', $atData['number_field'] ?? '', "all-types: number");
        assertEqual('2026-03-17', $atData['date_field'] ?? '', "all-types: date");
        assertEqual('opt_b', $atData['select_field'] ?? '', "all-types: select");
        assertEqual('maybe', $atData['radio_field'] ?? '', "all-types: radio");
        assertEqual('4', $atData['rating_field'] ?? '', "all-types: rating");
        assertEqual('secret-42', $atData['hidden_field'] ?? '', "all-types: hidden");

        // Textarea: may have newline normalization
        $taVal = $atData['textarea_field'] ?? '';
        if (str_contains($taVal, 'Line one') && str_contains($taVal, 'Line two') && str_contains($taVal, 'Line three')) {
            pass("all-types: textarea preserves multiline content");
        } else {
            fail("all-types: textarea content corrupted", var_export($taVal, true));
        }

        // Checkbox: stored as array (file/sqlite) or semicolon-joined string (csv)
        $cbVal = $atData['checkbox_field'] ?? '';
        if (is_array($cbVal)) {
            // file or sqlite backend
            if (in_array('red', $cbVal, true) && in_array('blue', $cbVal, true) && !in_array('green', $cbVal, true)) {
                pass("all-types: checkbox array [red, blue] correct");
            } else {
                fail("all-types: checkbox array wrong", json_encode($cbVal));
            }
        } elseif (is_string($cbVal)) {
            // CSV backend joins with "; "
            if (str_contains($cbVal, 'red') && str_contains($cbVal, 'blue') && !str_contains($cbVal, 'green')) {
                pass("all-types: checkbox string contains red, blue (CSV format)");
            } else {
                fail("all-types: checkbox string wrong", $cbVal);
            }
        } else {
            fail("all-types: checkbox unexpected type", gettype($cbVal));
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Test 4: Special Characters
    // ─────────────────────────────────────────────────────────────
    section("Test 4: Special Characters ($backend)");

    $specialData = [
        'title'       => 'Commas, "quotes", and <tags>',
        'description' => "Line with unicode: ä č ď ľ š ť ž\nLine with HTML: <script>alert('xss')</script>\nLine with equals: =SUM(A1:A10)\nLine with backslash: C:\\path\\to\\file",
        'category'    => 'a&b',
    ];

    $spResult = submitForm('test-special-chars', $specialData);
    if ($spResult['code'] === 200) {
        pass("special-chars submit: OK (id: {$spResult['json']['submission_id']})");
    } else {
        fail("special-chars submit: HTTP {$spResult['code']}", substr($spResult['body'], 0, 300));
    }

    usleep(300000);
    $spSubs = getSubmissions('test-special-chars');

    if ($spSubs === null || empty($spSubs)) {
        fail("special-chars: cannot read submissions");
    } else {
        $spData = $spSubs[0]['data'] ?? [];

        // Title: commas, quotes, angle brackets
        $titleVal = $spData['title'] ?? '';
        if (str_contains($titleVal, 'Commas,') && str_contains($titleVal, '"quotes"') && str_contains($titleVal, '<tags>')) {
            pass("special-chars: title preserves commas, quotes, angle brackets");
        } else {
            fail("special-chars: title corrupted", $titleVal);
        }

        // Description: unicode, HTML, formula prefix, backslash
        $descVal = $spData['description'] ?? '';
        if (str_contains($descVal, 'ä') && str_contains($descVal, 'č') && str_contains($descVal, 'ž')) {
            pass("special-chars: unicode diacritics preserved (ä č ž)");
        } else {
            fail("special-chars: unicode diacritics lost", $descVal);
        }

        if (str_contains($descVal, 'ď') && str_contains($descVal, 'ľ') && str_contains($descVal, 'š') && str_contains($descVal, 'ť')) {
            pass("special-chars: Slovak diacritics preserved (ď ľ š ť)");
        } else {
            fail("special-chars: Slovak diacritics lost", $descVal);
        }

        if (str_contains($descVal, 'script') && str_contains($descVal, 'alert')) {
            pass("special-chars: HTML content preserved (not stripped)");
        } else {
            fail("special-chars: HTML content stripped or corrupted", $descVal);
        }

        // For CSV, the =SUM formula prefix gets sanitized with a leading quote
        if ($backend === 'csv') {
            // CSV backend applies csvSanitize: =SUM → '=SUM
            if (str_contains($descVal, 'SUM(A1:A10)')) {
                pass("special-chars: formula text preserved in CSV (possibly with sanitization prefix)");
            } else {
                fail("special-chars: formula text lost in CSV", $descVal);
            }
        } else {
            if (str_contains($descVal, '=SUM(A1:A10)')) {
                pass("special-chars: formula text preserved exactly");
            } else {
                fail("special-chars: formula text lost", $descVal);
            }
        }

        if (str_contains($descVal, 'backslash') && str_contains($descVal, '\\')) {
            pass("special-chars: backslash preserved");
        } else {
            fail("special-chars: backslash lost", $descVal);
        }

        // Category with ampersand
        assertEqual('a&b', $spData['category'] ?? '', "special-chars: ampersand in select value preserved");
    }

    // ─────────────────────────────────────────────────────────────
    // Test 5: Complex Conditional Logic
    // ─────────────────────────────────────────────────────────────
    section("Test 5: Complex Conditional Logic ($backend)");

    // Scenario A — Minimal (no conditionals active)
    $clA = submitForm('test-complex-logic', [
        'full_name'        => 'Anna Minimal',
        'project_type'     => 'Residential',
        'need_installation' => 'No',
        'need_delivery'    => 'No',
        'heard_from'       => ['Social media'],
        'has_budget'       => 'No',
        'contact_method'   => 'Email',
        'contact_email'    => 'anna@test.com',
        'contact_phone'    => '+421900000001',
        'priority'         => 'Low',
        'message'          => 'Basic test',
    ]);
    if ($clA['code'] === 200 && ($clA['json']['status'] ?? '') === 'ok') {
        pass("complex-logic submit A (Minimal): OK");
    } else {
        fail("complex-logic submit A (Minimal): HTTP {$clA['code']}", substr($clA['body'], 0, 300));
    }

    // Scenario B — Chain conditional (Commercial → company_name → company_vat)
    $clB = submitForm('test-complex-logic', [
        'full_name'        => 'Boris Chain',
        'project_type'     => 'Commercial',
        'company_name'     => 'ACME s.r.o.',
        'company_vat'      => 'SK2020123456',
        'need_installation' => 'Yes',
        'need_delivery'    => 'No',
        'heard_from'       => ['Advertisement'],
        'has_budget'       => 'Yes',
        'budget_amount'    => '50000',
        'contact_method'   => 'Email',
        'contact_email'    => 'boris@acme.sk',
        'contact_phone'    => '+421900000002',
        'priority'         => 'High',
        'message'          => 'Chain test',
    ]);
    if ($clB['code'] === 200 && ($clB['json']['status'] ?? '') === 'ok') {
        pass("complex-logic submit B (Chain): OK");
    } else {
        fail("complex-logic submit B (Chain): HTTP {$clB['code']}", substr($clB['body'], 0, 300));
    }

    // Scenario C — AND condition (both Yes → preferred_date)
    $clC = submitForm('test-complex-logic', [
        'full_name'        => 'Cyril AndLogic',
        'project_type'     => 'Residential',
        'need_installation' => 'Yes',
        'need_delivery'    => 'Yes',
        'preferred_date'   => '2026-06-15',
        'heard_from'       => ['Social media'],
        'has_budget'       => 'No',
        'contact_method'   => 'Email',
        'contact_email'    => 'cyril@test.com',
        'contact_phone'    => '+421900000003',
        'priority'         => 'Medium',
        'message'          => 'AND test',
    ]);
    if ($clC['code'] === 200 && ($clC['json']['status'] ?? '') === 'ok') {
        pass("complex-logic submit C (AND): OK");
    } else {
        fail("complex-logic submit C (AND): HTTP {$clC['code']}", substr($clC['body'], 0, 300));
    }

    // Scenario D — OR condition (Friend checkbox → referral_detail) + Fax
    $clD = submitForm('test-complex-logic', [
        'full_name'        => 'Dana OrLogic',
        'project_type'     => 'Government',
        'need_installation' => 'No',
        'need_delivery'    => 'No',
        'heard_from'       => ['Friend', 'Social media'],
        'referral_detail'  => 'My colleague Jano',
        'has_budget'       => 'Yes',
        'budget_amount'    => '100000',
        'contact_method'   => 'Fax',
        'contact_email'    => 'dana@gov.sk',
        'contact_phone'    => '+421900000004',
        'contact_fax'      => '+421200000001',
        'priority'         => 'High',
        'message'          => 'OR + fax test',
    ]);
    if ($clD['code'] === 200 && ($clD['json']['status'] ?? '') === 'ok') {
        pass("complex-logic submit D (OR + Fax): OK");
    } else {
        fail("complex-logic submit D (OR + Fax): HTTP {$clD['code']}", substr($clD['body'], 0, 300));
    }

    // Scenario E — Negation (has_budget NOT "No" → budget_amount)
    $clE = submitForm('test-complex-logic', [
        'full_name'        => 'Emil Negation',
        'project_type'     => 'Residential',
        'need_installation' => 'No',
        'need_delivery'    => 'Yes',
        'heard_from'       => ['Other'],
        'referral_detail'  => 'Google search',
        'has_budget'       => 'Yes',
        'budget_amount'    => '25000',
        'contact_method'   => 'Email',
        'contact_email'    => 'emil@test.com',
        'contact_phone'    => '+421900000005',
        'priority'         => 'Low',
        'message'          => 'Negation test',
    ]);
    if ($clE['code'] === 200 && ($clE['json']['status'] ?? '') === 'ok') {
        pass("complex-logic submit E (Negation): OK");
    } else {
        fail("complex-logic submit E (Negation): HTTP {$clE['code']}", substr($clE['body'], 0, 300));
    }

    // Read back all 5 submissions
    usleep(300000);
    $clSubs = getSubmissions('test-complex-logic');

    if ($clSubs === null) {
        fail("complex-logic: cannot read submissions via API");
    } else {
        assertEqual(5, count($clSubs), "complex-logic: 5 submissions returned");

        // Find each submission by full_name
        $anna = $boris = $cyril = $dana = $emil = null;
        foreach ($clSubs as $sub) {
            $fn = $sub['data']['full_name'] ?? '';
            if ($fn === 'Anna Minimal') $anna = $sub;
            if ($fn === 'Boris Chain') $boris = $sub;
            if ($fn === 'Cyril AndLogic') $cyril = $sub;
            if ($fn === 'Dana OrLogic') $dana = $sub;
            if ($fn === 'Emil Negation') $emil = $sub;
        }

        // ── Scenario A assertions: Minimal (no conditionals active) ──
        if ($anna) {
            assertEqual('Anna Minimal', $anna['data']['full_name'] ?? '', "Anna: full_name correct");
            assertEqual('Low', $anna['data']['priority'] ?? '', "Anna: priority correct");
            assertEqual('Basic test', $anna['data']['message'] ?? '', "Anna: message correct");
            assertEqual('', $anna['data']['company_name'] ?? '', "Anna: company_name empty (Residential)");
            assertEqual('', $anna['data']['company_vat'] ?? '', "Anna: company_vat empty (no chain)");
            assertEqual('', $anna['data']['preferred_date'] ?? '', "Anna: preferred_date empty (AND not met)");
            assertEqual('', $anna['data']['referral_detail'] ?? '', "Anna: referral_detail empty (no Friend/Other)");
            assertEqual('', $anna['data']['budget_amount'] ?? '', "Anna: budget_amount empty (has_budget=No)");
            assertEqual('', $anna['data']['contact_fax'] ?? '', "Anna: contact_fax empty (method=Email)");
        } else {
            fail("complex-logic: Anna submission not found");
        }

        // ── Scenario B assertions: Chain conditional ──
        if ($boris) {
            assertEqual('Boris Chain', $boris['data']['full_name'] ?? '', "Boris: full_name correct");
            assertEqual('High', $boris['data']['priority'] ?? '', "Boris: priority correct");
            assertEqual('Chain test', $boris['data']['message'] ?? '', "Boris: message correct");
            assertEqual('ACME s.r.o.', $boris['data']['company_name'] ?? '', "Boris: company_name = ACME s.r.o.");
            assertEqual('SK2020123456', $boris['data']['company_vat'] ?? '', "Boris: company_vat = SK2020123456 (chain)");
            assertEqual('50000', $boris['data']['budget_amount'] ?? '', "Boris: budget_amount = 50000 (negation active)");
        } else {
            fail("complex-logic: Boris submission not found");
        }

        // ── Scenario C assertions: AND condition ──
        if ($cyril) {
            assertEqual('Cyril AndLogic', $cyril['data']['full_name'] ?? '', "Cyril: full_name correct");
            assertEqual('Medium', $cyril['data']['priority'] ?? '', "Cyril: priority correct");
            assertEqual('AND test', $cyril['data']['message'] ?? '', "Cyril: message correct");
            assertEqual('2026-06-15', $cyril['data']['preferred_date'] ?? '', "Cyril: preferred_date = 2026-06-15 (AND met)");
        } else {
            fail("complex-logic: Cyril submission not found");
        }

        // ── Scenario D assertions: OR condition + Fax ──
        if ($dana) {
            assertEqual('Dana OrLogic', $dana['data']['full_name'] ?? '', "Dana: full_name correct");
            assertEqual('High', $dana['data']['priority'] ?? '', "Dana: priority correct");
            assertEqual('OR + fax test', $dana['data']['message'] ?? '', "Dana: message correct");
            assertEqual('My colleague Jano', $dana['data']['referral_detail'] ?? '', "Dana: referral_detail = My colleague Jano (OR met)");
            assertEqual('+421200000001', $dana['data']['contact_fax'] ?? '', "Dana: contact_fax = +421200000001 (Fax method)");
            assertEqual('100000', $dana['data']['budget_amount'] ?? '', "Dana: budget_amount = 100000 (negation active)");
        } else {
            fail("complex-logic: Dana submission not found");
        }

        // ── Scenario E assertions: Negation ──
        if ($emil) {
            assertEqual('Emil Negation', $emil['data']['full_name'] ?? '', "Emil: full_name correct");
            assertEqual('Low', $emil['data']['priority'] ?? '', "Emil: priority correct");
            assertEqual('Negation test', $emil['data']['message'] ?? '', "Emil: message correct");
            assertEqual('25000', $emil['data']['budget_amount'] ?? '', "Emil: budget_amount = 25000 (NOT No)");
            assertEqual('Google search', $emil['data']['referral_detail'] ?? '', "Emil: referral_detail = Google search (OR met via Other)");
        } else {
            fail("complex-logic: Emil submission not found");
        }

        // ── Critical alignment test across ALL 5 submissions ──
        $expectedEntries = [
            'Anna Minimal'  => ['priority' => 'Low',    'message' => 'Basic test'],
            'Boris Chain'   => ['priority' => 'High',   'message' => 'Chain test'],
            'Cyril AndLogic' => ['priority' => 'Medium', 'message' => 'AND test'],
            'Dana OrLogic'  => ['priority' => 'High',   'message' => 'OR + fax test'],
            'Emil Negation' => ['priority' => 'Low',    'message' => 'Negation test'],
        ];
        $alignAllOk = true;
        foreach ($clSubs as $sub) {
            $data = $sub['data'] ?? [];
            $fn = $data['full_name'] ?? '';
            $pr = $data['priority'] ?? '';
            $msg = $data['message'] ?? '';
            $sid = $sub['id'] ?? '?';

            if (empty($fn)) {
                fail("ALIGNMENT(complex): full_name is empty for submission $sid");
                $alignAllOk = false;
                continue;
            }
            if (!isset($expectedEntries[$fn])) {
                fail("ALIGNMENT(complex): unexpected full_name '$fn' in submission $sid");
                $alignAllOk = false;
                continue;
            }
            $exp = $expectedEntries[$fn];
            if ($pr !== $exp['priority']) {
                fail("ALIGNMENT(complex): $fn priority expected '{$exp['priority']}', got '$pr' (column shift?)");
                $alignAllOk = false;
            }
            if ($msg !== $exp['message']) {
                fail("ALIGNMENT(complex): $fn message expected '{$exp['message']}', got '$msg' (column shift?)");
                $alignAllOk = false;
            }
            // Ensure full_name is not a leaked conditional value
            if (in_array($fn, ['ACME s.r.o.', 'SK2020123456', '50000', '100000', '25000', 'Email', 'Fax', '+421200000001'], true)) {
                fail("ALIGNMENT(complex): full_name column contains wrong value '$fn' (column shift detected!)");
                $alignAllOk = false;
            }
            if (!in_array($pr, ['Low', 'Medium', 'High'], true)) {
                fail("ALIGNMENT(complex): priority column contains wrong value '$pr' (column shift detected!)");
                $alignAllOk = false;
            }
        }
        if ($alignAllOk) {
            pass("ALIGNMENT(complex): full_name, priority, message correctly aligned across all 5 conditional variants");
        }

        // Verify group container key is absent
        foreach ($clSubs as $sub) {
            $data = $sub['data'] ?? [];
            $sid = $sub['id'] ?? '?';
            assertKeyMissing($data, 'contact_row', "[$sid] no 'contact_row' key in stored data");
        }
    }

    // CSV-specific: check column alignment in raw CSV
    if ($backend === 'csv') {
        $csvFile = $testSubmissionsDir . '/test-complex-logic.csv';
        if (file_exists($csvFile)) {
            $fp = fopen($csvFile, 'r');
            $headers = fgetcsv($fp, 0, ',', '"', '\\');
            $rows = [];
            while (($row = fgetcsv($fp, 0, ',', '"', '\\')) !== false) {
                $rows[] = $row;
            }
            fclose($fp);

            if (is_array($headers)) {
                // All rows must have same number of columns as header
                $headerCount = count($headers);
                $columnMismatch = false;
                foreach ($rows as $i => $row) {
                    if (count($row) !== $headerCount) {
                        fail("CSV complex-logic row " . ($i + 1) . " has " . count($row) . " columns, header has $headerCount");
                        $columnMismatch = true;
                    }
                }
                if (!$columnMismatch) {
                    pass("CSV complex-logic: all " . count($rows) . " data rows match header column count ($headerCount)");
                }

                // Check that full_name column has name values in every row
                $fnIdx = array_search('full_name', $headers, true);
                $prIdx = array_search('priority', $headers, true);
                $msgIdx = array_search('message', $headers, true);
                if ($fnIdx !== false && $prIdx !== false && $msgIdx !== false) {
                    $csvAligned = true;
                    $expectedNames = ['Anna Minimal', 'Boris Chain', 'Cyril AndLogic', 'Dana OrLogic', 'Emil Negation'];
                    foreach ($rows as $i => $row) {
                        $csvFn = $row[$fnIdx] ?? '';
                        $csvPr = $row[$prIdx] ?? '';
                        $csvMsg = $row[$msgIdx] ?? '';
                        if (!in_array($csvFn, $expectedNames, true)) {
                            fail("CSV complex-logic row " . ($i + 1) . ": full_name column has '$csvFn' (expected one of: " . implode(', ', $expectedNames) . ")");
                            $csvAligned = false;
                        }
                        if (!in_array($csvPr, ['Low', 'Medium', 'High'], true)) {
                            fail("CSV complex-logic row " . ($i + 1) . ": priority column has '$csvPr' (expected Low/Medium/High)");
                            $csvAligned = false;
                        }
                    }
                    if ($csvAligned) {
                        pass("CSV complex-logic raw: full_name, priority, message columns correctly aligned in all rows");
                    }
                } else {
                    fail("CSV complex-logic: 'full_name', 'priority', or 'message' column not found in headers", implode(', ', $headers));
                }

                // Verify group container name is NOT in CSV headers
                if (!in_array('contact_row', $headers, true)) {
                    pass("CSV complex-logic headers: no group container name (contact_row)");
                } else {
                    fail("CSV complex-logic headers contain group container name 'contact_row'", implode(', ', $headers));
                }
            }
        } else {
            fail("CSV file not created: $csvFile");
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Test 6: Dynamic Options (options_from)
    // ─────────────────────────────────────────────────────────────
    section("Test 6: Dynamic Options — options_from ($backend)");

    // Server may have died under Test 5 load — verify and restart if needed
    $healthCheck = httpGet("$baseUrl/submit.php?form=test-all-types&action=definition");
    if ($healthCheck['code'] === 0) {
        warn("server unresponsive before Test 6, restarting...");
        restartServer();
        usleep(500000);
    }

    // 6a: Form definition should include options_from
    $defResult = httpGet("$baseUrl/submit.php?form=test-dynamic-options&action=definition");
    if ($defResult['code'] === 200) {
        pass("dynamic-options: definition endpoint returns 200");
        $def = $defResult['json'];
        $countryField = null;
        foreach ($def['fields'] ?? [] as $f) {
            if (($f['name'] ?? '') === 'country') $countryField = $f;
        }
        if ($countryField && !empty($countryField['options_from'])) {
            pass("dynamic-options: country field has options_from in definition");
        } else {
            fail("dynamic-options: country field missing options_from", json_encode($countryField));
        }
    } else {
        fail("dynamic-options: definition endpoint HTTP {$defResult['code']}", substr($defResult['body'], 0, 300));
    }

    // 6b: Test options endpoint returns valid JSON
    $optResult = httpGet("$baseUrl/tests/test-options-endpoint.php");
    if ($optResult['code'] === 200 && is_array($optResult['json'])) {
        $optCount = count($optResult['json']);
        if ($optCount > 0) {
            pass("dynamic-options: options endpoint returns $optCount options");
            $firstOpt = $optResult['json'][0];
            if (isset($firstOpt['value']) && isset($firstOpt['label'])) {
                pass("dynamic-options: options have value/label structure");
            } else {
                fail("dynamic-options: options missing value/label", json_encode($firstOpt));
            }
        } else {
            fail("dynamic-options: options endpoint returned empty array");
        }
    } else {
        fail("dynamic-options: options endpoint HTTP {$optResult['code']}", substr($optResult['body'], 0, 200));
    }

    // 6c: Submit with a dynamic option value (server skips option validation for options_from)
    $dynResult = submitForm('test-dynamic-options', [
        'name'     => 'Dynamic Test',
        'country'  => 'SK',
        'category' => 'electronics',
    ]);
    if ($dynResult['code'] === 200 && ($dynResult['json']['status'] ?? '') === 'ok') {
        pass("dynamic-options: submit with dynamic option value OK (id: {$dynResult['json']['submission_id']})");
    } else {
        fail("dynamic-options: submit failed HTTP {$dynResult['code']}", substr($dynResult['body'], 0, 300));
    }

    // 6d: Verify stored data
    usleep(300000);
    $dynSubs = getSubmissions('test-dynamic-options');
    if ($dynSubs !== null && !empty($dynSubs)) {
        $dynData = $dynSubs[0]['data'] ?? [];
        assertEqual('Dynamic Test', $dynData['name'] ?? '', "dynamic-options: name stored correctly");
        assertEqual('SK', $dynData['country'] ?? '', "dynamic-options: country value stored correctly");
        assertEqual('electronics', $dynData['category'] ?? '', "dynamic-options: category value stored correctly");
    } else {
        fail("dynamic-options: cannot read submissions");
    }

    // ─────────────────────────────────────────────────────────────
    // Test 7: Action Response Override
    // ─────────────────────────────────────────────────────────────
    section("Test 7: Action Response Override ($backend)");

    $arResult = submitForm('test-action-response', [
        'product' => 'Widget Pro',
        'email'   => 'shop@test.com',
    ]);

    if ($arResult['code'] === 200 && ($arResult['json']['status'] ?? '') === 'ok') {
        pass("action-response: submit OK (id: {$arResult['json']['submission_id']})");

        // Check custom fields injected by the action
        $json = $arResult['json'];
        if (!empty($json['order_id'])) {
            pass("action-response: order_id present in response ({$json['order_id']})");
            // Verify prefix from action config
            if (str_starts_with($json['order_id'], 'ORD-')) {
                pass("action-response: order_id has correct prefix (ORD-)");
            } else {
                fail("action-response: order_id wrong prefix", $json['order_id']);
            }
        } else {
            fail("action-response: order_id missing from response", json_encode($json));
        }

        // Check redirect set by action
        if (!empty($json['redirect'])) {
            pass("action-response: redirect present in response");
            if (str_contains($json['redirect'], 'thank-you') && str_contains($json['redirect'], 'order=ORD-')) {
                pass("action-response: redirect URL contains order ID");
            } else {
                fail("action-response: redirect URL wrong", $json['redirect']);
            }
        } else {
            fail("action-response: redirect missing from response", json_encode($json));
        }

        // submission_id should still be present
        if (!empty($json['submission_id'])) {
            pass("action-response: submission_id still present alongside custom fields");
        } else {
            fail("action-response: submission_id missing");
        }
    } else {
        fail("action-response: submit failed HTTP {$arResult['code']}", substr($arResult['body'], 0, 300));
    }

    // 7b: Verify data was stored despite action response override
    usleep(300000);
    $arSubs = getSubmissions('test-action-response');
    if ($arSubs !== null && !empty($arSubs)) {
        $arData = $arSubs[0]['data'] ?? [];
        assertEqual('Widget Pro', $arData['product'] ?? '', "action-response: product stored correctly");
        assertEqual('shop@test.com', $arData['email'] ?? '', "action-response: email stored correctly");
    } else {
        fail("action-response: cannot read submissions");
    }

    // ─────────────────────────────────────────────────────────────
    // Backend summary
    // ─────────────────────────────────────────────────────────────
    $backendPassed = $passed - $prevPassed;
    $backendFailed = $failed - $prevFailed;
    $backendResults[$backend] = ['passed' => $backendPassed, 'failed' => $backendFailed];

    $bColor = $backendFailed > 0 ? "\033[31m" : "\033[32m";
    out("\n{$bColor}  Backend '$backend': $backendPassed passed, $backendFailed failed\033[0m");
}

// ═════════════════════════════════════════════════════════════════
// Final Results
// ═════════════════════════════════════════════════════════════════

out("\n\033[1m═══════════════════════════════════════════════\033[0m");
out("\033[1m  Per-backend results:\033[0m");
foreach ($backendResults as $be => $r) {
    $c = $r['failed'] > 0 ? "\033[31m" : "\033[32m";
    out("  {$c}$be: {$r['passed']} passed, {$r['failed']} failed\033[0m");
}
out("\033[1m═══════════════════════════════════════════════\033[0m");

$total = $passed + $failed + $warnings;
$color = $failed > 0 ? "\033[31m" : "\033[32m";
out("{$color}Total: $passed passed, $failed failed, $warnings warnings (of $total)\033[0m");
out("\033[1m═══════════════════════════════════════════════\033[0m\n");

exit($failed > 0 ? 1 : 0);
