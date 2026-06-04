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

    $affected = $service->saveVariantsFromPost($conn, 10, [
        'variant_link_id' => [$variants[0]['relation_id']],
        'variant_item_id' => [$smallId],
        'variant_label' => ['Medium'],
        'variant_name' => ['Chicken Sandwich - Small'],
        'variant_barcode' => ['CS-S2'],
        'variant_cost_price' => ['21.000'],
        'variant_price1' => ['47.000'],
        'variant_price2' => ['46.000'],
        'variant_market_price' => ['49.000'],
        'variant_active' => [1],
        'variant_default' => [1],
        'variant_sort' => [1],
    ], ['user_id' => 7]);
    itemVariantAssert(in_array($smallId, $affected, true), 'label rename should still update stale auto child name');
    $renamedVariants = $service->variantsForParent($conn, 10, true);
    itemVariantAssert($renamedVariants[0]['iname'] === 'Chicken Sandwich - Medium', 'child name should follow renamed variant label on save');
    itemVariantAssert($renamedVariants[0]['variant_label'] === 'Medium', 'variant label should persist');

    $conn->query("UPDATE item_variants SET variant_label = 'small' WHERE variant_item_id = {$smallId}");
    $conn->query("UPDATE myitems SET iname = 'Chicken Sandwich - df' WHERE id = {$smallId}");
    $affected = $service->saveVariantsFromPost($conn, 10, [
        'variant_link_id' => [$renamedVariants[0]['relation_id']],
        'variant_item_id' => [$smallId],
        'variant_label' => ['large'],
        'variant_name' => ['Chicken Sandwich - df'],
        'variant_barcode' => ['CS-S2'],
        'variant_cost_price' => ['21.000'],
        'variant_price1' => ['47.000'],
        'variant_price2' => ['46.000'],
        'variant_market_price' => ['49.000'],
        'variant_active' => [1],
        'variant_default' => [1],
        'variant_sort' => [1],
    ], ['user_id' => 7]);
    itemVariantAssert(in_array($smallId, $affected, true), 'stale child name should still update when only label changes');
    $staleVariants = $service->variantsForParent($conn, 10, true);
    itemVariantAssert($staleVariants[0]['iname'] === 'Chicken Sandwich - large', 'stale auto child name should follow renamed label');
    itemVariantAssert($staleVariants[0]['variant_label'] === 'large', 'renamed label should persist for stale child rows');

    $conn->query("INSERT INTO myitems (id, iname, barcode, user) VALUES (99, 'Chicken Sandwich - Small', 'CS-ORPHAN', 7)");
    $repaired = $service->repairUnlinkedChildrenForParent($conn, 10);
    itemVariantAssert(in_array(99, $repaired, true), 'repair should link unlinked small child');
    $editVariants = $service->variantsForEdit($conn, 10);
    $foundLinkedSmall = false;
    foreach ($editVariants as $variant) {
        if ((int) ($variant['variant_item_id'] ?? 0) === 99) {
            $foundLinkedSmall = true;
            itemVariantAssert((int) ($variant['relation_id'] ?? 0) > 0, 'repaired child should have a relation id');
            itemVariantAssert(empty($variant['is_unlinked_recovery']), 'repaired child should not stay flagged as unlinked');
        }
    }
    itemVariantAssert($foundLinkedSmall, 'repaired sibling should appear on edit screen');

    $activeChildId = (int) $staleVariants[0]['variant_item_id'];
    $affected = $service->saveVariantsFromPost($conn, 10, [
        'variant_link_id' => [(int) $staleVariants[0]['relation_id']],
        'variant_item_id' => [$activeChildId],
        'variant_label' => ['Small'],
        'variant_name' => ['Chicken Sandwich - large'],
        'variant_barcode' => ['CS-ORPHAN'],
        'variant_cost_price' => ['21.000'],
        'variant_price1' => ['47.000'],
        'variant_price2' => ['46.000'],
        'variant_market_price' => ['49.000'],
        'variant_active' => [1],
        'variant_default' => [1],
        'variant_sort' => [1],
    ], ['user_id' => 7]);
    itemVariantAssert(in_array(99, $affected, true), 'save should adopt the unlinked small child item');
    $linkedSmall = $service->variantsForParent($conn, 10, true);
    itemVariantAssert(count($linkedSmall) === 1, 'one active variant expected after adopting unlinked child');
    itemVariantAssert((int) $linkedSmall[0]['variant_item_id'] === 99, 'active variant should point to adopted child');
    itemVariantAssert($linkedSmall[0]['variant_label'] === 'Small', 'adopted variant label should persist');

    $conn->query("INSERT INTO myitems (id, iname, barcode, user) VALUES (98, 'Manual Conflict Name', 'CS-OTHER', 7)");
    $duplicateFailed = false;
    try {
        $service->saveVariantsFromPost($conn, 10, [
            'variant_link_id' => [(int) $linkedSmall[0]['relation_id']],
            'variant_item_id' => [(int) $linkedSmall[0]['variant_item_id']],
            'variant_label' => ['Small'],
            'variant_name' => ['Manual Conflict Name'],
            'variant_barcode' => ['CS-S2'],
            'variant_cost_price' => ['21.000'],
            'variant_price1' => ['47.000'],
            'variant_price2' => ['46.000'],
            'variant_market_price' => ['49.000'],
            'variant_active' => [1],
            'variant_default' => [1],
            'variant_sort' => [1],
        ], ['user_id' => 7]);
    } catch (InvalidArgumentException $exception) {
        $duplicateFailed = $exception->getMessage() === 'duplicate_item_name';
    }
    itemVariantAssert($duplicateFailed, 'manual child name conflicts should fail clearly');

    itemVariantAssert($service->hasActiveVariants($conn, 10) === true, 'parent should be chooser while active child exists');

    $deletedIds = $service->softDeleteParentAndVariantFamily($conn, 10);
    itemVariantAssert(in_array(10, $deletedIds, true), 'parent should be soft deleted');
    itemVariantAssert(in_array(99, $deletedIds, true), 'linked small child should be soft deleted with parent');
    $parentRow = $conn->query('SELECT isdeleted FROM myitems WHERE id = 10')->fetch_assoc();
    $childRow = $conn->query('SELECT isdeleted FROM myitems WHERE id = 99')->fetch_assoc();
    itemVariantAssert((int) ($parentRow['isdeleted'] ?? 0) === 1, 'parent row should be marked deleted');
    itemVariantAssert((int) ($childRow['isdeleted'] ?? 0) === 1, 'child row should be marked deleted');

    $childParent = $service->variantParentForChild($conn, 99);
    itemVariantAssert($childParent !== null && (int) $childParent['parent_item_id'] === 10, 'child should know parent relation');
    itemVariantAssert($service->hasActiveVariants($conn, 10) === false, 'deleted parent should no longer expose active variants');

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
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY uq_myitems_iname (iname),
            UNIQUE KEY uq_myitems_barcode (barcode)
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
