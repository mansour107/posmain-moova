<?php

require_once dirname(__DIR__, 2) . '/classes/Sync/SchemaManager.php';
require_once dirname(__DIR__, 2) . '/classes/Recipe/DTO/RecipeOrderLineContext.php';
require_once dirname(__DIR__, 2) . '/classes/Recipe/RecipeExplosionService.php';
require_once dirname(__DIR__, 2) . '/classes/Recipe/RecipeFeatureFlags.php';

if (($argv[1] ?? '') === '--child') {
    recipeModifierSubstitutionManagementEndpointRuntimeChild($argv[2] ?? '');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_recipe_manage_substitution_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    recipeModifierSubstitutionManagementEndpointRuntimeCreateBaseSchema($conn);
    (new SyncSchemaManager())->apply($conn);
    recipeModifierSubstitutionManagementEndpointRuntimeSeedBaseRows($conn);

    recipeModifierSubstitutionManagementEndpointRuntimeRunChild($db, [
        'action' => 'create_draft',
        'sellable_item_id' => 2001,
        'recipe_name' => 'Endpoint Managed Latte',
        'recipe_type' => 'make_to_order',
        'yield_qty' => '1.000000',
        'default_wastage_percent' => '0.0000',
        'costing_method' => 'item_cost_price',
    ]);

    $recipe = recipeModifierSubstitutionManagementEndpointRuntimeOne(
        $conn,
        "SELECT * FROM recipe_headers WHERE sellable_item_id = 2001 AND recipe_name = 'Endpoint Managed Latte' LIMIT 1"
    );
    recipeModifierSubstitutionManagementEndpointRuntimeAssert($recipe !== null, 'recipe_manage.php should create the draft recipe');
    recipeModifierSubstitutionManagementEndpointRuntimeAssert($recipe['status'] === 'draft', 'created recipe should start as draft');
    recipeModifierSubstitutionManagementEndpointRuntimeAssert((int) $recipe['created_by'] === 1, 'created recipe should stamp actor user');
    $recipeId = (int) $recipe['id'];

    recipeModifierSubstitutionManagementEndpointRuntimeRunChild($db, [
        'action' => 'add_line',
        'recipe_id' => $recipeId,
        'line_type' => 'ingredient',
        'ingredient_item_id' => 3001,
        'qty_per_yield' => '0.250000',
        'unit_conversion_to_base' => '1.00000000',
        'wastage_percent' => '0.0000',
        'is_required' => '1',
        'order_type' => 'any',
        'channel' => 'any',
        'substitution_group' => 'milk',
        'sort_order' => 1,
        'notes' => 'base regular milk',
    ]);
    recipeModifierSubstitutionManagementEndpointRuntimeRunChild($db, [
        'action' => 'add_line',
        'recipe_id' => $recipeId,
        'line_type' => 'modifier_ingredient',
        'ingredient_item_id' => 3001,
        'qty_per_yield' => '0.250000',
        'unit_conversion_to_base' => '1.00000000',
        'wastage_percent' => '0.0000',
        'is_required' => '1',
        'modifier_group_id' => 11,
        'modifier_option_id' => 77,
        'modifier_behavior' => 'substitution_remove',
        'substitution_group' => 'milk',
        'order_type' => 'any',
        'channel' => 'any',
        'sort_order' => 2,
        'notes' => 'remove regular milk when oat is selected',
    ]);
    recipeModifierSubstitutionManagementEndpointRuntimeRunChild($db, [
        'action' => 'add_line',
        'recipe_id' => $recipeId,
        'line_type' => 'modifier_ingredient',
        'ingredient_item_id' => 3002,
        'qty_per_yield' => '0.250000',
        'unit_conversion_to_base' => '1.00000000',
        'wastage_percent' => '0.0000',
        'is_required' => '1',
        'modifier_group_id' => 11,
        'modifier_option_id' => 77,
        'modifier_behavior' => 'substitution_add',
        'substitution_group' => 'milk',
        'order_type' => 'any',
        'channel' => 'any',
        'sort_order' => 3,
        'notes' => 'add oat milk when selected',
    ]);

    recipeModifierSubstitutionManagementEndpointRuntimeRunChild($db, [
        'action' => 'approve',
        'recipe_id' => $recipeId,
    ]);
    recipeModifierSubstitutionManagementEndpointRuntimeRunChild($db, [
        'action' => 'activate',
        'recipe_id' => $recipeId,
    ]);

    $active = recipeModifierSubstitutionManagementEndpointRuntimeOne($conn, "SELECT * FROM recipe_headers WHERE id = {$recipeId}");
    recipeModifierSubstitutionManagementEndpointRuntimeAssert($active !== null, 'recipe should still exist after activation');
    recipeModifierSubstitutionManagementEndpointRuntimeAssert($active['status'] === 'active', 'recipe_manage.php should activate the managed recipe');
    recipeModifierSubstitutionManagementEndpointRuntimeAssert((int) $active['approved_by'] === 1, 'approval/activation should stamp actor user');

    $lines = recipeModifierSubstitutionManagementEndpointRuntimeRows(
        $conn,
        "SELECT * FROM recipe_lines WHERE recipe_id = {$recipeId} ORDER BY sort_order, id"
    );
    recipeModifierSubstitutionManagementEndpointRuntimeAssert(count($lines) === 3, 'management endpoint should persist three substitution recipe lines');
    recipeModifierSubstitutionManagementEndpointRuntimeAssert($lines[0]['line_type'] === 'ingredient', 'first line should be the base ingredient');
    recipeModifierSubstitutionManagementEndpointRuntimeAssert($lines[0]['substitution_group'] === 'milk', 'base ingredient should retain substitution group');
    recipeModifierSubstitutionManagementEndpointRuntimeAssert($lines[1]['modifier_behavior'] === 'substitution_remove', 'second line should persist substitution removal behavior');
    recipeModifierSubstitutionManagementEndpointRuntimeAssert($lines[1]['substitution_group'] === 'milk', 'removal line should retain substitution group');
    recipeModifierSubstitutionManagementEndpointRuntimeAssert($lines[2]['modifier_behavior'] === 'substitution_add', 'third line should persist substitution add behavior');
    recipeModifierSubstitutionManagementEndpointRuntimeAssert($lines[2]['substitution_group'] === 'milk', 'add line should retain substitution group');

    $explosion = new RecipeExplosionService(new RecipeFeatureFlags([
        'recipe' => [
            'enabled' => true,
            'mode' => 'read_only',
        ],
    ]));
    $regular = $explosion->explodeOrderLine($conn, new RecipeOrderLineContext([
        'sellable_item_id' => 2001,
        'quantity' => '2.000000',
        'order_type' => 'takeaway',
        'channel' => 'pos',
    ]));
    $oat = $explosion->explodeOrderLine($conn, new RecipeOrderLineContext([
        'sellable_item_id' => 2001,
        'quantity' => '2.000000',
        'order_type' => 'takeaway',
        'channel' => 'pos',
        'modifiers' => [
            ['modifier_group_id' => 11, 'modifier_option_id' => 77],
        ],
    ]));

    recipeModifierSubstitutionManagementEndpointRuntimeAssert(count($regular->requirements) === 1, 'regular order should have one ingredient requirement');
    recipeModifierSubstitutionManagementEndpointRuntimeAssert($regular->requirements[0]->ingredientItemId === 3001, 'regular order should require regular milk');
    recipeModifierSubstitutionManagementEndpointRuntimeAssert($regular->requirements[0]->requiredQtyBase === '0.500000', 'regular order should scale regular milk quantity');
    recipeModifierSubstitutionManagementEndpointRuntimeAssert(count($oat->requirements) === 1, 'substituted order should have one ingredient requirement');
    recipeModifierSubstitutionManagementEndpointRuntimeAssert($oat->requirements[0]->ingredientItemId === 3002, 'substituted order should remove regular milk and require oat milk');
    recipeModifierSubstitutionManagementEndpointRuntimeAssert($oat->requirements[0]->requiredQtyBase === '0.500000', 'substituted order should scale oat milk quantity');
    recipeModifierSubstitutionManagementEndpointRuntimeAssert($oat->warnings === [], 'substitution explosion should not emit warnings');

    recipeModifierSubstitutionManagementEndpointRuntimeAssert(
        recipeModifierSubstitutionManagementEndpointRuntimeCount($conn, "SELECT COUNT(*) AS c FROM recipe_audit_log WHERE recipe_id = {$recipeId}") >= 6,
        'management endpoint should audit create, lines, approve, and activate'
    );

    echo "recipe-modifier-substitution-management-endpoint-runtime-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function recipeModifierSubstitutionManagementEndpointRuntimeChild(string $json): void
{
    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        fwrite(STDERR, "Invalid child payload.\n");
        exit(9);
    }

    $csrf = 'recipe-manage-substitution-csrf-fixed';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['PHP_SELF'] = 'recipe_manage.php';
    $_SERVER['SCRIPT_NAME'] = 'recipe_manage.php';
    $_SERVER['HTTP_ACCEPT'] = 'text/html';
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $csrf;
    $_SERVER['HTTP_X_POSMAIN_CSRF_TOKEN'] = $csrf;
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'recipe-manage-substitution-endpoint-runtime-test';
    $_GET = [];
    $_POST = array_merge($payload, [
        'csrf_token' => $csrf,
        'pos_tenant' => 0,
        'pos_branch' => 0,
    ]);

    session_id('recipemanage' . getmypid());
    require_once dirname(__DIR__, 2) . '/includes/session_bootstrap.php';
    $_SESSION['login'] = 'recipe_manage_substitution_smoke';
    $_SESSION['userid'] = 1;
    $_SESSION['user_id'] = 1;
    $_SESSION['usrole'] = 1;
    $_SESSION['userrole'] = 1;
    $_SESSION['usty'] = 2;
    $_SESSION['posmain_csrf_tokens'] = [
        'recipe_editor' => $csrf,
    ];

    chdir(dirname(__DIR__, 2));
    require dirname(__DIR__, 2) . '/recipe_manage.php';
    exit(0);
}

