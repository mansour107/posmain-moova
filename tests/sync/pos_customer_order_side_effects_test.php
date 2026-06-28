<?php

require_once __DIR__ . '/../../classes/Pos/Service/PosCustomerService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PosCustomerOrderSideEffects.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_crm_side_effects_' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    echo "pos-customer-order-side-effects-skipped-db-unavailable\n";
    exit(0);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->apply($conn);

    $conn->query("
        CREATE TABLE IF NOT EXISTS ot_head (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            pro_tybe INT NULL,
            order_type VARCHAR(40) NULL,
            fat_net DECIMAL(15,4) NOT NULL DEFAULT 0,
            paid_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            payment_status VARCHAR(40) NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            pro_date DATE NULL,
            crtime DATETIME NULL,
            mdtime DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $customerService = new PosCustomerService();
    $sideEffects = new PosCustomerOrderSideEffects();

    $saved = $customerService->saveCustomer($conn, [
        'display_name' => 'Rollup Test',
        'phones' => [['phone' => '01009998877', 'is_primary' => true]],
    ]);
    $customerId = (int) $saved['id'];
    posCrmSideEffectsAssert($customerId > 0, 'customer should be created');

    $conn->query("
        INSERT INTO ot_head (id, pro_tybe, order_type, fat_net, paid_amount, payment_status, isdeleted)
        VALUES (5001, 9, 'table', 100, 0, 'unpaid', 0)
    ");

    $rollup1 = $sideEffects->afterOrderSaved($conn, 5001, ['pos_customer_id' => $customerId], 'table', [
        'paid_amount' => 0,
        'payment_status' => 'unpaid',
    ]);
    posCrmSideEffectsAssert($rollup1['link']['linked'] === true, 'order should link to customer');

    $fulfillment = $conn->query('SELECT pos_customer_id FROM order_fulfillment WHERE order_id = 5001')->fetch_assoc();
    posCrmSideEffectsAssert((int) ($fulfillment['pos_customer_id'] ?? 0) === $customerId, 'fulfillment should store customer id');

    $conn->query("UPDATE ot_head SET paid_amount = 100, payment_status = 'paid' WHERE id = 5001");
    $rollup2 = $sideEffects->applyPaymentRollup($conn, 5001);
    posCrmSideEffectsAssert($rollup2['applied'] === true, 'first paid rollup should apply');
    posCrmSideEffectsAssert(abs($rollup2['paid_delta'] - 100.0) < 0.001, 'paid delta should be 100');

    $profile = $customerService->getProfile($conn, $customerId, true);
    posCrmSideEffectsAssert((int) ($profile['orders_count'] ?? 0) === 1, 'orders_count should be 1 after first paid');
    posCrmSideEffectsAssert(abs((float) ($profile['lifetime_paid'] ?? 0) - 100.0) < 0.001, 'lifetime_paid should be 100');

    $rollup3 = $sideEffects->applyPaymentRollup($conn, 5001);
    posCrmSideEffectsAssert($rollup3['applied'] === false, 'second rollup should be idempotent');

    $profileAfter = $customerService->getProfile($conn, $customerId, true);
    posCrmSideEffectsAssert((int) ($profileAfter['orders_count'] ?? 0) === 1, 'orders_count should stay 1 after duplicate rollup');
    posCrmSideEffectsAssert(abs((float) ($profileAfter['lifetime_paid'] ?? 0) - 100.0) < 0.001, 'lifetime_paid should stay 100');

    $rebuilt = $sideEffects->rebuildCustomerRollups($conn, $customerId);
    posCrmSideEffectsAssert((int) ($rebuilt['orders_count'] ?? 0) === 1, 'rebuild should preserve orders_count');
    posCrmSideEffectsAssert(abs((float) ($rebuilt['lifetime_paid'] ?? 0) - 100.0) < 0.001, 'rebuild should preserve lifetime_paid');

    echo "pos-customer-order-side-effects-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function posCrmSideEffectsAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
