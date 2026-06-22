<?php

require_once __DIR__ . '/../../classes/Pos/Service/DeliveryClientService.php';
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
    $conn->query("CREATE TABLE delivery_clients (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_name VARCHAR(255) NOT NULL,
        phone VARCHAR(50) NOT NULL UNIQUE,
        address TEXT NOT NULL,
        isdeleted TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $service = new DeliveryClientService();
    $first = $service->upsertByPhone($conn, '01001234567', 'Ahmed', 'Maadi');
    deliveryClientAssert($first['success'] === true, 'first upsert should succeed');
    deliveryClientAssert($first['client_id'] > 0, 'first upsert should return client id');

    $second = $service->upsertByPhone($conn, '01001234567', 'Ahmed Updated', 'Nasr City');
    deliveryClientAssert($second['client_id'] === $first['client_id'], 'duplicate phone should upsert same client id');

    $found = $service->findByPhone($conn, '01001234567');
    deliveryClientAssert($found['name'] === 'Ahmed Updated', 'upsert should update name');
    deliveryClientAssert($found['address'] === 'Nasr City', 'upsert should update address');

    $count = $conn->query('SELECT COUNT(*) AS c FROM delivery_clients')->fetch_assoc();
    deliveryClientAssert((int) $count['c'] === 1, 'upsert should not create duplicate rows');

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
