<?php

$root = dirname(__DIR__, 2);
$lifecycle = inventoryPhase11Source($root . '/classes/Recipe/RecipeOrderLifecycleService.php');
$reservations = inventoryPhase11Source($root . '/classes/Recipe/RecipeReservationService.php');
$availability = inventoryPhase11Source($root . '/classes/Recipe/RecipeAvailabilityService.php');
$itemAvailability = inventoryPhase11Source($root . '/classes/Pos/Service/ItemAvailabilityService.php');
$posMutation = inventoryPhase11Source($root . '/classes/Pos/Service/PosOrderMutationService.php');
$posCard = inventoryPhase11Source($root . '/includes/pos_item_card.php');
$posJs = inventoryPhase11Source($root . '/js/pos_barcode.js');
$barcodeSearch = inventoryPhase11Source($root . '/ajax/search_item.php');
$appConfig = inventoryPhase11Source($root . '/config/app_config.php');
$doc = inventoryPhase11Source($root . '/docs/inventory/phase11_pos_availability_contracts.md');

foreach ([
    'isReservationEnabledForItem',
    'reserveExplosion',
    'releaseForOrderLine',
    'consumeForOrderLine',
    'reservationExpiresAt',
    'defaultReservationMinutes',
    '\'expires_at\' => $this->reservationExpiresAt($context)',
] as $needle) {
    inventoryPhase11Assert(strpos($lifecycle . $reservations, $needle) !== false, 'reservation lifecycle should expose: ' . $needle);
}

foreach ([
    'expireReservations',
    'findExpiredReserved',
    'recordReservationRelease',
    "'expired'",
] as $needle) {
    inventoryPhase11Assert(strpos($reservations, $needle) !== false, 'reservation expiry should expose: ' . $needle);
}

foreach ([
    'batch_prepared',
    'availabilityFromPreparedStock',
    'assertAvailableForOrderLine',
    'isStrictStockEnabled',
    'Required ingredient out of stock.',
    'recipe_availability_cache',
] as $needle) {
    inventoryPhase11Assert(strpos($availability, $needle) !== false, 'POS recipe availability should expose: ' . $needle);
}

foreach ([
    'availability_can_add',
    'availability_low_stock',
    'availability_requires_manager_override',
    'availability_override_allowed',
    'pos.recipe_stock_override',
    'recipe_effective_available_qty',
    'itemAvailabilityContextFromPayload',
] as $needle) {
    inventoryPhase11Assert(strpos($itemAvailability . $posCard . $posJs . $barcodeSearch, $needle) !== false, 'cashier availability UI should expose: ' . $needle);
}

foreach ([
    'ItemAvailabilityService.php',
    'decorateItems($conn, [$barcodeItem]',
    'posmain_pos_availability_scope($conn)',
] as $needle) {
    inventoryPhase11Assert(strpos($barcodeSearch, $needle) !== false, 'barcode search should use POS availability service: ' . $needle);
}

foreach ([
    'managerApprovalService',
    'recipe.stock_override',
    'recordRecipeStockOverrideAudit',
    'assertAvailabilityCanAdd',
] as $needle) {
    inventoryPhase11Assert(strpos($posMutation, $needle) !== false, 'POS mutation should enforce override contract: ' . $needle);
}

foreach ([
    'POSMAIN_RECIPE_AVAILABILITY=1',
    'POSMAIN_INVENTORY_AVAILABILITY=1',
    'POSMAIN_RECIPE_RESERVATIONS=1',
    'POSMAIN_INVENTORY_RESERVATIONS=1',
    'POSMAIN_RECIPE_STRICT_STOCK=1',
    'POSMAIN_INVENTORY_STRICT_STOCK=1',
    'No competing reservation table',
    'Barcode search uses the same availability payload',
] as $needle) {
    inventoryPhase11Assert(strpos($doc, $needle) !== false, 'phase 11 doc should capture rollout contract: ' . $needle);
}

foreach ([
    "['POSMAIN_RECIPE_RESERVATIONS', 'POSMAIN_INVENTORY_RESERVATIONS']",
    "['POSMAIN_RECIPE_AVAILABILITY', 'POSMAIN_INVENTORY_AVAILABILITY']",
    "['POSMAIN_RECIPE_STRICT_STOCK', 'POSMAIN_INVENTORY_STRICT_STOCK']",
] as $needle) {
    inventoryPhase11Assert(strpos($appConfig, $needle) !== false, 'app config should bridge inventory POS flags conservatively: ' . $needle);
}

echo "inventory-phase11-pos-availability-contract-ok\n";

function inventoryPhase11Source(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function inventoryPhase11Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
