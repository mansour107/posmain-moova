<?php

declare(strict_types=1);

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Inventory/InventoryFeatureFlags.php';
require_once __DIR__ . '/../../classes/Inventory/InventoryLedgerService.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeFeatureFlags.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeInventoryMovementService.php';
require_once __DIR__ . '/../../classes/Recipe/DTO/IngredientRequirement.php';
require_once __DIR__ . '/../../classes/Recipe/DTO/RecipeExplosionResult.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_quantity_only_' . getmypid() . '_' . bin2hex(random_bytes(4));
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->apply($conn);

    $config = quantityOnlyConfig();
    $flags = new InventoryFeatureFlags($config);
    $ledger = new InventoryLedgerService($flags);
    $item = ['item_id' => 4101, 'item_type' => 'ingredient', 'track_stock' => 1];

    $purchase = $ledger->recordMovement($conn, quantityOnlyMovement(
        'purchase',
        '10.000000',
        '0.000000',
        '2.000000',
        'quantity-only:purchase'
    ), $item);
    quantityOnlyAssert(empty($purchase['noop']), 'quantity-only purchase must write stock');

    $sale = $ledger->recordMovement($conn, quantityOnlyMovement(
        'sale_direct',
        '0.000000',
        '2.000000',
        '2.000000',
        'quantity-only:sale'
    ), $item);
    quantityOnlyAssert(empty($sale['noop']), 'quantity-only direct sale must write stock');

    $adjustment = $ledger->recordMovement($conn, quantityOnlyMovement(
        'adjustment',
        '1.000000',
        '0.000000',
        '2.000000',
        'quantity-only:adjustment'
    ), $item);
    quantityOnlyAssert(empty($adjustment['noop']), 'quantity-only adjustment must write stock');

    $recipeFlags = new RecipeFeatureFlags($config);
    $recipe = new RecipeInventoryMovementService($recipeFlags);
    $explosion = new RecipeExplosionResult([
        'sellable_item_id' => 4201,
        'recipe_id' => 4202,
        'recipe_version' => 1,
        'has_recipe' => true,
        'requirements' => [
            new IngredientRequirement([
                'ingredient_item_id' => 4101,
                'required_qty_base' => '3.000000',
                'unit_conversion_to_base' => '1.00000000',
                'unit_cost' => '2.000000',
                'total_cost' => '6.000000',
            ]),
        ],
    ]);
    $recipeResult = $recipe->recordRecipeConsumption($conn, $explosion, [
        'pos_tenant' => 1,
        'pos_branch' => 1,
        'branch_uuid' => '41414141-4141-4141-8141-414141414141',
        'store_id' => 1,
        'channel' => 'pos',
        'order_type' => 'takeaway',
        'order_id' => 4203,
        'fat_detail_id' => 4204,
        'order_line_uuid' => 'quantity-only-line-4204',
        'recipe_order_line_usage_id' => 4205,
        'created_by' => 7,
    ]);
    quantityOnlyAssert(count($recipeResult->movementIds) === 1, 'quantity-only recipe sale must write through the inventory ledger');

    $balance = $conn->query(
        'SELECT qty_on_hand, qty_available, moving_average_cost, last_movement_id'
        . ' FROM inventory_item_balances WHERE item_id = 4101 LIMIT 1'
    )->fetch_assoc();
    quantityOnlyAssert((string) ($balance['qty_on_hand'] ?? '') === '6.000000', 'quantity-only movement total must be exact');
    quantityOnlyAssert((string) ($balance['qty_available'] ?? '') === '6.000000', 'available quantity must follow exact on-hand quantity');
    quantityOnlyAssert((string) ($balance['moving_average_cost'] ?? '') === '2.000000', 'moving-average cost must remain exact');

    $movementCount = (int) $conn->query('SELECT COUNT(*) AS c FROM inventory_movements')->fetch_assoc()['c'];
    $outbox = $conn->query(
        "SELECT aggregate_type, COUNT(*) AS c FROM sync_outbox"
        . " WHERE aggregate_type IN ('inventory_movement', 'inventory_balance')"
        . ' GROUP BY aggregate_type ORDER BY aggregate_type'
    );
    $outboxCounts = [];
    while ($row = $outbox->fetch_assoc()) {
        $outboxCounts[(string) $row['aggregate_type']] = (int) $row['c'];
    }
    quantityOnlyAssert($movementCount === 4, 'purchase, sale, adjustment, and recipe consumption must each have one movement');
    quantityOnlyAssert(($outboxCounts['inventory_movement'] ?? 0) === 4, 'every quantity movement must have one outbox revision');
    quantityOnlyAssert(($outboxCounts['inventory_balance'] ?? 0) === 4, 'every balance revision must have one outbox event');

    $replay = $recipe->recordRecipeConsumption($conn, $explosion, [
        'pos_tenant' => 1,
        'pos_branch' => 1,
        'branch_uuid' => '41414141-4141-4141-8141-414141414141',
        'store_id' => 1,
        'channel' => 'pos',
        'order_type' => 'takeaway',
        'order_id' => 4203,
        'fat_detail_id' => 4204,
        'order_line_uuid' => 'quantity-only-line-4204',
        'recipe_order_line_usage_id' => 4205,
        'created_by' => 7,
    ]);
    quantityOnlyAssert($replay->movementIds === $recipeResult->movementIds, 'recipe retry must replay the same movement identity');
    quantityOnlyAssert(
        (int) $conn->query('SELECT COUNT(*) AS c FROM inventory_movements')->fetch_assoc()['c'] === 4,
        'recipe retry must not duplicate quantity'
    );
    quantityOnlyAssert(
        quantityOnlyTableCount($conn, 'journal_heads') === 0 && quantityOnlyTableCount($conn, 'journal_entries') === 0,
        'quantity-only operation must not require or create financial journal tables'
    );

    echo "inventory-quantity-only-runtime-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function quantityOnlyConfig(): array
{
    return [
        'role' => 'branch',
        'branch' => [
            'uuid' => '41414141-4141-4141-8141-414141414141',
            'name' => 'Quantity Only Runtime',
            'pos_tenant' => 1,
            'pos_branch' => 1,
        ],
        'sync' => [
            'outbox_enabled' => true,
            'branch_sync_enabled' => true,
            'operational_sync_enabled' => true,
        ],
        'inventory' => [
            'ledger_mode' => 'off',
            'quantity_tracking' => true,
            'accounting' => false,
        ],
        'recipe' => [
            'enabled' => true,
            'mode' => 'consume_pilot',
            'consumption' => true,
            'pilot' => [
                'pos_branch' => '1',
            ],
        ],
    ];
}

function quantityOnlyMovement(
    string $movementType,
    string $qtyIn,
    string $qtyOut,
    string $unitCost,
    string $key
): array {
    return [
        'scope' => [
            'pos_tenant' => 1,
            'pos_branch' => 1,
            'branch_uuid' => '41414141-4141-4141-8141-414141414141',
            'store_id' => 1,
        ],
        'item_id' => 4101,
        'movement_type' => $movementType,
        'source_type' => 'manual',
        'source_uuid' => $key,
        'qty_in' => $qtyIn,
        'qty_out' => $qtyOut,
        'unit_cost' => $unitCost,
        'idempotency_key' => $key,
        'created_by' => 7,
    ];
}

function quantityOnlyTableCount(mysqli $conn, string $table): int
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS c FROM information_schema.tables'
        . ' WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $count = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    return $count;
}

function quantityOnlyAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
