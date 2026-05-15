<?php

require_once __DIR__ . '/../../classes/Pos/Service/InventoryMovementService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$legacySource = file_get_contents(__DIR__ . '/../../do/doadd_invoice.php');
if ($legacySource === false) {
    throw new RuntimeException('Unable to read do/doadd_invoice.php');
}
phase4DecimalStockAssert(strpos($legacySource, "intval(\$rowbl['itmqty'])") === false, 'legacy invoice path must not cast stock quantity to int');
phase4DecimalStockAssert(strpos($legacySource, "floatval(\$rowbl['itmqty'])") !== false, 'legacy invoice path should preserve decimal stock quantity');

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_phase4_decimal_stock_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    $conn->query("
        CREATE TABLE myitems (
            id INT NOT NULL PRIMARY KEY,
            cost_price DECIMAL(15,4) NOT NULL DEFAULT 0,
            itmqty DECIMAL(15,4) NOT NULL DEFAULT 0,
            price1 DECIMAL(15,4) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("INSERT INTO myitems (id, cost_price, itmqty, price1) VALUES (5, 10.0000, 1.7500, 15.0000)");

    $inventory = new InventoryMovementService();
    $posLine = $inventory->normalizeInvoiceLine($conn, InventoryMovementService::TYPE_POS, [
        'item_id' => 5,
        'qty' => 0.250,
        'price' => 15.000,
        'discount' => 0,
        'u_val' => 1,
        'store_id' => 3,
    ]);
    phase4DecimalStockAssert(abs($posLine['qty_out'] - 0.250) < 0.0001, 'POS decimal qty_out should be preserved');
    phase4DecimalStockAssert(abs($posLine['qty_in']) < 0.0001, 'POS decimal sale should not add stock');
    phase4DecimalStockAssert(abs($posLine['profit'] - 1.250) < 0.0001, 'POS decimal profit should preserve fractional qty');

    $purchaseLine = $inventory->normalizeInvoiceLine($conn, InventoryMovementService::TYPE_PURCHASE, [
        'item_id' => 5,
        'qty' => 0.250,
        'price' => 12.000,
        'discount' => 0,
        'u_val' => 1,
    ]);
    phase4DecimalStockAssert(abs($purchaseLine['qty_in'] - 0.250) < 0.0001, 'purchase decimal qty_in should be preserved');
    phase4DecimalStockAssert(abs($purchaseLine['cost_price'] - 10.250) < 0.0001, 'weighted cost should use decimal existing stock quantity');

    echo "phase4-decimal-stock-inventory-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function phase4DecimalStockAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
