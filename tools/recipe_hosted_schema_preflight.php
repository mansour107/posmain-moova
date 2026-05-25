<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Recipe/RecipeFeatureFlags.php';
require_once __DIR__ . '/../classes/Recipe/RecipeRuntimePreflightService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', [
    'json',
    'help',
    'shop-id::',
    'include-inactive',
    'current-db-only',
    'limit::',
]);

if (isset($options['help'])) {
    recipeHostedSchemaPreflightUsage();
    exit(0);
}

$result = recipeHostedSchemaPreflight($options, posmain_app_config());

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    recipeHostedSchemaPreflightPrintHuman($result);
}

exit(!empty($result['ready_for_hosted_recipe_schema']) ? 0 : 2);

function recipeHostedSchemaPreflightUsage(): void
{
    fwrite(STDOUT, "Usage: php tools/recipe_hosted_schema_preflight.php [--json] [--current-db-only] [--shop-id=1] [--include-inactive] [--limit=100]\n");
    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "Checks recipe schema/runtime preflight for hosted or single-domain routed shop databases.\n");
    fwrite(STDOUT, "When POSMAIN_ROUTER_ENABLED=1, active router shops are checked unless --current-db-only or --shop-id is supplied. When router mode is off, the current configured DB is checked.\n");
    fwrite(STDOUT, "This command is read-only: it does not install router tables, validate shops, apply migrations, change flags, write recipe rows, write stock, post accounting, update router metadata, or enqueue sync.\n");
}

function recipeHostedSchemaPreflight(array $options, array $config): array
{
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $flags = new RecipeFeatureFlags($config);
    $service = new RecipeRuntimePreflightService(dirname(__DIR__));
    $routerEnabled = posmain_router_enabled($config);
    $currentDbOnly = !empty($options['current-db-only']);
    $shopId = isset($options['shop-id']) && $options['shop-id'] !== ''
        ? max(0, (int) $options['shop-id'])
        : 0;
    $limit = max(1, min(500, (int) ($options['limit'] ?? 100)));
    $checkedAt = gmdate('Y-m-d\TH:i:s\Z');
    $targets = [];
    $blockers = [];
    $warnings = [];

    if (!$routerEnabled || $currentDbOnly) {
        $targets[] = recipeHostedSchemaPreflightCurrentDbTarget($service, $flags);
    } else {
        $routerTargets = recipeHostedSchemaPreflightRouterTargets($service, $flags, $config, $shopId, !empty($options['include-inactive']), $limit);
        $targets = $routerTargets['targets'];
        foreach ($routerTargets['blockers'] as $blocker) {
            $blockers[] = $blocker;
        }
        foreach ($routerTargets['warnings'] as $warning) {
            $warnings[] = $warning;
        }
    }

    if ($targets === []) {
        $blockers[] = 'recipe_hosted_schema_no_targets';
    }

    foreach ($targets as $target) {
        foreach ($target['blockers'] ?? [] as $blocker) {
            $blockers[] = 'recipe_hosted_schema_' . $target['target_key'] . '_' . $blocker;
        }
        foreach ($target['warnings'] ?? [] as $warning) {
            $warnings[] = 'recipe_hosted_schema_' . $target['target_key'] . '_' . $warning;
        }
    }

    $blockers = array_values(array_unique($blockers));
    $warnings = array_values(array_diff(array_unique($warnings), $blockers));

    return [
        'ok' => $blockers === [],
        'ready_for_hosted_recipe_schema' => $blockers === [],
        'checked_at_utc' => $checkedAt,
        'router_enabled' => $routerEnabled,
        'current_db_only' => $currentDbOnly,
        'target_count' => count($targets),
        'targets' => $targets,
        'hosted_schema_evidence_line' => recipeHostedSchemaPreflightEvidenceLine($checkedAt, $targets, $blockers),
        'blockers' => $blockers,
        'warnings' => $warnings,
    ];
}

