<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_drawer_schema_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

function drawerSchemaAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    $manager = new SyncSchemaManager();
    $conn->query($manager->plannedStatements()['drawer_sessions']);

    $conn->query("
        INSERT INTO drawer_sessions (
            uuid, user_id, tenant, branch, register_id, opened_at, opened_by,
            status, open_branch_lock, open_register_lock, open_user_lock
        ) VALUES (
            '11111111-1111-4111-8111-111111111111', 33, 0, 0, 1, NOW(), 33,
            'open', NULL, '0:0:r1', '0:0:u33'
        )
    ");

    $pending = $manager->pendingStatements($conn);
    drawerSchemaAssert(
        !isset($pending['drawer_sessions.backfill_open_branch_lock']),
        'valid multi-register session must not be classified as a missing branch lock'
    );

    $conn->query("
        INSERT INTO drawer_sessions (
            uuid, user_id, tenant, branch, opened_at, opened_by,
            status, open_branch_lock, open_register_lock, open_user_lock
        ) VALUES (
            '22222222-2222-4222-8222-222222222222', 44, 2, 3, NOW(), 44,
            'open', NULL, NULL, NULL
        )
    ");
    $pending = $manager->pendingStatements($conn);
    drawerSchemaAssert(isset($pending['drawer_sessions.backfill_open_branch_lock']), 'legacy open session must still be repairable');
    $conn->query($pending['drawer_sessions.backfill_open_branch_lock']);

    $rows = $conn->query('SELECT user_id, open_branch_lock FROM drawer_sessions ORDER BY user_id')->fetch_all(MYSQLI_ASSOC);
    drawerSchemaAssert($rows[0]['open_branch_lock'] === null, 'valid register session branch lock must remain null');
    drawerSchemaAssert($rows[1]['open_branch_lock'] === '2:3', 'legacy session must receive the branch lock');

    echo "drawer_multiregister_schema_readiness_test: OK\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
}
