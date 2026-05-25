<?php

$root = dirname(__DIR__, 2);
$toolPath = $root . '/tools/recipe_paid_reversal_surface_smoke.php';
$tool = recipePaidReversalSurfaceSmokeSource($toolPath);
$preflight = recipePaidReversalSurfaceSmokeSource($root . '/classes/Recipe/RecipeRuntimePreflightService.php');
$doc = recipePaidReversalSurfaceSmokeSource($root . '/docs/recipe/rollout_readiness.md');

exec('php ' . escapeshellarg($toolPath) . ' --help', $helpLines, $helpCode);
$help = implode("\n", $helpLines);

recipePaidReversalSurfaceSmokeAssert($helpCode === 0, 'paid reversal surface smoke help should exit cleanly');
recipePaidReversalSurfaceSmokeAssert(strpos($help, 'read-only authenticated GET smoke') !== false, 'help should describe authenticated GET smoke');
recipePaidReversalSurfaceSmokeAssert(strpos($help, 'paid refund/void POS surface') !== false, 'help should describe paid refund/void scope');
recipePaidReversalSurfaceSmokeAssert(strpos($help, 'does not log in') !== false, 'help should state that it does not log in');
recipePaidReversalSurfaceSmokeAssert(strpos($help, 'curl/Netscape cookie jar') !== false, 'help should document cookie jar input');
recipePaidReversalSurfaceSmokeAssert(strpos($help, 'refund endpoint method guard') !== false, 'help should document method-guard check');
recipePaidReversalSurfaceSmokeAssert(strpos($help, 'POS barcode gate') !== false, 'help should document cashier unlock warning');
recipePaidReversalSurfaceSmokeAssert(strpos($help, 'does not click buttons') !== false, 'help should distinguish browser QA limits');
recipePaidReversalSurfaceSmokeAssert(strpos($help, 'issue mutations') !== false, 'help should state that it does not issue mutations');

foreach ([
    'pos_barcode.php',
    'ajax/get_recent_orders.php',
    'ajax/refund_order.php',
    'reversePaidOrder',
    'refund_stock_policy',
    'paid-reversal-policy',
    'createPOSIdempotencyKey',
    'can_refund',
    'can_void',
    'payment_status',
    'order_status',
    'METHOD_NOT_ALLOWED',
    'pos_barcode_gate_requires_operator_unlock',
    'pos_barcode_gate_detected',
    'ui_snippets_checked',
] as $needle) {
    recipePaidReversalSurfaceSmokeAssert(strpos($tool, $needle) !== false, 'tool should check paid reversal surface: ' . $needle);
}

foreach ([
    'recipePaidReversalSurfaceSmokeLoginDetected',
    'recipePaidReversalSurfaceSmokePosBarcodeGateDetected',
    'recipePaidReversalSurfaceSmokeAccessDenied',
    'recipePaidReversalSurfaceSmokeFatalText',
    'recipePaidReversalSurfaceSmokeNormalizeCookieSource',
    '#HttpOnly_',
    'recent_orders_empty_capability_shape_not_observed',
    'no_paid_reversible_order_in_recent_orders',
    'requires_authenticated_session_cookie',
    'method_guard_payload_missing',
    'capability_fields_missing',
] as $needle) {
    recipePaidReversalSurfaceSmokeAssert(strpos($tool, $needle) !== false, 'tool should expose smoke guard: ' . $needle);
}

foreach ([
    "'method' => 'GET'",
    'CURLOPT_HTTPGET',
] as $needle) {
    recipePaidReversalSurfaceSmokeAssert(strpos($tool, $needle) !== false, 'tool should only use GET requests: ' . $needle);
}

foreach (['CURLOPT_POST', 'INSERT INTO', 'UPDATE ', 'DELETE FROM', 'RecipeInventoryMovementService', 'RecipeAccountingService', 'SyncOutboxEventService', 'recordRecipeConsumption', 'recordRefundReversal'] as $needle) {
    recipePaidReversalSurfaceSmokeAssert(strpos($tool, $needle) === false, 'tool must stay read-only and avoid: ' . $needle);
}

recipePaidReversalSurfaceSmokeAssert(strpos($preflight, 'tools/recipe_paid_reversal_surface_smoke.php') !== false, 'runtime preflight should require paid reversal smoke tool');
recipePaidReversalSurfaceSmokeAssert(strpos($doc, 'tools/recipe_paid_reversal_surface_smoke.php') !== false, 'rollout doc should document paid reversal smoke command');
recipePaidReversalSurfaceSmokeAssert(strpos($doc, 'paid refund/void POS surface') !== false, 'rollout doc should describe paid reversal smoke scope');
recipePaidReversalSurfaceSmokeAssert(strpos($doc, 'operator-unlock warning') !== false, 'rollout doc should describe POS barcode gate warning');
recipePaidReversalSurfaceSmokeAssert(strpos($doc, 'click buttons, confirm dialogs, issue mutations') !== false, 'rollout doc should document non-mutating paid reversal scope');

echo "recipe-paid-reversal-surface-smoke-contract-ok\n";

function recipePaidReversalSurfaceSmokeSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function recipePaidReversalSurfaceSmokeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
