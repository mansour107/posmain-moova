<?php

$root = dirname(__DIR__, 2);
$tool = inventoryProductionReadinessContractSource($root . '/tools/inventory_production_readiness.php');
$roadmap = inventoryProductionReadinessContractSource($root . '/docs/inventory/roadmap_status.md');

foreach ([
    'inventory_legacy_retirement_check.php',
    'inventory_cutover_readiness.php',
    'inventory_operational_health_check.php',
    'recipe_runtime_preflight.php',
    'browser_operator_qa',
    'browser_operator_qa_evidence_missing',
    'production_ready',
    'ledger_and_balance_cutover',
    'legacy_stock_retirement',
    'operational_hardening',
    'recipe_runtime',
    '--browser-evidence',
    '--decisions-file',
    '--acceptance-file',
    '--allow-accepted-reconciliation',
    '--accounting-acceptance-file',
    'inventoryProductionReadinessCutoverArgs',
    'inventory_ledger_mode_not_live_yet',
    "(string) (\$payload['mode'] ?? '') === 'live'",
] as $needle) {
    inventoryProductionReadinessContractAssert(strpos($tool, $needle) !== false, 'production readiness tool should contain: ' . $needle);
}

inventoryProductionReadinessContractAssert(
    !preg_match('/\b(INSERT\s+INTO|UPDATE\s+[A-Za-z_`]+|DELETE\s+FROM|DROP\s+TABLE|DROP\s+DATABASE|TRUNCATE)\b/i', $tool),
    'production readiness aggregate tool must remain read-only'
);

foreach ([
    'php tools/inventory_production_readiness.php --json',
    'Production readiness aggregate',
    'browser_operator_qa_evidence_missing',
    '`--decisions-file`',
    '`--accounting-acceptance-file`',
    '`POSMAIN_INVENTORY_LEDGER_MODE` is not `live`',
    'Do not claim production-ready Foodics-level inventory',
] as $needle) {
    inventoryProductionReadinessContractAssert(strpos($roadmap, $needle) !== false, 'roadmap status should name production readiness gate: ' . $needle);
}

$runtimeJson = shell_exec('cd ' . escapeshellarg($root) . ' && php tools/inventory_production_readiness.php --json 2>/dev/null');
inventoryProductionReadinessContractAssert(is_string($runtimeJson) && trim($runtimeJson) !== '', 'production readiness tool should produce JSON');
$runtime = json_decode((string) $runtimeJson, true);
inventoryProductionReadinessContractAssert(is_array($runtime), 'production readiness JSON should decode');
inventoryProductionReadinessContractAssert(array_key_exists('production_ready', $runtime), 'production readiness JSON should expose production_ready');
inventoryProductionReadinessContractAssert(empty($runtime['production_ready']), 'production readiness should not pass without browser evidence and live gates');

foreach ([
    'legacy_retirement',
    'cutover',
    'operational_health',
    'recipe_runtime',
    'browser_operator_qa',
] as $check) {
    inventoryProductionReadinessContractAssert(isset($runtime['checks'][$check]), 'production readiness should include check: ' . $check);
}

$blockers = $runtime['blockers'] ?? [];
inventoryProductionReadinessContractAssert(
    in_array('browser_operator_qa_not_ready', $blockers, true)
        || in_array('browser_operator_qa:browser_operator_qa_evidence_missing', $blockers, true),
    'production readiness should block when browser/operator QA evidence is missing'
);

echo "inventory-production-readiness-tool-contract-ok\n";

function inventoryProductionReadinessContractSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function inventoryProductionReadinessContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