function recipeHostedSchemaPreflightCurrentDbTarget(RecipeRuntimePreflightService $service, RecipeFeatureFlags $flags): array
{
    try {
        $conn = posmain_db_connect();
        try {
            $dbName = recipeHostedSchemaPreflightDatabaseName($conn);
            $preflight = $service->check($conn, $flags);
        } finally {
            $conn->close();
        }

        return recipeHostedSchemaPreflightTargetResult(
            'current_db',
            'current_db',
            ['db_name' => $dbName],
            $preflight
        );
    } catch (Throwable $exception) {
        return recipeHostedSchemaPreflightTargetError('current_db', 'current_db', [], $exception);
    }
}

function recipeHostedSchemaPreflightRouterTargets(
    RecipeRuntimePreflightService $service,
    RecipeFeatureFlags $flags,
    array $config,
    int $shopId,
    bool $includeInactive,
    int $limit
): array {
    $router = new PosmainShopRouter();
    $targets = [];
    $blockers = [];
    $warnings = [];

    try {
        $routerConn = posmain_router_db_connect($config);
    } catch (Throwable $exception) {
        return [
            'targets' => [],
            'blockers' => ['recipe_hosted_schema_router_database_unreachable'],
            'warnings' => [],
        ];
    }

    try {
        $shops = recipeHostedSchemaPreflightRouterShops($routerConn, $shopId, $includeInactive, $limit);
        if ($shops === []) {
            $blockers[] = $shopId > 0
                ? 'recipe_hosted_schema_router_shop_not_found'
                : 'recipe_hosted_schema_router_no_shops';
        }

        foreach ($shops as $shop) {
            $publicShop = $router->publicShop($shop);
            $targetKey = 'shop_' . (int) ($shop['id'] ?? 0);
            try {
                if (($shop['status'] ?? '') !== 'active') {
                    throw new RuntimeException('Shop is not active.');
                }

                $conn = $router->connectShopFromRoute($shop);
                try {
                    $dbName = recipeHostedSchemaPreflightDatabaseName($conn);
                    $preflight = $service->check($conn, $flags);
                } finally {
                    $conn->close();
                }

                $targets[] = recipeHostedSchemaPreflightTargetResult(
                    $targetKey,
                    'router_shop',
                    [
                        'shop' => $publicShop,
                        'db_name' => $dbName,
                    ],
                    $preflight
                );
            } catch (Throwable $exception) {
                $targets[] = recipeHostedSchemaPreflightTargetError($targetKey, 'router_shop', ['shop' => $publicShop], $exception);
            }
        }
    } catch (Throwable $exception) {
        $blockers[] = 'recipe_hosted_schema_router_query_failed';
        $warnings[] = 'recipe_hosted_schema_router_query_error_' . recipeHostedSchemaPreflightErrorCode($exception);
    } finally {
        $routerConn->close();
    }

    return [
        'targets' => $targets,
        'blockers' => $blockers,
        'warnings' => $warnings,
    ];
}

function recipeHostedSchemaPreflightErrorCode(Throwable $exception): string
{
    $message = strtolower($exception->getMessage());
    if (strpos($message, 'router_shops') !== false) {
        return 'router_shops_missing';
    }
    if (strpos($message, 'access denied') !== false) {
        return 'access_denied';
    }

    return 'unclassified';
}

function recipeHostedSchemaPreflightRouterShops(mysqli $routerConn, int $shopId, bool $includeInactive, int $limit): array
{
    $where = [];
    $params = [];
    $types = '';

    if ($shopId > 0) {
        $where[] = 'id = ?';
        $params[] = $shopId;
        $types .= 'i';
    }
    if (!$includeInactive) {
        $where[] = "status = 'active'";
    }

    $sql = 'SELECT * FROM router_shops';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY id ASC LIMIT ?';
    $params[] = $limit;
    $types .= 'i';

    $stmt = $routerConn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $shops = [];
    while ($row = $result->fetch_assoc()) {
        $shops[] = $row;
    }
    $stmt->close();

    return $shops;
}

