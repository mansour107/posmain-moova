<?php

$root = dirname(__DIR__, 2);
$toolPath = $root . '/tools/recipe_operator_surface_smoke.php';
$tool = recipeOperatorSurfaceSmokeSource($toolPath);
$preflight = recipeOperatorSurfaceSmokeSource($root . '/classes/Recipe/RecipeRuntimePreflightService.php');
$doc = recipeOperatorSurfaceSmokeSource($root . '/docs/recipe/rollout_readiness.md');

exec('php ' . escapeshellarg($toolPath) . ' --help', $helpLines, $helpCode);
$help = implode("\n", $helpLines);

recipeOperatorSurfaceSmokeAssert($helpCode === 0, 'operator surface smoke help should exit cleanly');
recipeOperatorSurfaceSmokeAssert(strpos($help, 'authenticated GET smoke') !== false, 'help should describe authenticated GET smoke');
recipeOperatorSurfaceSmokeAssert(strpos($help, 'does not log in') !== false, 'help should state that it does not log in');
recipeOperatorSurfaceSmokeAssert(strpos($help, 'does not apply migrations') !== false, 'help should state read-only migration behavior');
recipeOperatorSurfaceSmokeAssert(strpos($help, 'curl/Netscape cookie jar') !== false, 'help should document cookie jar input');
recipeOperatorSurfaceSmokeAssert(strpos($help, 'does not inspect JavaScript console logs or screenshots') !== false, 'help should distinguish browser QA limits');

foreach ([
    'recipe_operational_dashboard.php' => 'Recipe Operational Dashboard',
    'recipe_stock_reconciliation.php' => 'Recipe Stock Reconciliation',
    'recipe_operations_report.php' => 'Recipe Operations Reports',
    'recipe_manage.php' => 'Recipe Draft Management',
    'recipe_production.php' => 'Recipe Production Batches',
    'inventory_adjustments.php' => 'الهالك والتسويات',
    'recipe_audit_report.php' => 'Recipe Audit Log',
    'recipe_editor.php' => 'Recipe Editor - Read Only',
] as $path => $heading) {
    recipeOperatorSurfaceSmokeAssert(strpos($tool, $path) !== false, 'tool should smoke page: ' . $path);
    recipeOperatorSurfaceSmokeAssert(strpos($tool, $heading) !== false, 'tool should check heading: ' . $heading);
}

foreach ([
    'Recipe writes are disabled by the current feature flags. Mode: off.',
    'Production batch writes are disabled by the current recipe feature flags. Mode: off.',
    'هذه الشاشة جاهزة، لكن التسجيل يحتاج وضع bridge أو live للمخزون.',
] as $modeOffMessage) {
    recipeOperatorSurfaceSmokeAssert(strpos($tool, $modeOffMessage) !== false, 'tool should check mode-off disabled message: ' . $modeOffMessage);
}

foreach ([
    'recipeOperatorSurfaceSmokeLoginDetected',
    'recipeOperatorSurfaceSmokeAccessDenied',
    'recipeOperatorSurfaceSmokeFatalText',
    'recipeOperatorSurfaceSmokeNormalizeCookieSource',
    '#HttpOnly_',
    '$pageSpecificChecksAllowed = $pageBlockers === []',
    'recipe_operator_surface_smoke_cookie_missing',
    'requires_authenticated_session_cookie',
] as $needle) {
    recipeOperatorSurfaceSmokeAssert(strpos($tool, $needle) !== false, 'tool should expose smoke guard: ' . $needle);
}

foreach (['method\' => \'GET\'', 'POST', 'INSERT INTO', 'UPDATE ', 'DELETE FROM', 'RecipeInventoryMovementService', 'RecipeAccountingService', 'SyncOutboxEventService'] as $needle) {
    if ($needle === 'method\' => \'GET\'') {
        recipeOperatorSurfaceSmokeAssert(strpos($tool, $needle) !== false, 'tool should only use GET requests');
        continue;
    }
    recipeOperatorSurfaceSmokeAssert(strpos($tool, $needle) === false, 'tool must stay read-only and avoid: ' . $needle);
}

recipeOperatorSurfaceSmokeAssert(strpos($preflight, 'tools/recipe_operator_surface_smoke.php') !== false, 'runtime preflight should require operator surface smoke tool');
recipeOperatorSurfaceSmokeAssert(strpos($doc, 'tools/recipe_operator_surface_smoke.php') !== false, 'rollout doc should document operator surface smoke command');
recipeOperatorSurfaceSmokeAssert(strpos($doc, 'It does not log in, submit forms, apply migrations, change feature flags, write recipe rows, write stock, post accounting, enqueue sync') !== false, 'rollout doc should document read-only scope');
recipeOperatorSurfaceSmokeAssert(strpos($doc, '--cookie-file=/private/tmp/posmain-cookies.txt') !== false, 'rollout doc should document cookie jar usage');

echo "recipe-operator-surface-smoke-contract-ok\n";

function recipeOperatorSurfaceSmokeSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function recipeOperatorSurfaceSmokeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
