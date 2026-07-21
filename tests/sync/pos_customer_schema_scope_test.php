<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../includes/pos_customer_bootstrap.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$database = 'posmain_customer_scope_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($database);
    $manager = new SyncSchemaManager();
    $planned = $manager->plannedStatements();
    foreach ($manager->posCustomerTableKeys() as $table) {
        $conn->query($planned[$table]);
    }

    posCustomerSchemaScopeAssert($manager->pendingPosCustomerStatements($conn) === [], 'customer schema should be ready');
    posCustomerSchemaScopeAssert($manager->pendingStatements($conn) !== [], 'unrelated application migrations should remain pending in this fixture');
    posmain_ensure_pos_customer_schema($conn);

    $conn->query('DROP TABLE pos_customer_addresses');
    $fresh = new mysqli($host, $user, $pass, $database, $port);
    $blocked = false;
    try {
        posmain_ensure_pos_customer_schema($fresh);
    } catch (Throwable $exception) {
        $blocked = $exception->getMessage() === 'POS_CUSTOMER_SCHEMA_MIGRATIONS_PENDING';
    } finally {
        $fresh->close();
    }
    posCustomerSchemaScopeAssert($blocked, 'a genuinely missing customer table should still block customer writes clearly');

    echo "pos_customer_schema_scope_test: OK\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$database}`");
    $conn->close();
}

function posCustomerSchemaScopeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
