<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Security/SecurityAuditLogger.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_security_audit_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->apply($conn);

    $_SERVER['REMOTE_ADDR'] = '127.0.0.9';
    $_SERVER['HTTP_USER_AGENT'] = 'phase3-test-agent';
    $logger = new SecurityAuditLogger();
    $record = $logger->record($conn, 'permission_denied', [
        'user_id' => 7,
        'tenant' => 11,
        'branch' => 22,
        'target_type' => 'permission',
        'target_id' => 33,
        'metadata' => ['permission' => 'users.manage'],
    ]);

    securityAuditAssert((int) $record['id'] > 0, 'audit id missing');
    $stmt = $conn->prepare("SELECT * FROM security_audit_log WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $record['id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    securityAuditAssert($row !== null, 'audit row missing');
    securityAuditAssert($row['event_type'] === 'permission_denied', 'event type mismatch');
    securityAuditAssert((int) $row['user_id'] === 7, 'user id mismatch');
    securityAuditAssert((int) $row['tenant'] === 11, 'tenant mismatch');
    securityAuditAssert((int) $row['branch'] === 22, 'branch mismatch');
    securityAuditAssert($row['ip'] === '127.0.0.9', 'ip mismatch');
    securityAuditAssert($row['target_type'] === 'permission', 'target type mismatch');
    securityAuditAssert(json_decode($row['metadata_json'], true)['permission'] === 'users.manage', 'metadata mismatch');

    echo "security-audit-logger-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function securityAuditAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