function recipeModifierSubstitutionManagementEndpointRuntimeRunChild(string $db, array $payload): void
{
    $sessionPath = '/private/tmp/posmain-recipe-manage-substitution-sessions-' . getmypid();
    if (!is_dir($sessionPath) && !mkdir($sessionPath, 0700, true) && !is_dir($sessionPath)) {
        throw new RuntimeException('Unable to create isolated session path.');
    }

    $env = array_merge($_ENV, [
        'POSMAIN_DB_HOST' => getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1',
        'POSMAIN_DB_PORT' => getenv('POSMAIN_TEST_MYSQL_PORT') ?: '3307',
        'POSMAIN_DB_USER' => getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root',
        'POSMAIN_DB_PASS' => getenv('POSMAIN_TEST_MYSQL_PASS') ?: '',
        'POSMAIN_DB_NAME' => $db,
        'POSMAIN_SESSION_DRIVER' => 'file',
        'POSMAIN_SESSION_SAVE_PATH' => $sessionPath,
        'POSMAIN_SYNC_OUTBOX_ENABLED' => '0',
        'POSMAIN_ENABLE_RECIPES' => '1',
        'POSMAIN_RECIPE_MODE' => 'shadow',
        'POSMAIN_RECIPE_ACCOUNTING' => '0',
        'POSMAIN_RECIPE_AVAILABILITY' => '0',
        'POSMAIN_ROUTER_ENABLED' => '0',
    ]);
    $command = [
        PHP_BINARY,
        __FILE__,
        '--child',
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, dirname(__DIR__, 2), $env);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start recipe management endpoint child process.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0 || trim((string) $stderr) !== '' || trim((string) $stdout) !== '') {
        throw new RuntimeException("Recipe management endpoint child failed with code {$exitCode}: {$stderr}\n{$stdout}");
    }
}

