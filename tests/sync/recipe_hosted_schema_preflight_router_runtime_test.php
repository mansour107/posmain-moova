<?php

$rootPath = dirname(__DIR__, 2);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$suffix = (string) getmypid();
$routerDb = 'posmain_recipe_router_rt_' . $suffix;
$shopADb = 'posmain_recipe_shop_a_' . $suffix;
$shopBDb = 'posmain_recipe_shop_b_' . $suffix;
$key = 'recipe-router-runtime-test-key-' . $suffix;

require_once $rootPath . '/classes/Sync/SchemaManager.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root = new mysqli($host, $user, $pass, '', $port);

try {
    foreach ([$routerDb, $shopADb, $shopBDb] as $dbName) {
        $root->query("DROP DATABASE IF EXISTS `{$dbName}`");
        $root->query("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    }

    recipeHostedSchemaRouterRuntimeApplySchema($host, $port, $user, $pass, $shopADb);
    recipeHostedSchemaRouterRuntimeApplySchema($host, $port, $user, $pass, $shopBDb);

    $env = [
        'POSMAIN_CONFIG_ENCRYPTION_KEY' => $key,
        'POSMAIN_ROUTER_ENABLED' => '1',
        'POSMAIN_ROUTER_REQUIRE_ENCRYPTION' => '1',
        'POSMAIN_ROUTER_DB_HOST' => $host,
        'POSMAIN_ROUTER_DB_PORT' => (string) $port,
        'POSMAIN_ROUTER_DB_NAME' => $routerDb,
        'POSMAIN_ROUTER_DB_USER' => $user,
        'POSMAIN_ROUTER_DB_PASS' => $pass,
        'POSMAIN_ROUTER_DB_CHARSET' => 'utf8mb4',
    ];

    recipeHostedSchemaRouterRuntimeRun(
        $rootPath . '/tools/shop_router.php',
        ['--install', '--json'],
        $env
    );
    recipeHostedSchemaRouterRuntimeRun(
        $rootPath . '/tools/shop_router.php',
        [
            '--register-shop',
            '--slug=recipe-alpha',
            '--name=Recipe Alpha',
            '--db-host=' . $host,
            '--db-port=' . (string) $port,
            '--db-name=' . $shopADb,
            '--db-user=' . $user,
            '--db-pass=' . $pass,
            '--json',
        ],
        $env
    );
    recipeHostedSchemaRouterRuntimeRun(
        $rootPath . '/tools/shop_router.php',
        [
            '--register-shop',
            '--slug=recipe-beta',
            '--name=Recipe Beta',
            '--db-host=' . $host,
            '--db-port=' . (string) $port,
            '--db-name=' . $shopBDb,
            '--db-user=' . $user,
            '--db-pass=' . $pass,
            '--json',
        ],
        $env
    );

    $output = recipeHostedSchemaRouterRuntimeRun(
        $rootPath . '/tools/recipe_hosted_schema_preflight.php',
        ['--json'],
        $env
    );
    $payload = json_decode($output, true);
    recipeHostedSchemaRouterRuntimeAssert(is_array($payload), 'hosted schema router preflight should emit JSON');
    recipeHostedSchemaRouterRuntimeAssert(($payload['ready_for_hosted_recipe_schema'] ?? false) === true, 'routed hosted schema preflight should be ready');
    recipeHostedSchemaRouterRuntimeAssert(($payload['router_enabled'] ?? false) === true, 'routed hosted schema preflight should report router enabled');
    recipeHostedSchemaRouterRuntimeAssert((int) ($payload['target_count'] ?? 0) === 2, 'routed hosted schema preflight should check two shop targets');
    recipeHostedSchemaRouterRuntimeAssert(($payload['blockers'] ?? []) === [], 'routed hosted schema preflight should have no blockers');
    recipeHostedSchemaRouterRuntimeAssert(strpos((string) ($payload['hosted_schema_evidence_line'] ?? ''), '2 target(s), 2 ready, status=ready') !== false, 'evidence line should summarize two ready routed shops');

    $targetDbs = [];
    foreach (($payload['targets'] ?? []) as $target) {
        recipeHostedSchemaRouterRuntimeAssert(($target['target_type'] ?? '') === 'router_shop', 'target should be a routed shop');
        recipeHostedSchemaRouterRuntimeAssert(($target['ok'] ?? false) === true, 'routed shop target should be ready');
        recipeHostedSchemaRouterRuntimeAssert((int) ($target['pending_schema_changes'] ?? -1) === 0, 'routed shop target should have no pending schema changes');
        recipeHostedSchemaRouterRuntimeAssert(($target['missing_recipe_tables'] ?? []) === [], 'routed shop target should have all recipe tables');
        $targetDbs[] = (string) ($target['db_name'] ?? '');
    }
    sort($targetDbs);
    $expectedDbs = [$shopADb, $shopBDb];
    sort($expectedDbs);
    recipeHostedSchemaRouterRuntimeAssert($targetDbs === $expectedDbs, 'routed shop DB names should match the temporary shop databases');

    echo "recipe-hosted-schema-preflight-router-runtime-ok targets=2 router={$routerDb}\n";
} finally {
    foreach ([$routerDb, $shopADb, $shopBDb] as $dbName) {
        $root->query("DROP DATABASE IF EXISTS `{$dbName}`");
    }
    $root->close();
}

function recipeHostedSchemaRouterRuntimeApplySchema(string $host, int $port, string $user, string $pass, string $dbName): void
{
    $conn = new mysqli($host, $user, $pass, $dbName, $port);
    try {
        (new SyncSchemaManager())->apply($conn);
    } finally {
        $conn->close();
    }
}

function recipeHostedSchemaRouterRuntimeRun(string $script, array $args, array $env): string
{
    $prefix = [];
    foreach ($env as $key => $value) {
        $prefix[] = $key . '=' . escapeshellarg((string) $value);
    }
    $cmd = implode(' ', $prefix)
        . ' '
        . escapeshellarg(PHP_BINARY)
        . ' '
        . escapeshellarg($script);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }

    exec($cmd . ' 2>&1', $lines, $code);
    $output = implode("\n", $lines);
    recipeHostedSchemaRouterRuntimeAssert($code === 0, 'command failed: ' . $cmd . "\n" . $output);

    return $output;
}

function recipeHostedSchemaRouterRuntimeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
