<?php

$root = dirname(__DIR__, 2);
$toolPath = $root . '/tools/recipe_pos_grid_availability_surface_smoke.php';
$tool = recipePosGridAvailabilitySurfaceSmokeSource($toolPath);
$preflight = recipePosGridAvailabilitySurfaceSmokeSource($root . '/classes/Recipe/RecipeRuntimePreflightService.php');
$pilotEvidence = recipePosGridAvailabilitySurfaceSmokeSource($root . '/classes/Recipe/RecipePilotEvidenceService.php');
$doc = recipePosGridAvailabilitySurfaceSmokeSource($root . '/docs/recipe/rollout_readiness.md');

exec('php ' . escapeshellarg($toolPath) . ' --help', $helpLines, $helpCode);
$help = implode("\n", $helpLines);

recipePosGridAvailabilitySurfaceSmokeAssert($helpCode === 0, 'POS grid availability surface smoke help should exit cleanly');
recipePosGridAvailabilitySurfaceSmokeAssert(strpos($help, 'read-only authenticated GET smoke') !== false, 'help should describe authenticated GET smoke');
recipePosGridAvailabilitySurfaceSmokeAssert(strpos($help, 'POS grid recipe availability surface') !== false, 'help should describe POS grid availability scope');
recipePosGridAvailabilitySurfaceSmokeAssert(strpos($help, 'does not log in') !== false, 'help should state that it does not log in');
recipePosGridAvailabilitySurfaceSmokeAssert(strpos($help, 'curl/Netscape cookie jar') !== false, 'help should document cookie jar input');
recipePosGridAvailabilitySurfaceSmokeAssert(strpos($help, 'cost masking') !== false, 'help should document cost masking check');
recipePosGridAvailabilitySurfaceSmokeAssert(strpos($help, 'POS barcode gate') !== false, 'help should document cashier unlock warning');
recipePosGridAvailabilitySurfaceSmokeAssert(strpos($help, 'does not click items') !== false, 'help should distinguish browser QA limits');
recipePosGridAvailabilitySurfaceSmokeAssert(strpos($help, 'add items') !== false, 'help should state that it does not add items');

foreach ([
    'pos_barcode.php',
    'js/pos_barcode.js',
    'ajax/get_category_items.php',
    'data-is-available',
    'data-availability-can-add',
    'data-availability-status',
    'data-unavailable-reason',
    'data-recipe-enabled',
    'data-recipe-effective-available-qty',
    'data-recipe-availability-revision',
    'itemAvailabilityContext',
    'showUnavailableItemMessage',
    'availability.canAdd',
    'recipe_effective_available_qty',
    'availability_fields_missing',
    'sensitive_cost_keys_exposed',
    'cost_price',
    'costPrice',
    'ingredientCostJson',
    'internalCostPerSellUnit',
    'category_id_not_provided_payload_shape_not_observed',
    'no_recipe_item_in_category',
    'no_low_or_unavailable_recipe_item_in_category',
] as $needle) {
    recipePosGridAvailabilitySurfaceSmokeAssert(strpos($tool, $needle) !== false, 'tool should check POS availability surface: ' . $needle);
}

foreach ([
    'recipePosGridAvailabilitySurfaceSmokeLoginDetected',
    'recipePosGridAvailabilitySurfaceSmokePosBarcodeGateDetected',
    'recipePosGridAvailabilitySurfaceSmokeAccessDenied',
    'recipePosGridAvailabilitySurfaceSmokeFatalText',
    'recipePosGridAvailabilitySurfaceSmokeNormalizeCookieSource',
    '#HttpOnly_',
    'requires_authenticated_session_cookie',
    'expected_availability_card_attrs_missing',
    'expected_availability_js_missing',
] as $needle) {
    recipePosGridAvailabilitySurfaceSmokeAssert(strpos($tool, $needle) !== false, 'tool should expose smoke guard: ' . $needle);
}

foreach ([
    "'method' => 'GET'",
    'CURLOPT_HTTPGET',
] as $needle) {
    recipePosGridAvailabilitySurfaceSmokeAssert(strpos($tool, $needle) !== false, 'tool should only use GET requests: ' . $needle);
}

foreach (['CURLOPT_POST', 'INSERT INTO', 'UPDATE ', 'DELETE FROM', 'RecipeInventoryMovementService', 'RecipeAccountingService', 'SyncOutboxEventService', 'recordRecipeConsumption', 'requestApproval('] as $needle) {
    recipePosGridAvailabilitySurfaceSmokeAssert(strpos($tool, $needle) === false, 'tool must stay read-only and avoid: ' . $needle);
}

recipePosGridAvailabilitySurfaceSmokeAssert(strpos($preflight, 'tools/recipe_pos_grid_availability_surface_smoke.php') !== false, 'runtime preflight should require POS grid availability smoke tool');
recipePosGridAvailabilitySurfaceSmokeAssert(strpos($pilotEvidence, 'tools/recipe_pos_grid_availability_surface_smoke.php') !== false, 'pilot evidence should accept POS grid availability smoke command details');
recipePosGridAvailabilitySurfaceSmokeAssert(strpos($doc, 'tools/recipe_pos_grid_availability_surface_smoke.php') !== false, 'rollout doc should document POS grid availability smoke command');
recipePosGridAvailabilitySurfaceSmokeAssert(strpos($doc, 'POS grid recipe availability surface') !== false, 'rollout doc should describe POS grid availability smoke scope');
recipePosGridAvailabilitySurfaceSmokeAssert(strpos($doc, 'operator-unlock warning') !== false, 'rollout doc should describe POS barcode gate warning');
recipePosGridAvailabilitySurfaceSmokeAssert(strpos($doc, 'click items, create orders') !== false, 'rollout doc should document non-mutating POS availability scope');

echo "recipe-pos-grid-availability-surface-smoke-contract-ok\n";

function recipePosGridAvailabilitySurfaceSmokeSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function recipePosGridAvailabilitySurfaceSmokeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
