<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/ItemAvailabilityService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_phase4_item_availability_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->apply($conn);
    $conn->query("
        CREATE TABLE myitems (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            iname VARCHAR(200) NOT NULL,
            item_type VARCHAR(32) NOT NULL DEFAULT 'sellable',
            track_stock TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB
    ");

    $service = new ItemAvailabilityService();
    $scope = ['tenant' => 7, 'branch' => 3, 'channel' => 'pos'];

    $default = $service->availabilityForItem($conn, 10, $scope);
    phase4AvailabilityAssert($default['is_available'] === true, 'items should default to available');
    phase4AvailabilityAssert($default['channel'] === 'pos', 'default channel should reflect requested channel');

    $disabled = $service->setAvailability($conn, 10, false, $scope, 'خلصت الكمية', 99);
    phase4AvailabilityAssert($disabled['is_available'] === false, 'specific channel should be unavailable after upsert');
    phase4AvailabilityAssert($disabled['unavailable_reason'] === 'خلصت الكمية', 'unavailable reason should persist');
    phase4AvailabilityAssert($disabled['updated_by'] === 99, 'updated_by should persist');

    $thrown = false;
    try {
        $service->assertSellable($conn, 10, $scope);
    } catch (RuntimeException $e) {
        $thrown = $e->getMessage() === 'ITEM_UNAVAILABLE';
    }
    phase4AvailabilityAssert($thrown, 'assertSellable should reject unavailable item');

    $service->setAvailability($conn, 11, false, ['tenant' => 7, 'branch' => 3, 'channel' => 'all'], 'غير متاح للفرع', 100);
    $fallback = $service->availabilityForItem($conn, 11, $scope);
    phase4AvailabilityAssert($fallback['is_available'] === false, 'specific channel should fall back to all-channel rule');
    phase4AvailabilityAssert($fallback['channel'] === 'all', 'fallback channel should expose source rule');

    $service->setAvailability($conn, 11, true, $scope, null, 101);
    $override = $service->availabilityForItem($conn, 11, $scope);
    phase4AvailabilityAssert($override['is_available'] === true, 'specific channel should override all-channel rule');
    phase4AvailabilityAssert($override['channel'] === 'pos', 'specific channel should win over all-channel rule');

    $decorated = $service->decorateItems($conn, [
        ['id' => 10, 'iname' => 'Espresso'],
        ['id' => 11, 'iname' => 'Latte'],
        ['id' => 12, 'iname' => 'Tea'],
    ], $scope);
    phase4AvailabilityAssert(count($decorated) === 3, 'decorated item count should be preserved');
    phase4AvailabilityAssert((int) $decorated[0]['is_available'] === 0, 'decorated unavailable item expected');
    phase4AvailabilityAssert($decorated[0]['unavailable_reason'] === 'خلصت الكمية', 'decorated reason expected');
    phase4AvailabilityAssert((int) $decorated[1]['is_available'] === 1, 'decorated override item expected');
    phase4AvailabilityAssert((int) $decorated[2]['is_available'] === 1, 'decorated default item expected');

    $same = $conn->query("SELECT COUNT(*) AS c FROM item_availability WHERE item_id = 10 AND tenant = 7 AND branch = 3 AND channel = 'pos'")->fetch_assoc();
    phase4AvailabilityAssert((int) $same['c'] === 1, 'upsert should keep one scoped availability row');

    $conn->query("
        INSERT INTO myitems (id, iname, item_type, track_stock) VALUES
            (20, 'Positive low stock', 'sellable', 1),
            (21, 'Zero stock', 'sellable', 1),
            (22, 'Negative stock', 'sellable', 1),
            (23, 'Missing balance', 'sellable', 1),
            (24, 'Non stock item', 'service', 0),
            (25, 'Inventory disabled item', 'sellable', 1)
    ");
    $conn->query("
        INSERT INTO inventory_item_balances
            (pos_tenant, pos_branch, store_id, item_id, qty_on_hand, qty_reserved, qty_available, moving_average_cost)
        VALUES
            (7, 3, 9, 20, '3.000000', '0.000000', '3.000000', '1.000000'),
            (7, 3, 9, 21, '0.000000', '0.000000', '0.000000', '1.000000'),
            (7, 3, 9, 22, '-2.000000', '0.000000', '-2.000000', '1.000000')
    ");
    $quantityConfig = [
        'recipe' => ['enabled' => false, 'mode' => 'off'],
        'inventory' => [
            'ledger_mode' => 'off',
            'quantity_tracking' => true,
            'availability' => true,
        ],
    ];
    $quantityService = new ItemAvailabilityService(
        new RecipeFeatureFlags($quantityConfig),
        null,
        null,
        new NegativeStockSalePolicyService($quantityConfig),
        new InventoryFeatureFlags($quantityConfig)
    );
    $stockScope = ['tenant' => 7, 'branch' => 3, 'channel' => 'pos', 'store_id' => 9];
    $positiveLow = $quantityService->availabilityForItem($conn, 20, $stockScope);
    phase4AvailabilityAssert($positiveLow['availability_status'] === 'inventory_low', 'positive low direct stock should reuse the low-stock status');
    phase4AvailabilityAssert($positiveLow['inventory_cashier_qty_available'] === '3.000000', 'positive low direct stock should preserve its positive cashier quantity');
    phase4AvailabilityAssert($positiveLow['availability_can_add'] === true, 'positive low stock must remain sellable');

    $zero = $quantityService->assertSellable($conn, 21, $stockScope);
    phase4AvailabilityAssert($zero['is_available'] === true && $zero['availability_can_add'] === true, 'zero stock must remain sellable');
    phase4AvailabilityAssert($zero['inventory_cashier_qty_available'] === '0', 'zero stock must display exactly zero');
    phase4AvailabilityAssert($zero['availability_status'] === 'inventory_shortage', 'zero stock should be a warning status');

    $negative = $quantityService->assertSellable($conn, 22, $stockScope);
    phase4AvailabilityAssert($negative['inventory_qty_available'] === '-2.000000', 'cashier decoration must not mutate the raw negative balance');
    phase4AvailabilityAssert($negative['inventory_cashier_qty_available'] === '0', 'negative stock must be clamped to zero for cashier presentation');
    phase4AvailabilityAssert($negative['is_available'] === true && $negative['availability_can_add'] === true, 'negative stock must remain sellable');

    $missing = $quantityService->assertSellable($conn, 23, $stockScope);
    phase4AvailabilityAssert($missing['inventory_balance_found'] === false, 'missing stock row should remain distinguishable from a stored zero');
    phase4AvailabilityAssert($missing['inventory_cashier_qty_available'] === '0', 'missing tracked balance should display exactly zero');

    $nonStock = $quantityService->availabilityForItem($conn, 24, $stockScope);
    phase4AvailabilityAssert(empty($nonStock['inventory_stock_tracked']), 'non-stock products must not receive stock warnings');

    $disabledConfig = [
        'recipe' => ['enabled' => false, 'mode' => 'off'],
        'inventory' => [
            'ledger_mode' => 'off',
            'quantity_tracking' => false,
            'availability' => false,
        ],
    ];
    $disabledService = new ItemAvailabilityService(
        new RecipeFeatureFlags($disabledConfig),
        null,
        null,
        new NegativeStockSalePolicyService($disabledConfig),
        new InventoryFeatureFlags($disabledConfig)
    );
    $inventoryDisabled = $disabledService->availabilityForItem($conn, 25, $stockScope);
    phase4AvailabilityAssert(empty($inventoryDisabled['inventory_stock_tracked']), 'inventory-disabled shops must not receive stock warnings or requirements');
    phase4AvailabilityAssert($inventoryDisabled['availability_can_add'] === true, 'inventory-disabled shops must keep ordinary items sellable');

    echo "phase4-item-availability-service-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function phase4AvailabilityAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
