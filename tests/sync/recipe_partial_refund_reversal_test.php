<?php

require_once __DIR__ . '/recipe_negative_stock_warn_test.php';

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_recipe_partial_refund_' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    fwrite(STDERR, "recipe-partial-refund-reversal-failed-db-unavailable\n");
    exit(1);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    recipeNegativeStockWarnCreateSchema($conn);

    $balances = new InventoryBalanceRepository();
    $movements = new InventoryMovementRepository();
    $balances->putBalance($conn, [
        'pos_tenant' => 0,
        'pos_branch' => 0,
        'store_id' => 1,
        'item_id' => 6002,
        'qty_on_hand' => '9.600000',
        'qty_reserved' => '0.000000',
        'qty_available' => '9.600000',
        'moving_average_cost' => '2.500000',
    ]);
    $originalId = $movements->createMovement($conn, [
        'movement_uuid' => '11111111-1111-4111-8111-111111111111',
        'pos_tenant' => 0,
        'pos_branch' => 0,
        'store_id' => 1,
        'item_id' => 6002,
        'movement_type' => 'recipe_consumption',
        'source_type' => 'recipe_order_line_usage',
        'source_id' => 71,
        'order_id' => 9001,
        'fat_detail_id' => 81,
        'recipe_order_line_usage_id' => 71,
        'recipe_id' => 7001,
        'qty_out' => '0.400000',
        'unit_cost' => '2.500000',
        'total_cost' => '1.000000',
        'idempotency_key' => 'consume:9001:81:6002',
    ]);
    $original = $movements->findByIds($conn, [$originalId]);
    $service = new RecipeInventoryMovementService();

    $conn->begin_transaction();
    $first = $service->recordRefundReversal($conn, $original, [
        'policy' => 'return_to_stock',
        'refund_uuid' => 'credit-note:101',
        'refund_order_quantity' => '1.000000',
        'original_order_quantity' => '2.000000',
        'is_final_quantity' => false,
        'created_by' => 7,
    ]);
    $conn->commit();
    recipePartialRefundAssert(count($first->movementIds) === 1, 'first partial refund must create one movement');

    $conn->begin_transaction();
    $firstReplay = $service->recordRefundReversal($conn, $original, [
        'policy' => 'return_to_stock',
        'refund_uuid' => 'credit-note:101',
        'refund_order_quantity' => '1.000000',
        'original_order_quantity' => '2.000000',
        'is_final_quantity' => false,
        'created_by' => 7,
    ]);
    $conn->commit();
    recipePartialRefundAssert($firstReplay->movementIds === $first->movementIds, 'same credit-note retry must replay its movement');

    $afterFirst = recipePartialRefundRows($conn);
    recipePartialRefundAssert((int) $afterFirst['movement_count'] === 1, 'retry must not duplicate reversal rows');
    recipePartialRefundAssert((string) $afterFirst['qty'] === '0.200000', 'half-order refund must restore half the ingredient consumption');
    recipePartialRefundAssert((string) $afterFirst['cost'] === '0.500000', 'half-order refund must reverse proportional COGS');

    $conn->begin_transaction();
    $second = $service->recordRefundReversal($conn, $original, [
        'policy' => 'return_to_stock',
        'refund_uuid' => 'credit-note:102',
        'refund_order_quantity' => '1.000000',
        'original_order_quantity' => '2.000000',
        'is_final_quantity' => true,
        'created_by' => 7,
    ]);
    $conn->commit();
    recipePartialRefundAssert(count($second->movementIds) === 1, 'second credit note must create its own bounded movement');

    $afterFull = recipePartialRefundRows($conn);
    recipePartialRefundAssert((int) $afterFull['movement_count'] === 2, 'two partial refunds must retain two audit identities');
    recipePartialRefundAssert((string) $afterFull['qty'] === '0.400000', 'cumulative refund must never restore more than original consumption');
    recipePartialRefundAssert((string) $afterFull['cost'] === '1.000000', 'cumulative COGS reversal must equal original COGS exactly');
    $balance = $balances->findBalance($conn, 0, 0, 1, 6002);
    recipePartialRefundAssert((string) ($balance['qty_on_hand'] ?? '') === '10.000000', 'final inventory balance must return exactly to pre-sale quantity');

    $conn->begin_transaction();
    $over = $service->recordRefundReversal($conn, $original, [
        'policy' => 'return_to_stock',
        'refund_uuid' => 'credit-note:103',
        'refund_order_quantity' => '1.000000',
        'original_order_quantity' => '2.000000',
        'is_final_quantity' => true,
        'created_by' => 7,
    ]);
    $conn->commit();
    recipePartialRefundAssert($over->movementIds === [], 'a later refund must not over-return an already fully reversed movement');
    recipePartialRefundAssert((int) recipePartialRefundRows($conn)['movement_count'] === 2, 'over-return attempt must leave durable movement count unchanged');

    $balances->putBalance($conn, [
        'pos_tenant' => 0,
        'pos_branch' => 0,
        'store_id' => 1,
        'item_id' => 6003,
        'qty_on_hand' => '9.600000',
        'qty_reserved' => '0.000000',
        'qty_available' => '9.600000',
        'moving_average_cost' => '2.500000',
    ]);
    $mixedOriginalId = $movements->createMovement($conn, [
        'movement_uuid' => '22222222-2222-4222-8222-222222222222',
        'pos_tenant' => 0,
        'pos_branch' => 0,
        'store_id' => 1,
        'item_id' => 6003,
        'movement_type' => 'recipe_consumption',
        'source_type' => 'recipe_order_line_usage',
        'source_id' => 72,
        'order_id' => 9002,
        'fat_detail_id' => 82,
        'recipe_order_line_usage_id' => 72,
        'recipe_id' => 7002,
        'qty_out' => '0.400000',
        'unit_cost' => '2.500000',
        'total_cost' => '1.000000',
        'idempotency_key' => 'consume:9002:82:6003',
    ]);
    $mixedOriginal = $movements->findByIds($conn, [$mixedOriginalId]);

    $conn->begin_transaction();
    $wastePartial = $service->recordRefundReversal($conn, $mixedOriginal, [
        'policy' => 'waste',
        'refund_uuid' => 'credit-note:201',
        'refund_order_quantity' => '1.000000',
        'original_order_quantity' => '2.000000',
        'created_by' => 7,
    ]);
    $conn->commit();
    recipePartialRefundAssert($wastePartial->movementIds === [], 'waste partial must not return ingredients or reverse COGS');

    $conn->begin_transaction();
    $restockAfterWaste = $service->recordRefundReversal($conn, $mixedOriginal, [
        'policy' => 'return_to_stock',
        'refund_uuid' => 'credit-note:202',
        'refund_order_quantity' => '1.000000',
        'original_order_quantity' => '2.000000',
        // A financial final marker must never sweep stock that an earlier
        // waste/no-return credit-note line did not authorize for restock.
        'is_final_quantity' => true,
        'created_by' => 7,
    ]);
    $conn->commit();
    recipePartialRefundAssert(count($restockAfterWaste->movementIds) === 1, 'restock partial after waste must create one bounded reversal');
    $mixedRows = $conn->query("
        SELECT CAST(COALESCE(SUM(qty_in), 0) AS DECIMAL(18,6)) AS qty,
               CAST(COALESCE(SUM(total_cost), 0) AS DECIMAL(18,6)) AS cost
        FROM inventory_movements
        WHERE reversed_movement_id = {$mixedOriginalId}
          AND movement_type = 'refund_reversal'
    ")->fetch_assoc();
    recipePartialRefundAssert((string) $mixedRows['qty'] === '0.200000', 'waste then final restock must restore only the restock-authorized half');
    recipePartialRefundAssert((string) $mixedRows['cost'] === '0.500000', 'waste then final restock must reverse only half of recipe COGS');
    $mixedBalance = $balances->findBalance($conn, 0, 0, 1, 6003);
    recipePartialRefundAssert((string) ($mixedBalance['qty_on_hand'] ?? '') === '9.800000', 'mixed disposition balance must not return to pre-sale stock');

    echo "recipe-partial-refund-reversal-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function recipePartialRefundRows(mysqli $conn): array
{
    return $conn->query("
        SELECT COUNT(*) AS movement_count,
               CAST(COALESCE(SUM(qty_in), 0) AS DECIMAL(18,6)) AS qty,
               CAST(COALESCE(SUM(total_cost), 0) AS DECIMAL(18,6)) AS cost
        FROM inventory_movements
        WHERE movement_type = 'refund_reversal'
          AND reversed_movement_id IS NOT NULL
    ")->fetch_assoc();
}

function recipePartialRefundAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
