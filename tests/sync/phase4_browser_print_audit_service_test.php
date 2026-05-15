<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/BrowserPrintAuditService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_phase4_print_audit_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->apply($conn);

    $service = new BrowserPrintAuditService();
    $printed = $service->recordRenderedPrint($conn, 'receipt', 100, [
        'document_type' => 'receipt',
        'order' => ['id' => 100],
        'lines' => [['name' => 'Latte', 'qty' => '1.000']],
    ], 9, [
        'source' => 'test_receipt',
        'reprint_reason' => 'customer copy',
    ]);

    phase4BrowserPrintAuditAssert(is_array($printed), 'printed audit result expected');
    phase4BrowserPrintAuditAssert($printed['job_type'] === 'receipt', 'receipt job type expected');
    phase4BrowserPrintAuditAssert($printed['status'] === 'printed', 'browser render should mark job printed');
    phase4BrowserPrintAuditAssert($printed['attempts'] === 1, 'printed attempts should increment');
    phase4BrowserPrintAuditAssert($printed['created_by'] === 9, 'created_by expected');
    phase4BrowserPrintAuditAssert($printed['payload']['browser_print_audit']['source'] === 'test_receipt', 'audit source expected');
    phase4BrowserPrintAuditAssert($printed['payload']['browser_print_audit']['reprint_reason'] === 'customer copy', 'reprint reason expected');

    $kot = $service->recordRenderedPrint($conn, 'kot', 101, [
        'document_type' => 'kot',
        'order' => ['id' => 101],
        'lines' => [['name' => 'Tea', 'notes' => [['note_text' => 'بدون سكر']]]],
    ]);
    phase4BrowserPrintAuditAssert($kot['job_type'] === 'kot', 'kot job type expected');

    $conn->query('DROP TABLE print_jobs');
    $missing = (new BrowserPrintAuditService())->recordRenderedPrint($conn, 'receipt', 100, ['ok' => true]);
    phase4BrowserPrintAuditAssert($missing === null, 'missing print_jobs should skip logging');

    echo "phase4-browser-print-audit-service-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function phase4BrowserPrintAuditAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
