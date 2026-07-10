<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_handover_backfill_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

function backfillAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);

    $schema = new SyncSchemaManager();
    $schema->apply($conn);

    $conn->query("
        INSERT INTO drawer_sessions (
            uuid, user_id, tenant, branch, opened_at, opened_by,
            opening_cash, expected_cash, counted_cash, difference,
            closed_at, closed_by, variance_status, status
        ) VALUES (
            UUID(), 10, 1, 2, NOW(), 10,
            100.000, 150.000, 140.000, -10.000,
            NOW(), 10, 'none', 'closed'
        )
    ");
    $sessionId = (int) $conn->insert_id;

    $schema->apply($conn);

    $row = $conn->query("SELECT variance_status FROM drawer_sessions WHERE id = {$sessionId}")->fetch_assoc();
    backfillAssert(($row['variance_status'] ?? '') === 'resolved', 'backfill marks historical variance as resolved');

    $resolutionCount = (int) $conn->query("
        SELECT COUNT(*) AS c
        FROM drawer_session_resolutions
        WHERE drawer_session_id = {$sessionId}
    ")->fetch_assoc()['c'];
    backfillAssert($resolutionCount === 1, 'backfill inserts one synthetic resolution');

    $note = $conn->query("
        SELECT resolution_notes, resolved_by, variance_type, variance_amount
        FROM drawer_session_resolutions
        WHERE drawer_session_id = {$sessionId}
        LIMIT 1
    ")->fetch_assoc();
    backfillAssert(str_contains((string) ($note['resolution_notes'] ?? ''), 'Backfilled at migration'), 'resolution note documents migration');
    backfillAssert((int) ($note['resolved_by'] ?? -1) === 0, 'system resolver id is zero');
    backfillAssert(($note['variance_type'] ?? '') === 'closing', 'closing variance type inferred');
    backfillAssert(abs((float) ($note['variance_amount'] ?? 0) + 10.0) < 0.001, 'variance amount preserved');

    $schema->apply($conn);

    $resolutionCountAfter = (int) $conn->query("
        SELECT COUNT(*) AS c
        FROM drawer_session_resolutions
        WHERE drawer_session_id = {$sessionId}
    ")->fetch_assoc()['c'];
    backfillAssert($resolutionCountAfter === 1, 'second apply does not duplicate resolution rows');

    echo "drawer_handover_backfill_test: OK\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
}
