<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/TableOrderService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_table_counter_smoke_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    $conn->query("
        CREATE TABLE ot_head (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            pro_id INT NULL,
            pro_tybe INT NULL,
            tenant INT NULL DEFAULT 0,
            branch INT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE journal_heads (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            journal_id INT NULL,
            total DECIMAL(15,4) NULL,
            jdate DATE NULL,
            tenant INT NULL DEFAULT 0,
            branch INT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("INSERT INTO ot_head (pro_id, pro_tybe, tenant, branch) VALUES (20, 9, 0, 0), (99, 3, 0, 0)");
    $conn->query("INSERT INTO journal_heads (journal_id, total, jdate, tenant, branch) VALUES (30, 0, CURDATE(), 0, 0)");

    (new SyncSchemaManager())->apply($conn);

    $service = new TableOrderService();
    $conn->begin_transaction();
    try {
        $firstProId = $service->nextPosProId($conn, 9, 0, 0);
        $secondProId = $service->nextPosProId($conn, 9, 0, 0);
        $firstJournalId = $service->nextJournalId($conn, 0, 0);
        $secondJournalId = $service->nextJournalId($conn, 0, 0);

        if ($firstProId !== 21 || $secondProId !== 22) {
            throw new RuntimeException("Unexpected pro_id sequence {$firstProId},{$secondProId}");
        }
        if ($firstJournalId !== 31 || $secondJournalId !== 32) {
            throw new RuntimeException("Unexpected journal_id sequence {$firstJournalId},{$secondJournalId}");
        }

        $proCounter = $conn->query("
            SELECT current_value
            FROM document_counters
            WHERE pos_tenant = 0
              AND pos_branch = 0
              AND counter_type = 'pro_id'
              AND counter_key = 'pro_tybe:9'
        ")->fetch_assoc();
        $journalCounter = $conn->query("
            SELECT current_value
            FROM document_counters
            WHERE pos_tenant = 0
              AND pos_branch = 0
              AND counter_type = 'journal_id'
              AND counter_key = 'journal:default'
        ")->fetch_assoc();

        if ((int) ($proCounter['current_value'] ?? 0) !== 22) {
            throw new RuntimeException('Unexpected pro_id counter value');
        }
        if ((int) ($journalCounter['current_value'] ?? 0) !== 32) {
            throw new RuntimeException('Unexpected journal_id counter value');
        }
    } finally {
        $conn->rollback();
    }

    echo "table-order-counter-smoke-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}
