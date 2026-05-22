<?php

require_once __DIR__ . '/../../classes/Pos/Service/ItemVariantService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_item_variants_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    itemVariantCreateFixtureSchema($conn);
    itemVariantSeedFixture($conn);

    $service = new ItemVariantService();
    $service->ensureSchema($conn);
    $affected = $service->saveVariantsFromPost($conn, 10, [
        'variant_label' => ['Small', 'Large'],
        'variant_name' => ['', ''],
        'variant_barcode' => ['CS-S', 'CS-L'],
        'variant_cost_price' => ['20.500', '28.000'],
        'variant_price1' => ['45.000', '62.000'],
        'variant_price2' => ['44.000', '60.000'],
        'variant_market_price' => ['48.000', '65.000'],
        'variant_active' => [1, 1],
        'variant_default' => [1, 0],
        'variant_sort' => [1, 2],
    ], ['user_id' => 7]);

    itemVariantAssert(in_array(10, $affected, true), 'parent should be marked changed');
    itemVariantAssert(count($affected) === 3, 'parent and two child items should be changed');
    $variants = $service->variantsForParent($conn, 10, true);
    itemVariantAssert(count($variants) === 2, 'two active variants expected');
    itemVariantAssert($variants[0]['iname'] === 'Chicken Sandwich - Small', 'generated child name expected');
    itemVariantAssert($variants[0]['barcode'] === 'CS-S', 'small barcode expected');
    itemVariantAssert((float) $variants[1]['price1'] === 62.0, 'large price expected');
    itemVariantAssert($variants[0]['is_default'] === true, 'small should be default');
    itemVariantAssert(itemVariantCount($conn, 'item_units') === 3, 'each child should get one default item_unit row');

    $smallId = (int) $variants[0]['variant_item_id'];
    $largeId = (int) $variants[1]['variant_item_id'];
    $affected = $service->saveVariantsFromPost($conn, 10, [
        'variant_link_id' => [$variants[0]['relation_id'], $variants[1]['relation_id']],
        'variant_item_id' => [$smallId, $largeId],
        'variant_label' => ['Small', 'Large'],
        'variant_name' => ['Small Chicken Sandwich', 'Large Chicken Sandwich'],
        'variant_barcode' => ['CS-S2', 'CS-L'],
        'variant_cost_price' => ['21.000', '28.000'],
        'variant_price1' => ['47.000', '62.000'],
        'variant_price2' => ['46.000', '60.000'],
        'variant_market_price' => ['49.000', '65.000'],
        'variant_active' => [1, 0],
        'variant_default' => [1, 0],
        'variant_sort' => [2, 1],
    ], ['user_id' => 7]);

    itemVariantAssert(in_array($smallId, $affected, true), 'updated child should be marked changed');
    itemVariantAssert(in_array($largeId, $affected, true), 'deactivated child should be marked changed');
    $activeVariants = $service->variantsForParent($conn, 10, true);
    itemVariantAssert(count($activeVariants) === 1, 'one active variant expected after deactivation');
    itemVariantAssert($activeVariants[0]['variant_item_id'] === $smallId, 'small should remain active');
    itemVariantAssert($activeVariants[0]['barcode'] === 'CS-S2', 'small barcode should update');
    itemVariantAssert((float) $activeVariants[0]['price1'] === 47.0, 'small price should update');
    $childParent = $service->variantParentForChild($conn, $smallId);
    itemVariantAssert($childParent !== null && (int) $childParent['parent_item_id'] === 10, 'child should know parent relation');
    itemVariantAssert($service->hasActiveVariants($conn, 10) === true, 'parent should be chooser while active child exists');

    echo "item-variant-service-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function itemVariantCreateFixtureSchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE myitems (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            iname VARCHAR(255) NULL,
            name2 VARCHAR(255) NULL,
            code VARCHAR(50) NULL,
            barcode VARCHAR(80) NULL,
            info VARCHAR(255) NULL,
            market_price DECIMAL(15,3) NOT NULL DEFAULT 0,
            cost_price DECIMAL(15,3) NOT NULL DEFAULT 0,
            price1 DECIMAL(15,3) NOT NULL DEFAULT 0,
            price2 DECIMAL(15,3) NOT NULL DEFAULT 0,
            price3 DECIMAL(15,3) NOT NULL DEFAULT 0,
            group1 BIGINT UNSIGNED NOT NULL DEFAULT 0,
            group2 BIGINT UNSIGNED NOT NULL DEFAULT 0,
            itmqty DECIMAL(15,3) NOT NULL DEFAULT 0,
            user BIGINT UNSIGNED NOT NULL DEFAULT 1,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE myunits (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            uname VARCHAR(100) NOT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE item_units (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            item_id BIGINT UNSIGNED NOT NULL,
            unit_id BIGINT UNSIGNED NOT NULL,
            u_val DECIMAL(12,3) NOT NULL DEFAULT 1.000,
            unit_barcode VARCHAR(80) NULL,
            cost_price DECIMAL(15,3) NOT NULL DEFAULT 0,
            price1 DECIMAL(15,3) NOT NULL DEFAULT 0,
            price2 DECIMAL(15,3) NOT NULL DEFAULT 0,
            price3 DECIMAL(15,3) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function itemVariantSeedFixture(mysqli $conn): void
{
    $conn->query("INSERT INTO myunits (id, uname) VALUES (1, 'Piece')");
    $conn->query("
        INSERT INTO myitems (
            id, iname, name2, code, barcode, info, market_price, cost_price, price1, price2, price3, group1, group2, user
        ) VALUES (
            10, 'Chicken Sandwich', '', '10', 'CS-P', 'Parent sandwich', 0, 0, 0, 0, 0, 3, 4, 7
        )
    ");
    $conn->query("
        INSERT INTO item_units (item_id, unit_id, u_val, unit_barcode, cost_price, price1, price2, price3)
        VALUES (10, 1, 1, 'CS-P', 0, 0, 0, 0)
    ");
}

function itemVariantCount(mysqli $conn, string $table): int
{
    return (int) $conn->query("SELECT COUNT(*) AS c FROM {$table}")->fetch_assoc()['c'];
}

function itemVariantAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
