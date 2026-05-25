<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
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
$db = 'posmain_recipe_reservation_lifecycle_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    recipeReservationLifecycleCreateSchema($conn);
    recipeReservationLifecycleSeedRecipe($conn, 51010, 51011, '12.000000');

    $service = new RecipeOrderLifecycleService(recipeReservationLifecycleFlags());
    $ctx = recipeReservationLifecycleLineContext($conn, 7100, 7101, 51010, '2.000000');

    $added = $service->onOrderLineAdded($ctx);
    $addedAgain = $service->onOrderLineAdded($ctx);
    $balanceAfterAdd = recipeReservationLifecycleBalance($conn, 51011);
    $reservationsAfterAdd = recipeReservationLifecycleRows($conn, 'stock_reservations', 'order_id = 7100');

    recipeReservationLifecycleAssert(empty($added['noop']), 'reserve_only add should reserve the recipe ingredient');
    recipeReservationLifecycleAssert(empty($addedAgain['noop']), 'reserve_only duplicate add should return the existing reservation result');
    recipeReservationLifecycleAssert(count($reservationsAfterAdd) === 1, 'duplicate add should not create a second reservation row');
    recipeReservationLifecycleAssert($reservationsAfterAdd[0]['status'] === 'reserved', 'reservation should be active after add');
    recipeReservationLifecycleAssert($balanceAfterAdd['qty_on_hand'] === '12.000000', 'reservation should not consume stock on hand');
    recipeReservationLifecycleAssert($balanceAfterAdd['qty_reserved'] === '2.000000', 'reservation should increase qty_reserved');

    $newCtx = $ctx;
    $newCtx['quantity'] = '1.000000';
    $updated = $service->onOrderLineUpdated($ctx, $newCtx);
    $balanceAfterUpdate = recipeReservationLifecycleBalance($conn, 51011);
    $reservationsAfterUpdate = recipeReservationLifecycleRows($conn, 'stock_reservations', 'order_id = 7100');

    recipeReservationLifecycleAssert(empty($updated['noop']), 'reserve_only update should replace the reservation');
    recipeReservationLifecycleAssert(array_column($reservationsAfterUpdate, 'status') === ['released', 'reserved'], 'update should release old reservation and reserve the new quantity');
    recipeReservationLifecycleAssert(array_column($reservationsAfterUpdate, 'qty_reserved') === ['2.000000', '1.000000'], 'reservation rows should preserve old and new quantities');
    recipeReservationLifecycleAssert($balanceAfterUpdate['qty_on_hand'] === '12.000000', 'reservation update should not consume stock on hand');
    recipeReservationLifecycleAssert($balanceAfterUpdate['qty_reserved'] === '1.000000', 'reservation update should keep only the new reserved quantity');

    $cancelled = $service->onOrderLineCancelled($newCtx, 'runtime_reservation_cancel');
    $balanceAfterCancel = recipeReservationLifecycleBalance($conn, 51011);
    $reservationsAfterCancel = recipeReservationLifecycleRows($conn, 'stock_reservations', 'order_id = 7100');
    $movementTypes = array_column(recipeReservationLifecycleRows($conn, 'inventory_movements', 'order_id = 7100'), 'movement_type');

    recipeReservationLifecycleAssert(empty($cancelled['noop']), 'reserve_only cancel should release the active reservation');
    recipeReservationLifecycleAssert(array_column($reservationsAfterCancel, 'status') === ['released', 'released'], 'cancel should leave no active reservations');
    recipeReservationLifecycleAssert($balanceAfterCancel['qty_on_hand'] === '12.000000', 'cancel should not consume stock on hand');
    recipeReservationLifecycleAssert($balanceAfterCancel['qty_reserved'] === '0.000000', 'cancel should clear qty_reserved');
    recipeReservationLifecycleAssert(in_array('reservation', $movementTypes, true), 'reservation movement should be recorded');
    recipeReservationLifecycleAssert(in_array('reservation_release', $movementTypes, true), 'reservation release movement should be recorded');
    recipeReservationLifecycleAssert(!in_array('recipe_consumption', $movementTypes, true), 'reserve_only should not write consumption movements');

    echo "recipe-reservation-lifecycle-runtime-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function recipeReservationLifecycleCreateSchema(mysqli $conn): void
{
    (new SyncSchemaManager())->apply($conn);
    $conn->query("
        CREATE TABLE IF NOT EXISTS item_group (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            gname VARCHAR(255) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $conn->query("INSERT INTO item_group (id, gname) VALUES (7, 'Reservation Runtime')");
    $conn->query("
        CREATE TABLE IF NOT EXISTS myitems (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            uuid CHAR(36) NULL,
            iname VARCHAR(255) NULL,
            cost_price DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            group1 BIGINT UNSIGNED NOT NULL DEFAULT 7,
            item_type VARCHAR(64) NOT NULL DEFAULT 'sellable',
            track_stock TINYINT(1) NOT NULL DEFAULT 1,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function recipeReservationLifecycleSeedRecipe(mysqli $conn, int $sellableItemId, int $ingredientItemId, string $stock): void
{
    $conn->query("
        INSERT INTO myitems (id, iname, cost_price, group1, item_type, track_stock)
        VALUES
            ({$sellableItemId}, 'Reservation runtime item', 0.000000, 7, 'sellable', 1),
            ({$ingredientItemId}, 'Reservation runtime ingredient', 3.000000, 7, 'ingredient', 1)
    ");
    (new InventoryBalanceRepository())->putBalance($conn, [
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
        'recipe_name' => 'Reservation runtime recipe',
    ], $actor);
    $definition->addLine($conn, (int) $recipe['id'], [
        'ingredient_item_id' => $ingredientItemId,
        'qty_per_yield' => '1.000000',
    ], $actor);
    $definition->activate($conn, (int) $recipe['id'], $actor);
}

function recipeReservationLifecycleFlags(): RecipeFeatureFlags
{
    return new RecipeFeatureFlags([
        'recipe' => [
            'enabled' => true,
            'mode' => 'reserve_only',
            'reservations' => true,
            'pilot' => [
                'pos_branch' => '0',
                'item_ids' => [],
                'category_ids' => [],
            ],
        ],
    ]);
}

function recipeReservationLifecycleLineContext(mysqli $conn, int $orderId, int $lineId, int $itemId, string $qty): array
{
    return [
        'conn' => $conn,
        'order_id' => $orderId,
        'fat_detail_id' => $lineId,
        'pos_tenant' => 0,
        'pos_branch' => 0,
        'store_id' => 0,
        'channel' => 'table',
        'order_type' => 'dine_in',
        'sellable_item_id' => $itemId,
        'quantity' => $qty,
    ];
}

function recipeReservationLifecycleBalance(mysqli $conn, int $itemId): array
{
    return (new InventoryBalanceRepository())->findBalance($conn, 0, 0, 0, $itemId);
}

function recipeReservationLifecycleRows(mysqli $conn, string $table, string $where): array
{
    $result = $conn->query("SELECT * FROM {$table} WHERE {$where} ORDER BY id");
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

function recipeReservationLifecycleAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
