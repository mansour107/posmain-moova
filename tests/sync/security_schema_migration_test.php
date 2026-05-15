<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_security_schema_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);

    $manager = new SyncSchemaManager();
    $planned = $manager->plannedStatements();
    securitySchemaAssert(isset($planned['security_audit_log']), 'security_audit_log planned statement missing');
    securitySchemaAssert(isset($planned['failed_login_attempts']), 'failed_login_attempts planned statement missing');
    securitySchemaAssert(strpos($planned['security_audit_log'], 'KEY idx_security_audit_event_created') !== false, 'security audit event index missing');
    securitySchemaAssert(strpos($planned['failed_login_attempts'], 'UNIQUE KEY uq_failed_login_identity') !== false, 'failed login unique key missing');

    $manager->apply($conn);
    $inspect = $manager->inspect($conn);
    securitySchemaAssert(!empty($inspect['security_audit_log']['exists']), 'security_audit_log table not created');
    securitySchemaAssert(!empty($inspect['failed_login_attempts']['exists']), 'failed_login_attempts table not created');
    securitySchemaAssert(in_array('idx_security_audit_event_created', $inspect['security_audit_log']['indexes'], true), 'security audit event index not found');
    securitySchemaAssert(in_array('uq_failed_login_identity', $inspect['failed_login_attempts']['indexes'], true), 'failed login unique key not found');
    securitySchemaAssert($manager->pendingStatements($conn) === [], 'security schema apply should be idempotent');

    echo "security-schema-migration-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function securitySchemaAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
