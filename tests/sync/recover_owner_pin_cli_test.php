<?php

/**
 * Executable recovery CLI contract: schema-valid bootstrap status, fail-closed SQL,
 * temporary PIN + auth_version bump.
 */

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$root = dirname(__DIR__, 2);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_recover_owner_pin_' . getmypid();
$pinSecret = 'posmain-recover-cli-test-secret-do-not-use';
$tempPin = '4829';

$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    fwrite(STDERR, "recover-owner-pin-cli-SKIP: mysql unavailable ({$conn->connect_error})\n");
    exit(0);
}

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);

    recoverOwnerPinAssert(
        strpos((string) file_get_contents($root . '/scripts/recover_owner_pin.php'), "status = 'completed'") !== false,
        'recovery CLI must write schema-valid completed status'
    );
    recoverOwnerPinAssert(
        strpos((string) file_get_contents($root . '/scripts/recover_owner_pin.php'), "status = 'complete'") === false,
        'recovery CLI must not write invalid complete status'
    );

    // Legacy users table exists outside SchemaManager CREATE set; ALTER adds PIN columns.
    $conn->query("
        CREATE TABLE IF NOT EXISTS users (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          uname VARCHAR(100) NOT NULL DEFAULT '',
          password VARCHAR(255) NOT NULL DEFAULT '',
          usertype INT NOT NULL DEFAULT 0,
          userrole INT NOT NULL DEFAULT 0,
          isdeleted TINYINT(1) NOT NULL DEFAULT 0,
          PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    (new SyncSchemaManager())->apply($conn);
    $conn->query("
        INSERT INTO users (id, uname, password, usertype, userrole, isdeleted, auth_version, pin_must_change)
        VALUES (1, 'owner_recover', 'x', 2, 1, 0, 3, 0)
    ");
    $conn->query("
        INSERT INTO security_bootstrap_state (id, status, owner_user_id, started_at)
        VALUES (1, 'pending', 1, NOW())
    ");

    $before = $conn->query('SELECT auth_version, pin_must_change, pin_hash, pin_lookup FROM users WHERE id = 1')->fetch_assoc();
    recoverOwnerPinAssert((int) $before['auth_version'] === 3, 'fixture auth_version mismatch');
    recoverOwnerPinAssert((int) $before['pin_must_change'] === 0, 'fixture pin_must_change mismatch');

    $result = recoverOwnerPinRunCli($root, $db, $host, $port, $user, $pass, $pinSecret, [
        '--pin=' . $tempPin,
        '--user-id=1',
        '--force',
    ]);
    recoverOwnerPinAssert($result['code'] === 0, 'recovery CLI should succeed: ' . $result['stderr'] . $result['stdout']);
    recoverOwnerPinAssert(strpos($result['stdout'], 'pin_must_change=1') !== false, 'CLI should report temporary PIN');
    recoverOwnerPinAssert(strpos($result['stdout'], 'auth_version bumped') !== false, 'CLI should report auth_version bump');

    $after = $conn->query('SELECT auth_version, pin_must_change, pin_hash, pin_lookup FROM users WHERE id = 1')->fetch_assoc();
    recoverOwnerPinAssert((int) $after['auth_version'] === 4, 'auth_version must bump');
    recoverOwnerPinAssert((int) $after['pin_must_change'] === 1, 'pin_must_change must be set');
    recoverOwnerPinAssert(trim((string) $after['pin_hash']) !== '', 'pin_hash must be set');
    recoverOwnerPinAssert(trim((string) $after['pin_lookup']) !== '', 'pin_lookup must be set');

    $bootstrap = $conn->query('SELECT status, completed_at FROM security_bootstrap_state WHERE id = 1')->fetch_assoc();
    recoverOwnerPinAssert(($bootstrap['status'] ?? '') === 'completed', 'bootstrap status must be completed');
    recoverOwnerPinAssert(!empty($bootstrap['completed_at']), 'completed_at must be set');

    // Fail closed: invalid PIN format must not mutate bootstrap/auth state further.
    $authBeforeReject = (int) $conn->query('SELECT auth_version FROM users WHERE id = 1')->fetch_assoc()['auth_version'];
    $reject = recoverOwnerPinRunCli($root, $db, $host, $port, $user, $pass, $pinSecret, [
        '--pin=12',
        '--user-id=1',
        '--force',
    ]);
    recoverOwnerPinAssert($reject['code'] !== 0, 'invalid PIN must fail closed');
    $authAfterReject = (int) $conn->query('SELECT auth_version FROM users WHERE id = 1')->fetch_assoc()['auth_version'];
    recoverOwnerPinAssert($authAfterReject === $authBeforeReject, 'failed recovery must not bump auth_version');

    echo "recover-owner-pin-cli-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

/**
 * @param list<string> $args
 * @return array{code:int, stdout:string, stderr:string}
 */
function recoverOwnerPinRunCli(
    string $root,
    string $db,
    string $host,
    int $port,
    string $user,
    string $pass,
    string $pinSecret,
    array $args
): array {
    $cmd = array_merge([PHP_BINARY, $root . '/scripts/recover_owner_pin.php'], $args);
    $env = [
        'POSMAIN_DB_HOST' => $host,
        'POSMAIN_DB_PORT' => (string) $port,
        'POSMAIN_DB_USER' => $user,
        'POSMAIN_DB_PASS' => $pass,
        'POSMAIN_DB_NAME' => $db,
        'POSMAIN_PIN_SECRET' => $pinSecret,
        'POSMAIN_MAIN_AUTH_MODE' => 'pin',
        'POSMAIN_ROLE' => 'branch',
        'POSMAIN_ENV' => 'local',
        'POSMAIN_DISABLE_BRANCH_ENV_FILE_FALLBACK' => '1',
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
    ];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($cmd, $descriptors, $pipes, $root, $env);
    if (!is_resource($process)) {
        throw new RuntimeException('failed to start recover_owner_pin.php');
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
}

function recoverOwnerPinAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
