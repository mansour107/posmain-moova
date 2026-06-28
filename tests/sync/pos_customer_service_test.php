<?php

require_once __DIR__ . '/../../classes/Pos/Service/PosCustomerService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PosCustomerMigrationService.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_customer_service_' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    echo "pos-customer-service-skipped-db-unavailable\n";
    exit(0);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->apply($conn);

    $service = new PosCustomerService();
    $migration = new PosCustomerMigrationService();

    $a = $service->saveCustomer($conn, [
        'display_name' => 'Customer A',
        'phones' => [['phone' => '01001111111', 'is_primary' => true]],
        'addresses' => [['address_text' => 'Addr A', 'is_default' => true]],
    ]);
    $b = $service->saveCustomer($conn, [
        'display_name' => 'Customer B',
        'phones' => [['phone' => '01002222222', 'is_primary' => true]],
    ]);

    $idA = (int) $a['id'];
    $idB = (int) $b['id'];
    posCustomerServiceAssert($idA > 0 && $idB > 0, 'customers should be created');

    $service->recordOrderPaid($conn, $idB, 50.0);
    $merged = $service->mergeCustomers($conn, $idB, $idA);
    posCustomerServiceAssert((int) $merged['id'] === $idA, 'merge should return target profile');
    posCustomerServiceAssert($service->getProfile($conn, $idB, false) === null, 'source should be soft-deleted');

    $phones = $service->searchByPhone($conn, '01002222222');
    posCustomerServiceAssert(!empty($phones['exact']) && (int) $phones['exact']['id'] === $idA, 'merged phone should resolve to target');

    $conn->query("INSERT INTO order_fulfillment (order_id, fulfillment_type, customer_phone, pos_customer_id) VALUES (9001, 'delivery', '01001111111', 0)");
    $backfill = $migration->backfillOrderFulfillmentCustomers($conn);
    posCustomerServiceAssert(($backfill['updated'] ?? 0) >= 1, 'backfill should link fulfillment rows');

    $row = $conn->query('SELECT pos_customer_id FROM order_fulfillment WHERE order_id = 9001')->fetch_assoc();
    posCustomerServiceAssert((int) ($row['pos_customer_id'] ?? 0) === $idA, 'backfill should set pos_customer_id on fulfillment');

    $service->softDeleteCustomer($conn, $idA);
    posCustomerServiceAssert($service->getProfile($conn, $idA, false) === null, 'soft delete should hide customer');

    echo "pos-customer-service-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function posCustomerServiceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
