<?php

declare(strict_types=1);

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Inventory/InventoryFeatureFlags.php';
require_once __DIR__ . '/../../classes/Inventory/InventoryLedgerService.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeFeatureFlags.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeInventoryMovementService.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeAccountingService.php';
require_once __DIR__ . '/../../classes/Recipe/Repository/InventoryMovementRepository.php';
require_once __DIR__ . '/../../classes/Recipe/DTO/IngredientRequirement.php';
require_once __DIR__ . '/../../classes/Recipe/DTO/RecipeExplosionResult.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_recipe_truth_' . getmypid() . '_' . bin2hex(random_bytes(4));
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->apply($conn);
    recipeTruthCreateAccountingSchema($conn);
    recipeTruthSeedAccounts($conn);

    $config = recipeTruthConfig();
    $inventoryFlags = new InventoryFeatureFlags($config);
    $recipeFlags = new RecipeFeatureFlags($config);
    $ledger = new InventoryLedgerService($inventoryFlags);
    $movements = new RecipeInventoryMovementService($recipeFlags);
    $accounting = new RecipeAccountingService($recipeFlags);
    $repository = new InventoryMovementRepository();

    recipeTruthOpen($ledger, $conn, 6101, '10.000000', '2.000000', 'truth:opening:6101');
    $sale = recipeTruthSale($conn, $movements, $accounting, $config, 6101, 6201, 7001, 7101, '4.000000', '2.000000');
    recipeTruthAssert(count($sale['movement_ids']) === 1, 'sale must consume one ingredient movement');
    recipeTruthBalance($conn, 6101, '6.000000', '2.000000');
    recipeTruthJournal($conn, (int) $sale['journal_head_id'], [
        [5100, '8.000000', '0.000000'],
        [1100, '0.000000', '8.000000'],
    ]);

    $original = $repository->findByIds($conn, $sale['movement_ids']);
    $waste = recipeTruthRefund(
        $conn,
        $movements,
        $accounting,
        $config,
        $original,
        6201,
        7001,
        'truth:waste:7001',
        '1.000000',
        '2.000000',
        'waste'
    );
    recipeTruthAssert($waste['movement_ids'] === [] && $waste['journal_head_id'] === 0, 'waste refund must not restore stock or reverse COGS');
    recipeTruthBalance($conn, 6101, '6.000000', '2.000000');

    $partial = recipeTruthRefund(
        $conn,
        $movements,
        $accounting,
        $config,
        $original,
        6201,
        7001,
        'truth:return:7001:1',
        '1.000000',
        '2.000000',
        'return_to_stock'
    );
    recipeTruthAssert(count($partial['movement_ids']) === 1, 'explicit partial return must create one bounded reversal');
    recipeTruthMovementTotals($conn, 7001, '2.000000', '4.000000');
    recipeTruthBalance($conn, 6101, '8.000000', '2.000000');
    recipeTruthJournal($conn, (int) $partial['journal_head_id'], [
        [1100, '4.000000', '0.000000'],
        [5100, '0.000000', '4.000000'],
    ]);

    $beforeReplay = recipeTruthCounts($conn);
    $partialReplay = recipeTruthRefund(
        $conn,
        $movements,
        $accounting,
        $config,
        $original,
        6201,
        7001,
        'truth:return:7001:1',
        '1.000000',
        '2.000000',
        'return_to_stock'
    );
    recipeTruthAssert($partialReplay['movement_ids'] === $partial['movement_ids'], 'refund retry must replay the same movement identity');
    recipeTruthAssert(recipeTruthCounts($conn) === $beforeReplay, 'refund retry must not duplicate movement, journal, or outbox records');

    $full = recipeTruthRefund(
        $conn,
        $movements,
        $accounting,
        $config,
        $original,
        6201,
        7001,
        'truth:return:7001:2',
        '1.000000',
        '2.000000',
        'return_to_stock'
    );
    recipeTruthAssert(count($full['movement_ids']) === 1, 'second partial must complete the exact full return');
    recipeTruthMovementTotals($conn, 7001, '4.000000', '8.000000');
    recipeTruthBalance($conn, 6101, '10.000000', '2.000000');

    $over = recipeTruthRefund(
        $conn,
        $movements,
        $accounting,
        $config,
        $original,
        6201,
        7001,
        'truth:return:7001:over',
        '1.000000',
        '2.000000',
        'return_to_stock'
    );
    recipeTruthAssert($over['movement_ids'] === [], 'cumulative return must never exceed original recipe consumption');

    // Default paid reversal disposition is waste: the financial reversal may
    // proceed elsewhere, but ingredient stock and COGS remain consumed.
    recipeTruthOpen($ledger, $conn, 6102, '5.000000', '3.000000', 'truth:opening:6102');
    $voidSale = recipeTruthSale($conn, $movements, $accounting, $config, 6102, 6202, 7002, 7102, '2.000000', '3.000000');
    $voidOriginal = $repository->findByIds($conn, $voidSale['movement_ids']);
    $voidWaste = recipeTruthRefund(
        $conn,
        $movements,
        $accounting,
        $config,
        $voidOriginal,
        6202,
        7002,
        'truth:void-waste:7002',
        '1.000000',
        '1.000000',
        'waste'
    );
    recipeTruthAssert($voidWaste['movement_ids'] === [], 'paid reversal must not silently restore recipe ingredients');
    recipeTruthBalance($conn, 6102, '3.000000', '3.000000');

    // A manager-selected return-to-stock disposition reverses both stock and
    // COGS exactly once.
    $voidReturn = recipeTruthRefund(
        $conn,
        $movements,
        $accounting,
        $config,
        $voidOriginal,
        6202,
        7002,
        'truth:void-return:7002',
        '1.000000',
        '1.000000',
        'return_to_stock'
    );
    recipeTruthAssert(count($voidReturn['movement_ids']) === 1, 'explicit paid reversal return must restore stock once');
    recipeTruthBalance($conn, 6102, '5.000000', '3.000000');
    recipeTruthJournal($conn, (int) $voidReturn['journal_head_id'], [
        [1100, '6.000000', '0.000000'],
        [5100, '0.000000', '6.000000'],
    ]);

    $unbalanced = $conn->query(
        'SELECT COUNT(*) AS c FROM ('
        . ' SELECT journal_id FROM journal_entries GROUP BY journal_id'
        . ' HAVING CAST(SUM(debit) AS DECIMAL(24,6)) <> CAST(SUM(credit) AS DECIMAL(24,6))'
        . ') x'
    )->fetch_assoc();
    recipeTruthAssert((int) ($unbalanced['c'] ?? 0) === 0, 'every recipe COGS and reversal journal must balance exactly');
    $unlinked = $conn->query(
        "SELECT COUNT(*) AS c FROM inventory_movements"
        . " WHERE movement_type IN ('recipe_consumption', 'refund_reversal')"
        . ' AND (accounting_journal_id IS NULL OR accounting_journal_id = 0)'
    )->fetch_assoc();
    recipeTruthAssert((int) ($unlinked['c'] ?? 0) === 0, 'every accounting-enabled recipe stock movement must link to its journal');
    $outbox = $conn->query(
        "SELECT COUNT(*) AS c FROM inventory_movements m"
        . " LEFT JOIN sync_outbox o ON o.aggregate_type = 'inventory_movement'"
        . ' AND o.aggregate_local_id = m.id AND o.event_version = m.id'
        . ' WHERE o.id IS NULL'
    )->fetch_assoc();
    recipeTruthAssert((int) ($outbox['c'] ?? 0) === 0, 'every authoritative recipe inventory movement must have its atomic outbox event');

    echo "recipe-sale-refund-reversal-truth-table-runtime-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function recipeTruthConfig(): array
{
    return [
        'role' => 'branch',
        'branch' => [
            'uuid' => '61616161-6161-4161-8161-616161616161',
            'name' => 'Recipe Truth Table',
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
            'accounting' => true,
            'accounts' => [
                'inventory_asset_account_id' => 1100,
                'cogs_account_id' => 5100,
            ],
        ],
        'recipe' => [
            'enabled' => true,
            'mode' => 'full',
            'consumption' => true,
            'accounting' => true,
        ],
    ];
}

