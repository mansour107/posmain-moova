<?php

/**
 * Runtime proof for PasswordResetService: invalidate, issue, complete, replay/expiry.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/includes/db_bootstrap.php';
require_once $root . '/classes/PasswordService.php';
require_once $root . '/classes/Security/PasswordResetService.php';

function step1ResetAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$conn = posmain_db_connect();
$service = new PasswordResetService();
$service->ensureSchema($conn);

$uname = 'step1_reset_' . getmypid();
$md5 = md5('legacy-secret');

$conn->query("DELETE FROM users WHERE uname = '" . $conn->real_escape_string($uname) . "'");

$cols = [];
$result = $conn->query('SHOW COLUMNS FROM users');
step1ResetAssert($result instanceof mysqli_result, 'users table required');
while ($row = $result->fetch_assoc()) {
    $cols[strtolower((string) $row['Field'])] = true;
}

$fields = ['uname', 'password'];
$values = [$uname, $md5];
$types = 'ss';
if (isset($cols['img'])) {
    $fields[] = 'img';
    $values[] = '';
    $types .= 's';
}
if (isset($cols['userrole'])) {
    $fields[] = 'userrole';
    $values[] = 1;
    $types .= 'i';
}
if (isset($cols['usertype'])) {
    $fields[] = 'usertype';
    $values[] = 2;
    $types .= 'i';
}
if (isset($cols['isdeleted'])) {
    $fields[] = 'isdeleted';
    $values[] = 0;
    $types .= 'i';
}

$placeholders = implode(',', array_fill(0, count($fields), '?'));
$sql = 'INSERT INTO users (' . implode(',', $fields) . ') VALUES (' . $placeholders . ')';
$insert = $conn->prepare($sql);
step1ResetAssert($insert instanceof mysqli_stmt, 'fixture prepare failed: ' . $conn->error);
$insert->bind_param($types, ...$values);
step1ResetAssert($insert->execute(), 'fixture user insert failed: ' . $insert->error);
$userId = (int) $conn->insert_id;
$insert->close();

$invalidated = $service->invalidateLegacyPasswordHashes($conn, ['dry_run' => false]);
step1ResetAssert($invalidated['invalidated'] >= 1, 'expected at least one legacy hash invalidation');

$row = $conn->query('SELECT password FROM users WHERE id = ' . $userId)->fetch_assoc();
step1ResetAssert(!PasswordService::isLegacyMd5Hash((string) $row['password']), 'legacy hash must be replaced');
putenv('POSMAIN_DENY_LEGACY_PASSWORD_AUTH=1');
step1ResetAssert(
    PasswordService::verifyPassword('legacy-secret', (string) $row['password']) === false,
    'invalidated user must not authenticate with old secret'
);
putenv('POSMAIN_DENY_LEGACY_PASSWORD_AUTH');

$issued = $service->issueResetToken($conn, $uname, ['ttl_seconds' => 300, 'created_by' => 'step1-test']);
step1ResetAssert($issued['token'] !== '', 'token must be issued');
$tokenHash = hash('sha256', $issued['token']);
$stored = $conn->query(
    "SELECT token_hash, used_at FROM password_reset_tokens WHERE token_hash = '" . $conn->real_escape_string($tokenHash) . "'"
)->fetch_assoc();
step1ResetAssert($stored && $stored['token_hash'] === $tokenHash, 'only token hash may be stored');
step1ResetAssert($stored['used_at'] === null, 'new token must be unused');

$completed = $service->completeReset($conn, $issued['token'], 'replacement-password-456', ['source' => 'test']);
step1ResetAssert($completed['user_id'] === $userId, 'completed reset must target fixture user');

$row = $conn->query('SELECT password FROM users WHERE id = ' . $userId)->fetch_assoc();
step1ResetAssert(
    PasswordService::verifyPassword('replacement-password-456', (string) $row['password']),
    'new password must verify'
);

$replayFailed = false;
try {
    $service->completeReset($conn, $issued['token'], 'another-password-789', ['source' => 'test']);
} catch (Throwable $exception) {
    $replayFailed = $exception->getMessage() === 'PASSWORD_RESET_TOKEN_USED';
}
step1ResetAssert($replayFailed, 'replay must fail as TOKEN_USED');

$issued2 = $service->issueResetToken($conn, $uname, ['ttl_seconds' => 60, 'created_by' => 'step1-test']);
$conn->query(
    "UPDATE password_reset_tokens SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND)
      WHERE token_hash = '" . $conn->real_escape_string(hash('sha256', $issued2['token'])) . "'"
);
$expiredFailed = false;
try {
    $service->completeReset($conn, $issued2['token'], 'expired-password-000', ['source' => 'test']);
} catch (Throwable $exception) {
    $expiredFailed = $exception->getMessage() === 'PASSWORD_RESET_TOKEN_EXPIRED';
}
step1ResetAssert($expiredFailed, 'expired token must fail');

$audit = $conn->query(
    "SELECT metadata_json FROM security_audit_log
      WHERE event_type IN ('password_reset_issued','password_reset_completed','password_legacy_invalidated')
      ORDER BY id DESC LIMIT 20"
);
while ($audit && ($auditRow = $audit->fetch_assoc())) {
    $meta = (string) ($auditRow['metadata_json'] ?? '');
    step1ResetAssert(
        !str_contains($meta, $issued['token']) && !str_contains($meta, $issued2['token']),
        'audit metadata must never contain plaintext reset tokens'
    );
}

echo "commercial-v1-step1-password-reset-runtime-ok user_id={$userId}\n";
