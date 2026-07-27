<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Sync/KdsSchemaReadinessGuard.php';
require_once __DIR__ . '/../../includes/kds_bootstrap.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_kds_scope_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

function kdsScopeAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    $manager = new SyncSchemaManager();
    $manager->applyKdsSchema($conn);

    kdsScopeAssert($manager->pendingKdsStatements($conn) === [], 'fresh KDS schema must be ready');
    kdsScopeAssert($manager->pendingStatements($conn) !== [], 'fixture must retain unrelated global migrations');
    kdsScopeAssert((new KdsSchemaReadinessGuard())->inspect($conn)['ready'], 'scoped guard must ignore unrelated migrations');

    posmain_ensure_kds_schema($conn);
    $stationCount = (int) $conn->query('SELECT COUNT(*) AS c FROM kds_stations')->fetch_assoc()['c'];
    kdsScopeAssert($stationCount === 1, 'KDS bootstrap must create the default station when scoped schema is ready');

    $conn->query("INSERT INTO kds_ticket_lines (ticket_id, detail_id, line_key, name) VALUES (1, 1, '', 'test')");
    $pending = $manager->pendingKdsStatements($conn);
    kdsScopeAssert(isset($pending['kds_ticket_lines.backfill_line_key']), 'blank line key must be reported as pending');
    $conn->query($pending['kds_ticket_lines.backfill_line_key']);
    kdsScopeAssert(
        !isset($manager->pendingKdsStatements($conn)['kds_ticket_lines.backfill_line_key']),
        'completed line-key backfill must not stay permanently pending'
    );

    echo "kds_scoped_schema_readiness_test: OK\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
}
