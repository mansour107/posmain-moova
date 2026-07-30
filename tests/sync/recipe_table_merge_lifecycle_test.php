<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/TableMergeService.php';
require_once __DIR__ . '/../../classes/Recipe/DTO/RecipeActorContext.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeDefinitionService.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeFeatureFlags.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeOrderLifecycleService.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/InventoryBalanceRepository.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_recipe_table_merge_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    recipeTableMergeCreateSchema($conn);
    recipeTableMergeSeedRecipe($conn, 41010, 41011, '10.000000');

    $flags = recipeTableMergeFlags();
    $lifecycle = new RecipeOrderLifecycleService($flags);
    $merge = new TableMergeService(null, null, $lifecycle);

    $lifecycle->onOrderLineAdded([
        'conn' => $conn,
        'order_id' => 100,
        'fat_detail_id' => 1001,
        'store_id' => 3,
        'channel' => 'table',
        'order_type' => 'dine_in',
        'sellable_item_id' => 41010,
        'quantity' => '3.000000',
    ]);
    $reservedBeforeMerge = (new InventoryBalanceRepository())->findBalance($conn, 0, 0, 3, 41011);
    recipeTableMergeAssert($reservedBeforeMerge['qty_reserved'] === '3.000000', 'source table order should reserve three ingredient units before merge');

    $result = $merge->mergeOrders($conn, [
        'source_table_id' => 1,
        'destination_table_id' => 2,
        'source_order_id' => 100,
        'source_mutation_version' => 1,
        'destination_mutation_version' => 1,
        'destination_order_id' => 200,
    ], [
        'user_id' => 77,
        'tenant' => 0,
        'branch' => 0,
    ]);
    recipeTableMergeAssert($result['success'] === true, 'table merge should succeed');

    $sourceUsages = recipeTableMergeRows($conn, 'recipe_order_line_usage', 'order_id = 100');
    $destinationUsages = recipeTableMergeRows($conn, 'recipe_order_line_usage', 'order_id = 200 AND sellable_item_id = 41010');
    $sourceReservations = recipeTableMergeRows($conn, 'stock_reservations', 'order_id = 100');
    $destinationReservations = recipeTableMergeRows($conn, 'stock_reservations', 'order_id = 200 AND sellable_item_id = 41010');
    $balanceAfterMerge = (new InventoryBalanceRepository())->findBalance($conn, 0, 0, 3, 41011);

    recipeTableMergeAssert(array_column($sourceUsages, 'status') === ['released'], 'source recipe usage should be released after merge');
    recipeTableMergeAssert(array_column($destinationUsages, 'status') === ['reserved'], 'destination recipe usage should be reserved after merge');
    recipeTableMergeAssert(array_column($sourceReservations, 'status') === ['released'], 'source reservation should be released after merge');
    recipeTableMergeAssert(array_column($destinationReservations, 'status') === ['reserved'], 'destination reservation should be active after merge');
    recipeTableMergeAssert($destinationReservations[0]['qty_reserved'] === '3.000000', 'destination reservation should keep the moved quantity');
    recipeTableMergeAssert($balanceAfterMerge['qty_on_hand'] === '10.000000', 'merge should not consume stock');
    recipeTableMergeAssert($balanceAfterMerge['qty_reserved'] === '3.000000', 'merge should keep total reserved quantity unchanged');

    $lifecycle->onOrderPaid([
        'conn' => $conn,
        'order_id' => 200,
        'store_id' => 3,
        'channel' => 'table',
        'order_type' => 'dine_in',
        'lines' => [
            [
                'fat_detail_id' => 1001,
                'sellable_item_id' => 41010,
                'quantity' => '3.000000',
            ],
        ],
    ]);
    $balanceAfterPayment = (new InventoryBalanceRepository())->findBalance($conn, 0, 0, 3, 41011);
    $consumedQty = $conn->query("SELECT COALESCE(SUM(qty_out), 0) AS qty FROM inventory_movements WHERE item_id = 41011 AND movement_type = 'recipe_consumption'")->fetch_assoc();
    $destinationUsageAfterPay = recipeTableMergeRows($conn, 'recipe_order_line_usage', 'order_id = 200 AND sellable_item_id = 41010');

    recipeTableMergeAssert($balanceAfterPayment['qty_on_hand'] === '7.000000', 'merged order payment should consume three ingredient units once');
    recipeTableMergeAssert($balanceAfterPayment['qty_reserved'] === '0.000000', 'merged order payment should consume the moved reservation');
    recipeTableMergeAssert(abs((float) $consumedQty['qty'] - 3.0) < 0.0001, 'merged order payment should not double-consume');
    recipeTableMergeAssert(array_column($destinationUsageAfterPay, 'status') === ['consumed'], 'destination recipe usage should be consumed after payment');

    echo "recipe-table-merge-lifecycle-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function recipeTableMergeCreateSchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE settings (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            def_pos_store INT UNSIGNED NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $conn->query("
        CREATE TABLE acc_head (
            id INT UNSIGNED NOT NULL PRIMARY KEY,
            code VARCHAR(32) NOT NULL,
            aname VARCHAR(191) NOT NULL,
            is_stock TINYINT(1) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $conn->query("INSERT INTO acc_head (id, code, aname, is_stock) VALUES (3, 'STORE-3', 'Operational store', 1)");
    $conn->query("INSERT INTO settings (def_pos_store) VALUES (3)");
    $conn->query("
        CREATE TABLE tables (
            id INT NOT NULL PRIMARY KEY,
            tname VARCHAR(120) NULL,
            table_case INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE ot_head (
            id INT NOT NULL PRIMARY KEY,
            pro_tybe INT NULL,
            table_id INT NULL,
            store_id INT NULL DEFAULT 3,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            order_status ENUM('draft','active','completed','cancelled') NULL,
            payment_status ENUM('unpaid','partial','paid','refunded','voided') NULL,
            invoice_status ENUM('draft','completed','cancelled') NULL,
            fat_total DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_disc DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_net DECIMAL(15,4) NOT NULL DEFAULT 0,
            pro_value DECIMAL(15,4) NOT NULL DEFAULT 0,
            profit DECIMAL(15,4) NOT NULL DEFAULT 0,
            paid_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            remaining_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            completed_at DATETIME NULL,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE fat_details (
            id INT NOT NULL PRIMARY KEY,
            fatid INT NOT NULL,
            pro_id INT NULL,
            item_id INT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            qty_in DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            qty_out DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            u_val DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
            det_store INT NOT NULL DEFAULT 0,
            det_value DECIMAL(15,4) NOT NULL DEFAULT 0,
            profit DECIMAL(15,4) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    (new SyncSchemaManager())->apply($conn);
    $conn->query("
        CREATE TABLE IF NOT EXISTS myitems (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            iname VARCHAR(255) NULL,
            cost_price DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            group1 BIGINT UNSIGNED NOT NULL DEFAULT 7,
            item_type VARCHAR(64) NOT NULL DEFAULT 'sellable',
            track_stock TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        INSERT INTO tables (id, tname, table_case, isdeleted) VALUES
        (1, 'T1', 1, 0),
        (2, 'T2', 1, 0)
    ");
    $conn->query("
        INSERT INTO ot_head (
            id, pro_tybe, table_id, store_id, isdeleted, order_status, payment_status,
            invoice_status, fat_total, fat_disc, fat_net, pro_value, profit,
            paid_amount, remaining_amount, tenant, branch
        ) VALUES
        (100, 9, 1, 3, 0, 'active', 'unpaid', 'draft', 30, 0, 30, 30, 12, 0, 30, 0, 0),
        (200, 9, 2, 3, 0, 'active', 'unpaid', 'draft', 10, 0, 10, 10, 4, 0, 10, 0, 0)
    ");
    $conn->query("
        INSERT INTO fat_details (id, fatid, pro_id, item_id, isdeleted, qty_in, qty_out, u_val, det_store, det_value, profit) VALUES
        (1001, 100, 100, 41010, 0, 0, 6.000000, 2.000000, 3, 30, 12),
        (2001, 200, 200, 41020, 0, 0, 1.000000, 1.000000, 3, 10, 4)
    ");
}

function recipeTableMergeSeedRecipe(mysqli $conn, int $sellableItemId, int $ingredientItemId, string $stock): void
{
    $conn->query("
        INSERT INTO myitems (id, iname, cost_price, group1, item_type, track_stock)
        VALUES
            ({$sellableItemId}, 'Merge recipe item', 0.000000, 7, 'sellable', 1),
            ({$ingredientItemId}, 'Merge recipe ingredient', 4.000000, 7, 'ingredient', 1),
            (41020, 'Merge non-recipe item', 2.000000, 7, 'sellable', 1)
    ");
    (new InventoryBalanceRepository())->putBalance($conn, [
        'pos_tenant' => 0,
        'pos_branch' => 0,
        'store_id' => 3,
        'item_id' => $ingredientItemId,
        'qty_on_hand' => $stock,
        'qty_reserved' => '0.000000',
        'qty_available' => $stock,
    ]);

    $definition = new RecipeDefinitionService(new RecipeFeatureFlags([
        'recipe' => [
            'enabled' => true,
            'mode' => 'shadow',
        ],
    ]));
    $actor = new RecipeActorContext(77, 0, 0, null, ['recipe.manage', 'recipe.approve']);
    $recipe = $definition->createDraft($conn, [
        'sellable_item_id' => $sellableItemId,
        'recipe_name' => 'Merge payment recipe',
    ], $actor);
    $definition->addLine($conn, (int) $recipe['id'], [
        'ingredient_item_id' => $ingredientItemId,
        'qty_per_yield' => '1.000000',
    ], $actor);
    $definition->activate($conn, (int) $recipe['id'], $actor);
}

function recipeTableMergeFlags(): RecipeFeatureFlags
{
    return new RecipeFeatureFlags([
        'recipe' => [
            'enabled' => true,
            'mode' => 'consume_pilot',
            'reservations' => true,
            'consumption' => true,
            'pilot' => [
                'pos_branch' => '0',
                'item_ids' => [],
                'category_ids' => [],
            ],
        ],
    ]);
}

function recipeTableMergeRows(mysqli $conn, string $table, string $where): array
{
    $result = $conn->query("SELECT * FROM {$table} WHERE {$where} ORDER BY id");
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

function recipeTableMergeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
