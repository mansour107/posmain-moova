<?php

require_once __DIR__ . '/../../classes/Recipe/DTO/RecipeExplosionResult.php';
require_once __DIR__ . '/../../classes/Recipe/DTO/IngredientRequirement.php';
require_once __DIR__ . '/../../classes/Recipe/DTO/RecipeScope.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeFeatureFlags.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeInventoryMovementService.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/InventoryBalanceRepository.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/InventoryMovementRepository.php';

// Enable recipe consumption for this test process (consume_pilot + pilot branch 0 so
// every branch-0 sellable item is in pilot scope). Strict stock stays OFF so the
// negative-stock path is warn-only (no throw).
if (!function_exists('posmain_app_config')) {
    function posmain_app_config(): array
    {
        return [
            'recipe' => [
                'enabled' => true,
                'mode' => 'consume_pilot',
                'consumption' => true,
                'strict_stock' => false,
                'allow_negative_stock_with_approval' => true,
                'pilot' => ['pos_branch' => '0', 'item_ids' => [], 'category_ids' => []],
            ],
        ];
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    recipeNegativeStockWarnTestMain();
}

function recipeNegativeStockWarnTestMain(): void
{
    $host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
    $user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
    $pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';

    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = @new mysqli($host, $user, $pass, '', $port);
    if ($conn->connect_errno) {
        echo "recipe-negative-stock-warn-skipped-db-unavailable\n";
        return;
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = 'posmain_recipe_neg_stock_' . getmypid();

    try {
        $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        $conn->select_db($db);
        recipeNegativeStockWarnCreateSchema($conn);

        $sellableItemId = 6001;
        $ingredientItemId = 6002;

        // Ingredient starts at zero on-hand; consuming any qty drives it negative.
        (new InventoryBalanceRepository())->putBalance($conn, [
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 0,
            'item_id' => $ingredientItemId,
            'qty_on_hand' => '0.000000',
            'qty_reserved' => '0.000000',
            'qty_available' => '0.000000',
            'moving_average_cost' => '2.500000',
        ]);

        $explosion = new RecipeExplosionResult([
            'sellable_item_id' => $sellableItemId,
            'recipe_id' => 7001,
            'recipe_version' => 1,
            'cost_snapshot_id' => null,
            'has_recipe' => true,
            'requirements' => [
                new IngredientRequirement([
                    'ingredient_item_id' => $ingredientItemId,
                    'required_qty_base' => '0.500000',
                    'unit_conversion_to_base' => '1.00000000',
                    'unit_cost' => '2.500000',
                    'total_cost' => '1.250000',
                ]),
            ],
        ]);

        $orderContext = [
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 0,
            'channel' => 'pos',
            'order_type' => 'takeaway',
            'order_id' => 9001,
            'order_line_uuid' => 'ord-line-9001',
            'created_by' => 1,
        ];

        $captured = recipeNegativeStockWarnCaptureErrorLog(static function () use ($conn, $explosion, $orderContext) {
            $service = new RecipeInventoryMovementService();
            return $service->recordRecipeConsumption($conn, $explosion, $orderContext);
        });

        // 1) The sale must proceed (warn-only, strict stock OFF) — no exception thrown.
        recipeNegativeStockWarnAssert($captured['exception'] === null, 'consumption should not throw when stock goes negative (warn-only)');
        recipeNegativeStockWarnAssert(!($captured['result']->noop ?? false), 'consumption should record movements, not no-op');

        // 2) A tagged [recipe_negative_stock] warning must be emitted.
        $joined = implode("\n", $captured['logs']);
        recipeNegativeStockWarnAssert(
            strpos($joined, '[recipe_negative_stock]') !== false,
            'negative stock consumption should emit a tagged [recipe_negative_stock] warning log'
        );
        recipeNegativeStockWarnAssert(
            strpos($joined, 'item_id=' . $ingredientItemId) !== false,
            'negative stock warning should identify the ingredient item id'
        );

        // 3) The ingredient on-hand balance should now be negative (warn-only allows it).
        $balance = (new InventoryBalanceRepository())->findBalance($conn, 0, 0, 0, $ingredientItemId);
        recipeNegativeStockWarnAssert($balance !== null, 'ingredient balance row should exist after consumption');
        recipeNegativeStockWarnAssert(
            (float) ($balance['qty_on_hand'] ?? '0') < 0,
            'ingredient on-hand should be negative after consuming with zero stock, got: ' . ($balance['qty_on_hand'] ?? '?')
        );

        echo "recipe-negative-stock-warn-ok\n";
    } finally {
        $conn->query("DROP DATABASE IF EXISTS `{$db}`");
        $conn->close();
    }
}

function recipeNegativeStockWarnCaptureErrorLog(callable $callback): array
{
    $logs = [];
    $previousHandler = set_error_handler(static function ($severity, $message) use (&$logs) {
        return true;
    }, E_ALL);
    // PHP's error_log() with no destination writes to the PHP error log stream; capture via
    // ini override to a temp file we control.
    $tempFile = tempnam(sys_get_temp_dir(), 'posmain_negstock_');
    $previousLog = ini_set('error_log', $tempFile);

    $exception = null;
    $result = null;
    try {
        $result = $callback();
    } catch (Throwable $e) {
        $exception = $e;
    }

    if (is_file($tempFile)) {
        $contents = (string) file_get_contents($tempFile);
        $logs = array_values(array_filter(explode("\n", $contents), static function (string $line) {
            return trim($line) !== '';
        }));
        @unlink($tempFile);
    }

    if ($previousLog !== false) {
        ini_set('error_log', $previousLog);
    }
    if ($previousHandler !== null) {
        set_error_handler($previousHandler, E_ALL);
    } else {
        restore_error_handler();
    }

    return ['exception' => $exception, 'result' => $result, 'logs' => $logs];
}

function recipeNegativeStockWarnCreateSchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE inventory_movements (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            movement_uuid CHAR(36) NOT NULL,
            movement_group_uuid CHAR(36) NULL,
            pos_tenant INT NOT NULL DEFAULT 0,
            pos_branch INT NOT NULL DEFAULT 0,
            branch_uuid CHAR(36) NULL,
            store_id INT NOT NULL DEFAULT 0,
            item_id BIGINT UNSIGNED NOT NULL,
            movement_type VARCHAR(64) NOT NULL,
            source_type VARCHAR(64) NOT NULL DEFAULT 'manual',
            source_id BIGINT UNSIGNED NULL,
            source_uuid VARCHAR(128) NULL,
            order_id BIGINT UNSIGNED NULL,
            fat_detail_id BIGINT UNSIGNED NULL,
            order_line_uuid VARCHAR(64) NULL,
            recipe_order_line_usage_id BIGINT UNSIGNED NULL,
            recipe_id BIGINT UNSIGNED NULL,
            recipe_cost_snapshot_id BIGINT UNSIGNED NULL,
            production_batch_id BIGINT UNSIGNED NULL,
            qty_in DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            qty_out DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            unit_id BIGINT UNSIGNED NULL,
            unit_conversion_to_base DECIMAL(18,8) NOT NULL DEFAULT 1.00000000,
            unit_cost DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            total_cost DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            accounting_journal_id BIGINT UNSIGNED NULL,
            idempotency_key VARCHAR(191) NOT NULL,
            reversed_movement_id BIGINT UNSIGNED NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_inventory_movement_uuid (movement_uuid),
            UNIQUE KEY uq_inventory_idempotency (pos_tenant, pos_branch, store_id, idempotency_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE inventory_item_balances (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            pos_tenant INT NOT NULL DEFAULT 0,
            pos_branch INT NOT NULL DEFAULT 0,
            branch_uuid CHAR(36) NULL,
            store_id INT NOT NULL DEFAULT 0,
            item_id BIGINT UNSIGNED NOT NULL,
            qty_on_hand DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            qty_reserved DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            qty_available DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            moving_average_cost DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            last_movement_id BIGINT UNSIGNED NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_inventory_balance_item (pos_tenant, pos_branch, store_id, item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function recipeNegativeStockWarnAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