function recipeHostedSchemaPreflightTargetResult(string $targetKey, string $targetType, array $meta, array $preflight): array
{
    $schema = $preflight['checks']['schema'] ?? [];
    $blockers = array_values(array_unique(array_map('strval', $preflight['blockers'] ?? [])));
    $warnings = array_values(array_unique(array_map('strval', $preflight['warnings'] ?? [])));

    return array_merge([
        'target_key' => $targetKey,
        'target_type' => $targetType,
        'ok' => !empty($preflight['ready_for_recipe_operator_qa']),
        'mode' => (string) ($preflight['mode'] ?? 'unknown'),
        'pending_schema_changes' => (int) ($schema['pending_count'] ?? 0),
        'missing_recipe_tables' => array_values(array_map('strval', $schema['missing_recipe_tables'] ?? [])),
        'blockers' => $blockers,
        'warnings' => $warnings,
    ], $meta);
}

function recipeHostedSchemaPreflightTargetError(string $targetKey, string $targetType, array $meta, Throwable $exception): array
{
    return array_merge([
        'target_key' => $targetKey,
        'target_type' => $targetType,
        'ok' => false,
        'mode' => 'unknown',
        'pending_schema_changes' => null,
        'missing_recipe_tables' => [],
        'error' => 'database_unreachable',
        'message' => $exception->getMessage(),
        'blockers' => ['database_unreachable'],
        'warnings' => [],
    ], $meta);
}

function recipeHostedSchemaPreflightDatabaseName(mysqli $conn): string
{
    $result = $conn->query('SELECT DATABASE() AS db_name');
    $row = $result ? $result->fetch_assoc() : [];

    return (string) ($row['db_name'] ?? '');
}

function recipeHostedSchemaPreflightEvidenceLine(string $checkedAt, array $targets, array $blockers): string
{
    $readyTargets = 0;
    foreach ($targets as $target) {
        if (!empty($target['ok'])) {
            $readyTargets++;
        }
    }

    $status = $blockers === [] ? 'ready' : 'not_ready';

    return sprintf(
        'Hosted/cloud runtime schema evidence: tools/recipe_hosted_schema_preflight.php checked %d target(s), %d ready, status=%s at %s',
        count($targets),
        $readyTargets,
        $status,
        $checkedAt
    );
}

function recipeHostedSchemaPreflightPrintHuman(array $result): void
{
    fwrite(STDOUT, 'Recipe hosted schema preflight: ' . (!empty($result['ready_for_hosted_recipe_schema']) ? 'READY' : 'NOT READY') . PHP_EOL);
    fwrite(STDOUT, '- router enabled: ' . (!empty($result['router_enabled']) ? 'yes' : 'no') . PHP_EOL);
    fwrite(STDOUT, '- targets checked: ' . (int) ($result['target_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- evidence: ' . (string) ($result['hosted_schema_evidence_line'] ?? '') . PHP_EOL);

    foreach (($result['targets'] ?? []) as $target) {
        fwrite(STDOUT, '  - ' . (string) ($target['target_key'] ?? '') . ': ' . (!empty($target['ok']) ? 'ready' : 'not ready') . ', pending=' . (string) ($target['pending_schema_changes'] ?? 'n/a') . ', missing_tables=' . count($target['missing_recipe_tables'] ?? []) . PHP_EOL);
    }

    if (!empty($result['blockers'])) {
        fwrite(STDOUT, "- blockers:\n");
        foreach ($result['blockers'] as $blocker) {
            fwrite(STDOUT, '  - ' . (string) $blocker . PHP_EOL);
        }
    }

    if (!empty($result['warnings'])) {
        fwrite(STDOUT, "- warnings:\n");
        foreach ($result['warnings'] as $warning) {
            fwrite(STDOUT, '  - ' . (string) $warning . PHP_EOL);
        }
    }
}
