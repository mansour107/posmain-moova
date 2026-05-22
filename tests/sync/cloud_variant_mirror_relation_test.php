<?php

require_once __DIR__ . '/../../classes/Sync/CloudLegacyPosMirrorService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_cloud_variant_mirror_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    cloudVariantMirrorCreateSchema($conn);

    $service = new CloudLegacyPosMirrorService();
    $service->mirrorFromBranchEvent($conn, 'branch-1', [
        'aggregate_type' => 'menu_item',
        'event_type' => 'menu.item_saved',
        'payload' => [
            'menu_item' => [
                'local_item_id' => 10,
                'item_name' => 'Strawberry Juice',
                'price1' => 0,
                'isdeleted' => 0,
                'variants' => [
                    [
                        'variant_item_id' => 11,
                        'label' => 'Small',
                        'name' => 'Strawberry Juice - Small',
                        'price1' => 60,
                        'sort_order' => 2,
                        'is_default' => true,
                        'is_active' => true,
                    ],
                    [
                        'variant_item_id' => 12,
                        'label' => 'Large',
                        'name' => 'Strawberry Juice - Large',
                        'price1' => 80,
                        'sort_order' => 1,
                        'is_default' => false,
                        'is_active' => true,
                    ],
                ],
            ],
        ],
    ]);

    $service->mirrorFromBranchEvent($conn, 'branch-1', [
        'aggregate_type' => 'menu_item',
        'event_type' => 'menu.item_saved',
        'payload' => [
            'menu_item' => [
                'local_item_id' => 11,
                'item_name' => 'Strawberry Juice - Small',
                'price1' => 60,
                'parent_item_id' => 10,
                'variant_label' => 'Small',
                'isdeleted' => 0,
            ],
        ],
    ]);

    $small = $conn->query('SELECT * FROM item_variants WHERE parent_item_id = 10 AND variant_item_id = 11')->fetch_assoc();
    cloudVariantMirrorAssert((int) $small['sort_order'] === 2, 'child event should not reset parent-defined sort_order');
    cloudVariantMirrorAssert((int) $small['is_default'] === 1, 'child event should not reset parent-defined default');
    cloudVariantMirrorAssert((int) $small['is_active'] === 1, 'child event should keep relation active');

    echo "cloud-variant-mirror-relation-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function cloudVariantMirrorCreateSchema(mysqli $conn): void
{
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

function cloudVariantMirrorAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
