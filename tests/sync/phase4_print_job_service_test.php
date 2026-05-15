<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/PrintJobService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_phase4_print_jobs_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->apply($conn);

    $service = new PrintJobService();
    $receiptPrinter = $service->savePrinter($conn, [
        'name' => 'Receipt Browser',
        'printer_type' => 'receipt',
        'connection_type' => 'browser',
        'config' => ['paper' => '80mm'],
        'tenant' => 7,
        'branch' => 3,
    ]);
    phase4PrintAssert($receiptPrinter['printer_type'] === 'receipt', 'receipt printer type expected');
    phase4PrintAssert($receiptPrinter['config']['paper'] === '80mm', 'printer config should round trip');

    $kitchenPrinter = $service->savePrinter($conn, [
        'name' => 'Kitchen Browser',
        'printer_type' => 'kitchen',
        'connection_type' => 'browser',
        'tenant' => 7,
        'branch' => 3,
    ]);
    $inactive = $service->savePrinter($conn, [
        'name' => 'Inactive',
        'printer_type' => 'receipt',
        'connection_type' => 'browser',
        'tenant' => 7,
        'branch' => 3,
        'is_active' => false,
    ]);

    $activeReceiptPrinters = $service->listActivePrinters($conn, [
        'tenant' => 7,
        'branch' => 3,
        'printer_type' => 'receipt',
    ]);
    phase4PrintAssert(count($activeReceiptPrinters) === 1, 'inactive receipt printer should be excluded');
    phase4PrintAssert($activeReceiptPrinters[0]['id'] === $receiptPrinter['id'], 'receipt printer lookup expected');

    $updated = $service->savePrinter($conn, [
        'id' => $receiptPrinter['id'],
        'name' => 'Receipt Browser Updated',
        'printer_type' => 'receipt',
        'connection_type' => 'browser',
        'config' => ['paper' => '58mm'],
        'tenant' => 7,
        'branch' => 3,
    ]);
    phase4PrintAssert($updated['name'] === 'Receipt Browser Updated', 'printer update expected');
    phase4PrintAssert($updated['config']['paper'] === '58mm', 'printer config update expected');

    $receiptJob = $service->enqueue($conn, [
        'job_type' => 'receipt',
        'order_id' => 100,
        'printer_id' => $receiptPrinter['id'],
        'payload' => [
            'order_no' => 'T-100',
            'lines' => [['name' => 'Latte', 'qty' => 2]],
        ],
        'created_by' => 9,
    ]);
    phase4PrintAssert($receiptJob['status'] === 'queued', 'receipt job should queue');
    phase4PrintAssert($receiptJob['payload']['order_no'] === 'T-100', 'receipt payload should round trip');

    $kotJob = $service->enqueue($conn, [
        'job_type' => 'kot',
        'order_id' => 101,
        'printer_id' => $kitchenPrinter['id'],
        'payload_json' => json_encode([
            'table' => 'A1',
            'notes' => ['بدون ملح'],
        ], JSON_UNESCAPED_UNICODE),
    ]);
    phase4PrintAssert($kotJob['job_type'] === 'kot', 'kot job expected');
    phase4PrintAssert($kotJob['payload']['notes'][0] === 'بدون ملح', 'kot payload should preserve unicode');

    phase4PrintAssert(count($service->queuedJobs($conn, 10)) === 2, 'two queued jobs expected');

    $printed = $service->markPrinted($conn, $receiptJob['id']);
    phase4PrintAssert($printed['status'] === 'printed', 'printed status expected');
    phase4PrintAssert($printed['attempts'] === 1, 'printed attempts should increment');
    phase4PrintAssert($printed['printed_at'] !== null, 'printed_at should be set');

    phase4PrintExpectException(function () use ($service, $conn, $receiptJob) {
        $service->markFailed($conn, $receiptJob['id'], 'already printed');
    }, 'PRINT_JOB_NOT_QUEUED');

    $failed = $service->markFailed($conn, $kotJob['id'], 'printer offline');
    phase4PrintAssert($failed['status'] === 'failed', 'failed status expected');
    phase4PrintAssert($failed['last_error'] === 'printer offline', 'failure error expected');

    $reprint = $service->cloneForReprint($conn, $receiptJob['id'], 'customer copy', 10);
    phase4PrintAssert($reprint['status'] === 'queued', 'reprint should queue new job');
    phase4PrintAssert($reprint['payload']['reprint']['source_job_id'] === $receiptJob['id'], 'reprint should include source id');
    phase4PrintAssert($reprint['payload']['reprint']['reason'] === 'customer copy', 'reprint reason expected');

    $cancelled = $service->cancel($conn, $reprint['id'], 'operator cancelled');
    phase4PrintAssert($cancelled['status'] === 'cancelled', 'cancelled status expected');
    phase4PrintAssert($cancelled['last_error'] === 'operator cancelled', 'cancel reason expected');
    phase4PrintAssert(count($service->queuedJobs($conn, 10)) === 0, 'no queued jobs should remain');

    phase4PrintExpectException(function () use ($service, $conn, $inactive) {
        $service->enqueue($conn, [
            'job_type' => 'receipt',
            'printer_id' => $inactive['id'],
            'payload' => ['ok' => true],
        ]);
    }, 'PRINTER_NOT_FOUND');

    phase4PrintExpectException(function () use ($service, $conn) {
        $service->enqueue($conn, [
            'job_type' => 'invoice',
            'payload' => ['ok' => true],
        ]);
    }, 'PRINT_JOB_TYPE_INVALID');

    phase4PrintExpectException(function () use ($service, $conn) {
        $service->enqueue($conn, [
            'job_type' => 'receipt',
            'payload' => [],
        ]);
    }, 'PRINT_JOB_PAYLOAD_INVALID');

    phase4PrintExpectException(function () use ($service, $conn) {
        $service->savePrinter($conn, [
            'name' => 'Bad',
            'printer_type' => 'laser',
        ]);
    }, 'PRINTER_TYPE_INVALID');

    echo "phase4-print-job-service-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function phase4PrintExpectException(callable $fn, string $message): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        phase4PrintAssert($e->getMessage() === $message, "expected {$message}, got {$e->getMessage()}");
        return;
    }

    throw new RuntimeException("expected exception {$message}");
}

function phase4PrintAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
