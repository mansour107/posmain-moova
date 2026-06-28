<?php

require_once __DIR__ . '/../../classes/Pos/Service/PosCustomerService.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_delivery_client_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->applyPosCustomerSchema($conn);

    $service = new PosCustomerService();
    $first = $service->upsertForDelivery($conn, '01001234567', 'Ahmed', 'Maadi');
    deliveryClientAssert((int) ($first['id'] ?? 0) > 0, 'first upsert should return customer id');

    $second = $service->upsertForDelivery($conn, '01001234567', 'Ahmed Updated', 'Nasr City');
    deliveryClientAssert((int) $second['id'] === (int) $first['id'], 'duplicate phone should upsert same customer id');

    $search = $service->searchByPhone($conn, '01001234567');
    deliveryClientAssert(!empty($search['exact']), 'search should find customer');
    deliveryClientAssert($search['exact']['display_name'] === 'Ahmed Updated', 'upsert should update name');

    $count = $conn->query('SELECT COUNT(*) AS c FROM pos_customers WHERE isdeleted = 0')->fetch_assoc();
    deliveryClientAssert((int) $count['c'] === 1, 'upsert should not create duplicate customers');

    echo "delivery_client_upsert_test: OK\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function deliveryClientAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "delivery_client_upsert_test FAILED: {$message}\n");
        exit(1);
    }
}