function recipeTruthOpen(
    InventoryLedgerService $ledger,
    mysqli $conn,
    int $itemId,
    string $qty,
    string $cost,
    string $key
): void {
    $ledger->recordMovement($conn, [
        'scope' => recipeTruthScope(),
        'item_id' => $itemId,
        'movement_type' => 'opening_balance',
        'source_type' => 'manual',
        'source_uuid' => $key,
        'qty_in' => $qty,
        'unit_cost' => $cost,
        'idempotency_key' => $key,
        'created_by' => 7,
    ], ['item_id' => $itemId, 'item_type' => 'ingredient', 'track_stock' => 1]);
}

function recipeTruthSale(
    mysqli $conn,
    RecipeInventoryMovementService $movements,
    RecipeAccountingService $accounting,
    array $config,
    int $ingredientItemId,
    int $sellableItemId,
    int $orderId,
    int $lineId,
    string $ingredientQty,
    string $unitCost
): array {
    $explosion = new RecipeExplosionResult([
        'sellable_item_id' => $sellableItemId,
        'recipe_id' => $sellableItemId + 100,
        'recipe_version' => 1,
        'has_recipe' => true,
        'requirements' => [
            new IngredientRequirement([
                'ingredient_item_id' => $ingredientItemId,
                'required_qty_base' => $ingredientQty,
                'unit_conversion_to_base' => '1.00000000',
                'unit_cost' => $unitCost,
                'total_cost' => RecipeDecimal::multiply($ingredientQty, $unitCost),
            ]),
        ],
    ]);
    $context = array_merge(recipeTruthScope(), [
        'channel' => 'pos',
        'order_type' => 'takeaway',
        'order_id' => $orderId,
        'fat_detail_id' => $lineId,
        'order_line_uuid' => 'truth-line-' . $lineId,
        'recipe_order_line_usage_id' => $lineId + 1000,
        'sellable_item_id' => $sellableItemId,
        'cogs_account_id' => 5100,
        'inventory_account_id' => 1100,
        'user_id' => 7,
        'created_by' => 7,
        'sync_config' => $config,
    ]);

    $conn->begin_transaction();
    try {
        $movement = $movements->recordRecipeConsumption($conn, $explosion, $context);
        $journal = $accounting->postSaleCogs($conn, $context, $movement->movementIds);
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }

    return [
        'movement_ids' => $movement->movementIds,
        'journal_head_id' => (int) ($journal['journal_head_id'] ?? 0),
    ];
}

