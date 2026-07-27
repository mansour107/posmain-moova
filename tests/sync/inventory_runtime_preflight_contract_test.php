<?php

$root = dirname(__DIR__, 2);
$service = inventoryRuntimePreflightContractSource($root . '/classes/Inventory/InventoryRuntimePreflightService.php');
$tool = inventoryRuntimePreflightContractSource($root . '/tools/inventory_runtime_preflight.php');
$production = inventoryRuntimePreflightContractSource($root . '/tools/inventory_production_readiness.php');

foreach ([
    'inventory_runtime_schema_missing_tables',
    'inventory_runtime_schema_pending_migrations',
    'inventory_runtime_live_requires_accounting',
    'inventory_runtime_live_requires_reservations',
    'inventory_runtime_live_requires_availability',
    'inventory_runtime_live_legacy_mirror_must_be_disabled',
    'inventory_runtime_account_missing_or_inactive:',
    'document_counters',
    'sync_outbox',
] as $needle) {
    inventoryRuntimePreflightContractAssert(
        strpos($service, $needle) !== false,
        'runtime preflight should preserve blocker or required surface: ' . $needle
    );
}

foreach ([
    'inventory_runtime_preflight_database_unreachable',
    'catch (Throwable $exception)',
    "'ok' => false",
] as $needle) {
    inventoryRuntimePreflightContractAssert(
        strpos($tool, $needle) !== false,
        'runtime preflight CLI should fail closed with JSON: ' . $needle
    );
}

inventoryRuntimePreflightContractAssert(
    strpos($production, "'runtime_activation'") !== false
        && strpos($production, 'tools/inventory_runtime_preflight.php') !== false,
    'aggregate production readiness must require the runtime activation preflight'
);
inventoryRuntimePreflightContractAssert(
    !preg_match('/\b(INSERT\s+INTO|UPDATE\s+[A-Za-z_`]+|DELETE\s+FROM|DROP\s+TABLE|DROP\s+DATABASE|TRUNCATE)\b/i', $service . $tool),
    'runtime preflight must remain read-only'
);

$runtimeJson = shell_exec('cd ' . escapeshellarg($root) . ' && php tools/inventory_runtime_preflight.php --json 2>/dev/null');
inventoryRuntimePreflightContractAssert(is_string($runtimeJson) && trim($runtimeJson) !== '', 'runtime preflight should always emit JSON');
$runtime = json_decode((string) $runtimeJson, true);
inventoryRuntimePreflightContractAssert(is_array($runtime), 'runtime preflight JSON should decode');
inventoryRuntimePreflightContractAssert(array_key_exists('ok', $runtime), 'runtime preflight JSON should expose ok');

echo "inventory-runtime-preflight-contract-ok\n";

function inventoryRuntimePreflightContractSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function inventoryRuntimePreflightContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