function recipeModifierSubstitutionManagementEndpointRuntimeCreateBaseSchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE settings (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            lang VARCHAR(20) NULL DEFAULT 'ar',
            edit_pass VARCHAR(191) NULL DEFAULT ''
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE towns (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tname VARCHAR(191) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE users (
            id INT NOT NULL PRIMARY KEY,
            uname VARCHAR(191) NOT NULL,
            password VARCHAR(255) NULL,
            userrole INT NULL,
            usertype INT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE usr_pwrs (
            id INT NOT NULL PRIMARY KEY,
            rollname VARCHAR(191) NULL,
            add_stock TINYINT(1) NOT NULL DEFAULT 0,
            edit_stock TINYINT(1) NOT NULL DEFAULT 0,
            add_items TINYINT(1) NOT NULL DEFAULT 0,
            edit_items TINYINT(1) NOT NULL DEFAULT 0,
            sid_accounts TINYINT(1) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE myitems (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            iname VARCHAR(191) NOT NULL,
            cost_price DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            group1 BIGINT UNSIGNED NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function recipeModifierSubstitutionManagementEndpointRuntimeSeedBaseRows(mysqli $conn): void
{
    $conn->query("INSERT INTO settings (lang, edit_pass) VALUES ('ar', '')");
    $conn->query("INSERT INTO towns (id, tname) VALUES (1, 'Main')");
    $conn->query("INSERT INTO users (id, uname, userrole, usertype, isdeleted) VALUES (1, 'admin', 1, 2, 0)");
    $conn->query("
        INSERT INTO usr_pwrs
            (id, rollname, add_stock, edit_stock, add_items, edit_items, sid_accounts, isdeleted)
        VALUES
            (1, 'Admin', 1, 1, 1, 1, 1, 0)
    ");
    $conn->query("
        INSERT INTO myitems (id, iname, cost_price, group1, isdeleted) VALUES
            (2001, 'Latte', 0.000000, 10, 0),
            (3001, 'Regular milk', 2.000000, 20, 0),
            (3002, 'Oat milk', 3.000000, 20, 0)
    ");
}

function recipeModifierSubstitutionManagementEndpointRuntimeOne(mysqli $conn, string $sql): ?array
{
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    $result->free();

    return $row ?: null;
}

function recipeModifierSubstitutionManagementEndpointRuntimeRows(mysqli $conn, string $sql): array
{
    $result = $conn->query($sql);
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();

    return $rows;
}

function recipeModifierSubstitutionManagementEndpointRuntimeCount(mysqli $conn, string $sql): int
{
    $row = recipeModifierSubstitutionManagementEndpointRuntimeOne($conn, $sql);

    return (int) ($row['c'] ?? 0);
}

function recipeModifierSubstitutionManagementEndpointRuntimeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
