<?php

require_once __DIR__ . '/../../classes/Recipe/RecipeAffectedItemCostService.php';
require_once __DIR__ . '/../../classes/Recipe/RecipeFeatureFlags.php';

// Enable recipes for this test process so RecipeFeatureFlags::isEnabled() returns true
// and the service proceeds past the feature guard (the tableExists guard still protects
// the missing-tables case).
if (!function_exists('posmain_app_config')) {
    function posmain_app_config(): array
    {
        return ['recipe' => ['enabled' => true, 'mode' => 'full']];
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    recipeAffectedItemCostTestMain();
}

function recipeAffectedItemCostTestMain(): void
{
    $host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
    $user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
    $pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';

    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = @new mysqli($host, $user, $pass, '', $port);
    if ($conn->connect_errno) {
        echo "recipe-affected-item-cost-skipped-db-unavailable\n";
        return;
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = 'posmain_recipe_affected_cost_' . getmypid();

    try {
        $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        $conn->select_db($db);

        recipeAffectedItemCostTestDisabledNoOp($conn);
        recipeAffectedItemCostTestMissingTablesNoOp($conn);
        recipeAffectedItemCostTestQueryCorrectness($conn);

        echo "recipe-affected-item-cost-ok\n";
    } finally {
        $conn->query("DROP DATABASE IF EXISTS `{$db}`");
        $conn->close();
    }
}

function recipeAffectedItemCostTestDisabledNoOp(mysqli $conn): void
{
    $service = new RecipeAffectedItemCostService();

    // A non-positive ingredient id must short-circuit before any flag/table check,
    // guaranteeing the purchase-path hook is harmless for invalid ids.
    $count = $service->resyncItemsUsingIngredient($conn, 0);
    recipeAffectedItemCostAssert($count === 0, 'non-positive ingredient id should be a no-op');

    $count = $service->resyncItemsUsingIngredient($conn, -1);
    recipeAffectedItemCostAssert($count === 0, 'negative ingredient id should be a no-op');
}

function recipeAffectedItemCostTestMissingTablesNoOp(mysqli $conn): void
{
    // Recipes are enabled via the test's posmain_app_config() helper, but no recipe tables
    // exist in this fresh db. The tableExists guard must prevent any query and return 0
    // without raising, so the ledger purchase hook is safe on shops without recipe schema.
    $service = new RecipeAffectedItemCostService();
    $count = $service->resyncItemsUsingIngredient($conn, 555);
    recipeAffectedItemCostAssert($count === 0, 'missing recipe tables should make resync a no-op without errors');
}

function recipeAffectedItemCostTestQueryCorrectness(mysqli $conn): void
{
    recipeAffectedItemCostCreateRecipeTables($conn);

    // Ingredient 700 is used by recipe 1001 (active) and recipe 1002 (archived) and
    // recipe 1003 (active but expired). Only 1001 should be selected for resync.
    $conn->query("INSERT INTO recipe_headers (id, recipe_uuid, pos_tenant, pos_branch, sellable_item_id, recipe_name, recipe_type, status, version_number, yield_qty, costing_method, effective_from, effective_to) VALUES
        (1001, 'rcp-1001', 0, 0, 5001, 'Latte', 'make_to_order', 'active', 1, 1.000000, 'item_cost_price', NULL, NULL),
        (1002, 'rcp-1002', 0, 0, 5002, 'Mocha', 'make_to_order', 'archived', 1, 1.000000, 'item_cost_price', NULL, NULL),
        (1003, 'rcp-1003', 0, 0, 5003, 'Cold Brew', 'make_to_order', 'active', 1, 1.000000, 'item_cost_price', '2020-01-01 00:00:00', '2020-02-01 00:00:00')
    ");
    $conn->query("INSERT INTO recipe_lines (id, recipe_id, line_uuid, ingredient_item_id, line_type, qty_per_yield, unit_conversion_to_base, is_required, sort_order) VALUES
        (1, 1001, 'ln-1', 700, 'ingredient', 0.020000, 1.00000000, 1, 0),
        (2, 1002, 'ln-2', 700, 'ingredient', 0.030000, 1.00000000, 1, 0),
        (3, 1003, 'ln-3', 700, 'ingredient', 0.025000, 1.00000000, 1, 0),
        (4, 1001, 'ln-4', 701, 'ingredient', 0.200000, 1.00000000, 1, 1)
    ");

    $service = new RecipeAffectedItemCostService();

    // The cost service itself will fail (no myitems/balances), but the query for active
    // recipes using ingredient 700 must resolve to exactly recipe 1001. We assert via the
    // private query method through reflection to validate selection without a full cost path.
    $ids = recipeAffectedItemCostCallPrivateQuery($service, $conn, 700);
    recipeAffectedItemCostAssert($ids === [1001], 'only the active, in-effect recipe using the ingredient should be selected, got: ' . json_encode($ids));

    // Ingredient 701 is used only by the active recipe 1001.
    $ids701 = recipeAffectedItemCostCallPrivateQuery($service, $conn, 701);
    recipeAffectedItemCostAssert($ids701 === [1001], 'ingredient 701 should resolve to recipe 1001, got: ' . json_encode($ids701));

    // Ingredient 999 is used by no recipe.
    $ids999 = recipeAffectedItemCostCallPrivateQuery($service, $conn, 999);
    recipeAffectedItemCostAssert($ids999 === [], 'unknown ingredient should resolve to no recipes');
}

function recipeAffectedItemCostCallPrivateQuery(RecipeAffectedItemCostService $service, mysqli $conn, int $ingredientItemId): array
{
    $method = new ReflectionMethod(RecipeAffectedItemCostService::class, 'activeRecipeIdsUsingIngredient');
    $method->setAccessible(true);

    return $method->invoke($service, $conn, $ingredientItemId);
}

function recipeAffectedItemCostCreateRecipeTables(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE recipe_headers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            recipe_uuid CHAR(36) NOT NULL,
            pos_tenant INT NOT NULL DEFAULT 0,
            pos_branch INT NOT NULL DEFAULT 0,
            branch_uuid CHAR(36) NULL,
            sellable_item_id BIGINT UNSIGNED NOT NULL,
            recipe_name VARCHAR(255) NOT NULL,
            recipe_type ENUM('make_to_order','batch_prepared','hybrid','packaging_bundle','modifier_only','sub_recipe') NOT NULL DEFAULT 'make_to_order',
            status ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
            version_number INT UNSIGNED NOT NULL DEFAULT 1,
            yield_qty DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
            yield_unit_id BIGINT UNSIGNED NULL,
            default_wastage_percent DECIMAL(9,4) NOT NULL DEFAULT 0.0000,
            effective_from DATETIME NULL,
            effective_to DATETIME NULL,
            costing_method ENUM('item_cost_price','moving_average','last_purchase','manual_snapshot') NOT NULL DEFAULT 'item_cost_price',
            requires_recipe_for_sale TINYINT(1) NOT NULL DEFAULT 0,
            allow_sale_without_stock TINYINT(1) NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NULL,
            approved_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            approved_at DATETIME NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_recipe_uuid (recipe_uuid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE recipe_lines (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            recipe_id BIGINT UNSIGNED NOT NULL,
            line_uuid CHAR(36) NOT NULL,
            ingredient_item_id BIGINT UNSIGNED NULL,
            sub_recipe_id BIGINT UNSIGNED NULL,
            line_type ENUM('ingredient','packaging','sub_recipe','modifier_ingredient','labor_placeholder') NOT NULL DEFAULT 'ingredient',
            ingredient_item_type_snapshot VARCHAR(64) NULL,
            qty_per_yield DECIMAL(18,6) NOT NULL,
            unit_id BIGINT UNSIGNED NULL,
            unit_conversion_to_base DECIMAL(18,8) NOT NULL DEFAULT 1.00000000,
            wastage_percent DECIMAL(9,4) NOT NULL DEFAULT 0.0000,
            is_required TINYINT(1) NOT NULL DEFAULT 1,
            modifier_group_id BIGINT UNSIGNED NULL,
            modifier_option_id BIGINT UNSIGNED NULL,
            modifier_behavior ENUM('additive','substitution_remove','substitution_add') NOT NULL DEFAULT 'additive',
            substitution_group VARCHAR(64) NULL,
            order_type ENUM('any','dine_in','takeaway','delivery') NOT NULL DEFAULT 'any',
            channel ENUM('any','pos','table','moova','cofe','api') NOT NULL DEFAULT 'any',
            sort_order INT NOT NULL DEFAULT 0,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_recipe_line_uuid (line_uuid),
            KEY idx_recipe_lines_recipe (recipe_id, sort_order),
            KEY idx_recipe_lines_ingredient (ingredient_item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function recipeAffectedItemCostAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