function recipeTruthRefund(
    mysqli $conn,
    RecipeInventoryMovementService $movements,
    RecipeAccountingService $accounting,
    array $config,
    array $original,
    int $sellableItemId,
    int $orderId,
    string $refundUuid,
    string $refundQty,
    string $originalQty,
    string $policy
): array {
    $context = array_merge(recipeTruthScope(), [
        'order_id' => $orderId,
        'sellable_item_id' => $sellableItemId,
        'refund_uuid' => $refundUuid,
        'refund_order_quantity' => $refundQty,
        'original_order_quantity' => $originalQty,
        'policy' => $policy,
        'cogs_account_id' => 5100,
        'inventory_account_id' => 1100,
        'user_id' => 7,
        'created_by' => 7,
        'sync_config' => $config,
    ]);

    $conn->begin_transaction();
    try {
        $movement = $movements->recordRefundReversal($conn, $original, $context);
        $journal = $movement->movementIds
            ? $accounting->postRefundReversal($conn, $context, $movement->movementIds)
            : ['journal_head_id' => 0];
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }

    return [
        'movement_ids' => $movement->movementIds,
        'journal_head_id' => (int) ($journal['journal_head_id'] ?? 0),
    ];
}

function recipeTruthScope(): array
{
    return [
        'pos_tenant' => 1,
        'pos_branch' => 1,
        'branch_uuid' => '61616161-6161-4161-8161-616161616161',
        'store_id' => 1,
    ];
}

function recipeTruthBalance(mysqli $conn, int $itemId, string $qty, string $cost): void
{
    $row = $conn->query(
        'SELECT qty_on_hand, qty_available, moving_average_cost'
        . " FROM inventory_item_balances WHERE item_id = {$itemId} LIMIT 1"
    )->fetch_assoc();
    recipeTruthAssert((string) ($row['qty_on_hand'] ?? '') === $qty, 'unexpected exact on-hand quantity for item ' . $itemId);
    recipeTruthAssert((string) ($row['qty_available'] ?? '') === $qty, 'unexpected exact available quantity for item ' . $itemId);
    recipeTruthAssert((string) ($row['moving_average_cost'] ?? '') === $cost, 'unexpected moving-average cost for item ' . $itemId);
}

