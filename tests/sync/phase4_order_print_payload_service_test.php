<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/OrderPrintPayloadService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_phase4_print_payload_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    phase4PrintPayloadCreateLegacyTables($conn);
    (new SyncSchemaManager())->apply($conn);
    phase4PrintPayloadSeed($conn);

    $service = new OrderPrintPayloadService();
    $receipt = $service->buildReceiptPayload($conn, 100);

    phase4PrintPayloadAssert($receipt['document_type'] === 'receipt', 'receipt payload type expected');
    phase4PrintPayloadAssert($receipt['order']['id'] === 100, 'order id expected');
    phase4PrintPayloadAssert($receipt['table']['name'] === 'T1', 'table name expected');
    phase4PrintPayloadAssert($receipt['customer']['name'] === 'Walk In', 'customer name expected');
    phase4PrintPayloadAssert($receipt['totals']['net'] === '26.50', 'net total expected');
    phase4PrintPayloadAssert(count($receipt['lines']) === 2, 'two active lines expected');
    phase4PrintPayloadAssert($receipt['lines'][0]['name'] === 'Latte', 'line item name expected');
    phase4PrintPayloadAssert($receipt['lines'][0]['qty'] === '2.000', 'line qty expected');
    phase4PrintPayloadAssert($receipt['lines'][0]['line_total'] === '20.00', 'line total expected');
    phase4PrintPayloadAssert(count($receipt['lines'][0]['modifiers']) === 1, 'line modifier expected');
    phase4PrintPayloadAssert($receipt['lines'][0]['modifiers'][0]['name_ar'] === 'لبن شوفان', 'modifier name expected');
    phase4PrintPayloadAssert($receipt['lines'][0]['modifiers'][0]['line_delta'] === '3.000', 'modifier delta expected');
    phase4PrintPayloadAssert(count($receipt['lines'][0]['notes']) === 1, 'line note expected');
    phase4PrintPayloadAssert($receipt['lines'][0]['notes'][0]['note_text'] === 'بدون سكر', 'note text expected');

    $kot = $service->buildKotPayloadByTableId($conn, 1);
    phase4PrintPayloadAssert($kot['document_type'] === 'kot', 'kot payload type expected');
    phase4PrintPayloadAssert($kot['order']['id'] === 100, 'active table order expected');
    phase4PrintPayloadAssert($kot['lines'][1]['legacy_notes'] === 'ساخن', 'legacy notes should remain available');

    phase4PrintPayloadExpectException(function () use ($service, $conn) {
        $service->buildReceiptPayload($conn, 999);
    }, 'ORDER_NOT_FOUND');
    phase4PrintPayloadExpectException(function () use ($service, $conn) {
        $service->buildKotPayloadByTableId($conn, 2);
    }, 'ACTIVE_TABLE_ORDER_NOT_FOUND');

    echo "phase4-order-print-payload-service-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function phase4PrintPayloadCreateLegacyTables(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE tables (
            id INT NOT NULL PRIMARY KEY,
            tname VARCHAR(120) NULL,
            table_case INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE acc_head (
            id INT NOT NULL PRIMARY KEY,
            aname VARCHAR(120) NULL,
            info TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE ot_head (
            id INT NOT NULL PRIMARY KEY,
            pro_id VARCHAR(40) NULL,
            pro_date DATE NULL,
            pro_tybe INT NULL,
            table_id INT NULL,
            acc1 INT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            order_type VARCHAR(40) NULL,
            order_status VARCHAR(40) NULL,
            payment_status VARCHAR(40) NULL,
            fat_total DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_disc DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_plus DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_net DECIMAL(15,4) NOT NULL DEFAULT 0,
            paid_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            remaining_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            crtime DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE myitems (
            id INT NOT NULL PRIMARY KEY,
            iname VARCHAR(120) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE fat_details (
            id INT NOT NULL PRIMARY KEY,
            fatid INT NOT NULL,
            item_id INT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            qty_out DECIMAL(15,4) NOT NULL DEFAULT 0,
            qty_in DECIMAL(15,4) NOT NULL DEFAULT 0,
            price DECIMAL(15,4) NOT NULL DEFAULT 0,
            det_value DECIMAL(15,4) NOT NULL DEFAULT 0,
            notes TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function phase4PrintPayloadSeed(mysqli $conn): void
{
    $conn->query("INSERT INTO tables (id, tname, table_case, isdeleted) VALUES (1, 'T1', 1, 0), (2, 'T2', 0, 0)");
    $conn->query("INSERT INTO acc_head (id, aname, info) VALUES (10, 'Walk In', 'customer info')");
    $conn->query("
        INSERT INTO ot_head (
            id, pro_id, pro_date, pro_tybe, table_id, acc1, isdeleted,
            order_type, order_status, payment_status, fat_total, fat_disc,
            fat_plus, fat_net, paid_amount, remaining_amount, crtime
        ) VALUES (
            100, 'A100', '2026-05-13', 9, 1, 10, 0,
            'table', 'active', 'partial', 25, 1.50, 3, 26.50, 10, 16.50, '2026-05-13 10:00:00'
        )
    ");
    $conn->query("INSERT INTO myitems (id, iname) VALUES (501, 'Latte'), (502, 'Tea')");
    $conn->query("
        INSERT INTO fat_details (id, fatid, item_id, isdeleted, qty_out, qty_in, price, det_value, notes) VALUES
        (1001, 100, 501, 0, 2, 0, 10, 20, NULL),
        (1002, 100, 502, 0, 1, 0, 5, 5, 'ساخن'),
        (1003, 100, 501, 1, 1, 0, 10, 10, NULL)
    ");
    $conn->query("
        INSERT INTO modifier_groups (id, name_ar, selection_min, selection_max, is_required, is_active)
        VALUES (201, 'Milk', 0, 2, 0, 1)
    ");
    $conn->query("
        INSERT INTO modifier_options (id, group_id, name_ar, price_delta, is_active)
        VALUES (301, 201, 'لبن شوفان', 1.500, 1)
    ");
    $conn->query("
        INSERT INTO order_line_modifiers (
            order_id, detail_id, modifier_group_id, modifier_option_id, qty, price_delta
        ) VALUES (100, 1001, 201, 301, 2, 1.500)
    ");
    $conn->query("
        INSERT INTO order_line_notes (order_id, detail_id, note_type, note_text, created_by)
        VALUES (100, 1001, 'kitchen', 'بدون سكر', 9)
    ");
}

function phase4PrintPayloadExpectException(callable $fn, string $message): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        phase4PrintPayloadAssert($e->getMessage() === $message, "expected {$message}, got {$e->getMessage()}");
        return;
    }

    throw new RuntimeException("expected exception {$message}");
}

function phase4PrintPayloadAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
