<?php

$root = dirname(__DIR__, 2);
$endpoint = recipeMoovaMenuPayloadSource($root . '/ajax/moova_menu_sync_payload.php');
$syncPayload = recipeMoovaMenuPayloadSource($root . '/classes/Recipe/RecipeSyncPayloadService.php');
$costLeak = recipeMoovaMenuPayloadSource($root . '/classes/Recipe/RecipeCostLeakAuditService.php');

recipeMoovaMenuPayloadAssert(
    strpos($endpoint, 'RecipeSyncPayloadService.php') !== false,
    'Moova menu payload endpoint should load the shared recipe sync payload service.'
);
recipeMoovaMenuPayloadAssert(
    strpos($endpoint, 'RecipeScopeResolver.php') !== false,
    'Moova menu payload endpoint should resolve the same scoped recipe context as other sync paths.'
);
recipeMoovaMenuPayloadAssert(
    strpos($endpoint, 'function moova_menu_sync_build_menu(mysqli $conn, string $catalogVersion, ?array $link = null)') !== false,
    'Moova menu builder should accept the resolved Moova link so recipe availability uses the mapped tenant/branch.'
);
recipeMoovaMenuPayloadAssert(
    strpos($endpoint, '$recipeFlags->isMoovaSyncEnabled()') !== false,
    'Moova menu payload should be gated by the effective recipe Moova sync flag.'
);
recipeMoovaMenuPayloadAssert(
    strpos($endpoint, 'menuItemSnapshotPayload(') !== false,
    'Moova menu payload should delegate recipe availability shape to RecipeSyncPayloadService.'
);
recipeMoovaMenuPayloadAssert(
    strpos($endpoint, "'source_system' => 'moova_menu_sync'") !== false,
    'Moova menu payload should identify recipe availability context as menu sync.'
);
recipeMoovaMenuPayloadAssert(
    strpos($endpoint, "'channel' => 'moova'") !== false && strpos($endpoint, "'order_type' => 'delivery'") !== false,
    'Moova menu payload should request delivery/Moova-specific recipe availability.'
);
recipeMoovaMenuPayloadAssert(
    strpos($endpoint, "\$menuItem['recipe_availability'] = \$recipePayload") !== false,
    'Moova menu payload should include a safe nested recipe availability payload.'
);
recipeMoovaMenuPayloadAssert(
    strpos($endpoint, "\$menuItem['deliveryAvailable'] = false") !== false,
    'Moova menu payload should make delivery unavailable when computed recipe availability is false.'
);
recipeMoovaMenuPayloadAssert(
    strpos($endpoint, 'moova_menu_sync_sanitize_public_payload($payload)') !== false,
    'Moova menu payload should still sanitize every JSON response before output.'
);

foreach (['RecipeAvailabilityService', 'RecipeInventoryMovementService', 'RecipeAccountingService'] as $forbiddenService) {
    recipeMoovaMenuPayloadAssert(
        strpos($endpoint, $forbiddenService) === false,
        'Moova menu endpoint must not calculate availability or write stock/accounting directly: ' . $forbiddenService
    );
}

foreach (['cost_price', 'cost', 'internal_cost_per_sell_unit'] as $sensitiveKey) {
    recipeMoovaMenuPayloadAssert(
        strpos($syncPayload, $sensitiveKey) === false || strpos($costLeak, $sensitiveKey) !== false,
        'Sensitive recipe/menu cost keys must remain covered by the cost-leak audit service: ' . $sensitiveKey
    );
}

echo "recipe-moova-menu-availability-payload-contract-ok\n";

function recipeMoovaMenuPayloadSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function recipeMoovaMenuPayloadAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