function recipeTruthMovementTotals(mysqli $conn, int $orderId, string $qty, string $cost): void
{
    $row = $conn->query(
        'SELECT CAST(COALESCE(SUM(qty_in), 0) AS DECIMAL(18,6)) AS qty,'
        . ' CAST(COALESCE(SUM(total_cost), 0) AS DECIMAL(18,6)) AS cost'
        . " FROM inventory_movements WHERE order_id = {$orderId} AND movement_type = 'refund_reversal'"
    )->fetch_assoc();
    recipeTruthAssert((string) ($row['qty'] ?? '') === $qty, 'cumulative recipe return quantity must be exact');
    recipeTruthAssert((string) ($row['cost'] ?? '') === $cost, 'cumulative recipe COGS reversal must be exact');
}

function recipeTruthJournal(mysqli $conn, int $journalHeadId, array $expected): void
{
    recipeTruthAssert($journalHeadId > 0, 'expected an accounting journal');
    $result = $conn->query(
        'SELECT account_id, debit, credit FROM journal_entries'
        . " WHERE journal_id = {$journalHeadId} ORDER BY id"
    );
    $actual = [];
    while ($row = $result->fetch_assoc()) {
        $actual[] = [(int) $row['account_id'], (string) $row['debit'], (string) $row['credit']];
    }
    recipeTruthAssert($actual === $expected, 'journal entries do not match the exact Inventory/COGS truth table');
}

function recipeTruthCounts(mysqli $conn): array
{
    return [
        'movements' => (int) $conn->query('SELECT COUNT(*) AS c FROM inventory_movements')->fetch_assoc()['c'],
        'journals' => (int) $conn->query('SELECT COUNT(*) AS c FROM journal_heads')->fetch_assoc()['c'],
        'outbox' => (int) $conn->query('SELECT COUNT(*) AS c FROM sync_outbox')->fetch_assoc()['c'],
    ];
}

function recipeTruthCreateAccountingSchema(mysqli $conn): void
{
    $conn->query("CREATE TABLE acc_head (
        id BIGINT UNSIGNED NOT NULL PRIMARY KEY, code VARCHAR(20) NOT NULL, deletable INT DEFAULT 1,
        editable INT DEFAULT 1, aname VARCHAR(100) NOT NULL, constant INT DEFAULT 0, is_stock INT DEFAULT 0,
        is_fund INT DEFAULT 0, rentable INT NULL, parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0, nature INT NULL,
        kind INT NULL, is_basic INT NOT NULL DEFAULT 0, isdeleted TINYINT NOT NULL DEFAULT 0,
        tenant INT DEFAULT 0, branch INT DEFAULT 0
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE journal_heads (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, journal_id BIGINT UNSIGNED NOT NULL,
        total DECIMAL(24,6) NOT NULL, jdate DATE NOT NULL, op_id BIGINT NULL, pro_tybe INT NULL,
        details VARCHAR(250) NULL, op2 BIGINT DEFAULT 0, isdeleted TINYINT DEFAULT 0, user BIGINT NULL,
        tenant INT DEFAULT 0, branch INT DEFAULT 0, source_type VARCHAR(64) NULL, source_id BIGINT NULL,
        posting_kind VARCHAR(64) NULL, idempotency_key VARCHAR(191) NULL, reversal_of_journal_id BIGINT NULL,
        UNIQUE KEY uq_recipe_truth_idempotency (idempotency_key)
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE journal_entries (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, journal_id BIGINT UNSIGNED NOT NULL,
        account_id BIGINT UNSIGNED NOT NULL, debit DECIMAL(24,6) NOT NULL DEFAULT 0,
        credit DECIMAL(24,6) NOT NULL DEFAULT 0, tybe INT NOT NULL DEFAULT 0, op2 BIGINT DEFAULT 0,
        op_id BIGINT DEFAULT 0, isdeleted TINYINT DEFAULT 0, tenant INT DEFAULT 0, branch INT DEFAULT 0
    ) ENGINE=InnoDB");
}

function recipeTruthSeedAccounts(mysqli $conn): void
{
    $conn->query("INSERT INTO acc_head (id, code, aname, parent_id, is_basic, tenant, branch) VALUES
        (1, '1', 'Recipe truth root', 0, 1, 1, 1),
        (1100, '1100', 'Inventory asset', 1, 0, 1, 1),
        (5100, '5100', 'COGS', 1, 0, 1, 1)");
}

function recipeTruthAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
