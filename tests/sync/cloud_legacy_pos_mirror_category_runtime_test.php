<?php

require_once __DIR__ . '/../../classes/Sync/CloudLegacyPosMirrorService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_cloud_mirror_category_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    cloudLegacyPosMirrorCategoryRuntimeCreateSchema($conn);

    $service = new CloudLegacyPosMirrorService();
    $service->mirrorFromBranchEvent($conn, 'branch-1', [
        'aggregate_type' => 'menu_item',
        'event_type' => 'menu.item_saved',
        'payload' => [
            'menu_item' => [
                'local_item_id' => 501,
                'item_name' => 'QA Ingredient',
                'category_id' => 9103,
                'isdeleted' => 0,
            ],
        ],
    ]);

    $categoryCount = (int) $conn->query('SELECT COUNT(*) AS c FROM item_group')->fetch_assoc()['c'];
    cloudLegacyPosMirrorCategoryRuntimeAssert($categoryCount === 0, 'missing source category name must not create item_group rows');

    $itemGroup = (int) $conn->query('SELECT group1 FROM myitems WHERE id = 501')->fetch_assoc()['group1'];
    cloudLegacyPosMirrorCategoryRuntimeAssert($itemGroup === 9103, 'item should still mirror its source category id');

    $service->mirrorFromBranchEvent($conn, 'branch-1', [
        'aggregate_type' => 'menu_item',
        'event_type' => 'menu.item_saved',
        'payload' => [
            'menu_item' => [
                'local_item_id' => 502,
                'item_name' => 'House Coffee',
                'category_id' => 17,
                'category_name' => 'coffee',
                'isdeleted' => 0,
            ],
        ],
    ]);

    $namedCategory = $conn->query('SELECT gname FROM item_group WHERE id = 17')->fetch_assoc();
    cloudLegacyPosMirrorCategoryRuntimeAssert(
        is_array($namedCategory) && $namedCategory['gname'] === 'coffee',
        'source category name should mirror into item_group'
    );

    echo "cloud-legacy-pos-mirror-category-runtime-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function cloudLegacyPosMirrorCategoryRuntimeCreateSchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE item_group (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            gname VARCHAR(255) NOT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE myitems (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            iname VARCHAR(255) NULL,
            name2 VARCHAR(255) NULL,
            barcode VARCHAR(80) NULL,
            info VARCHAR(255) NULL,
            cost_price DECIMAL(15,3) NOT NULL DEFAULT 0,
            price1 DECIMAL(15,3) NOT NULL DEFAULT 0,
            price2 DECIMAL(15,3) NOT NULL DEFAULT 0,
            price3 DECIMAL(15,3) NOT NULL DEFAULT 0,
            group1 BIGINT UNSIGNED NOT NULL DEFAULT 0,
            group2 BIGINT UNSIGNED NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            user BIGINT UNSIGNED NOT NULL DEFAULT 1,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            item_type VARCHAR(40) NOT NULL DEFAULT 'sellable',
            track_stock TINYINT(1) NOT NULL DEFAULT 1,
            manual_price_edit TINYINT(1) NOT NULL DEFAULT 0,
            mdtime DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function cloudLegacyPosMirrorCategoryRuntimeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
