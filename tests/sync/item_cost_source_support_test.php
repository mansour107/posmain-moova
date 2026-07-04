<?php

require_once __DIR__ . '/../../classes/Items/ItemCostSourceSupport.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_item_cost_source_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    $conn->query('CREATE TABLE myitems (
        id INT AUTO_INCREMENT PRIMARY KEY,
        iname VARCHAR(120) NOT NULL,
        cost_price DECIMAL(18,6) NOT NULL DEFAULT 0,
        isdeleted TINYINT(1) NOT NULL DEFAULT 0
    )');
    $conn->query("INSERT INTO myitems (iname, cost_price) VALUES ('Tea', 12.5)");
    $itemId = (int) $conn->insert_id;

    ItemCostSourceSupport::ensureColumn($conn);
    itemCostSourceAssert(ItemCostSourceSupport::readForItem($conn, $itemId) === 'direct', 'new column should default to direct');

    ItemCostSourceSupport::saveForItem($conn, $itemId, 'purchase');
    itemCostSourceAssert(ItemCostSourceSupport::readForItem($conn, $itemId) === 'purchase', 'purchase source should persist');

    ItemCostSourceSupport::saveForItem($conn, $itemId, 'recipe');
    itemCostSourceAssert(ItemCostSourceSupport::readForItem($conn, $itemId) === 'recipe', 'recipe source should persist');

    ItemCostSourceSupport::saveForItem($conn, $itemId, 'invalid');
    itemCostSourceAssert(ItemCostSourceSupport::readForItem($conn, $itemId) === 'direct', 'invalid source should normalize to direct');

    echo "item-cost-source-support-ok\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function itemCostSourceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
