<?php

$root = dirname(__DIR__, 2);
$posOrder = recipeMoovaReplayContractSource($root . '/classes/PosOrderService.php');
$lifecycle = recipeMoovaReplayContractSource($root . '/classes/Recipe/RecipeOrderLifecycleService.php');
$reservations = recipeMoovaReplayContractSource($root . '/classes/Recipe/RecipeReservationService.php');
$reservationRepo = recipeMoovaReplayContractSource($root . '/classes/Recipe/Repository/StockReservationRepository.php');
$usageRepo = recipeMoovaReplayContractSource($root . '/classes/Recipe/Repository/RecipeOrderLineUsageRepository.php');
$runtimeTest = recipeMoovaReplayContractSource($root . '/tests/sync/recipe_moova_replay_runtime_test.php');

recipeMoovaReplayContractAssert(strpos($posOrder, 'recipeContextsFromExternalLineMap') !== false, 'Moova mapped recipe contexts should resolve external line identities');
recipeMoovaReplayContractAssert(strpos($posOrder, 'FROM external_order_line_map') !== false, 'Moova mapped recipe contexts should read the external line identity map');
recipeMoovaReplayContractAssert(strpos($posOrder, "'source_line_uuid' => substr(\$sourceChannel . ':' . (string) \$row['external_line_id']") !== false, 'Moova mapped cancellation contexts should preserve provider source line UUIDs');

recipeMoovaReplayContractAssert(strpos($lifecycle, 'cancelTargetUsages') !== false, 'Recipe lifecycle should centralize cancellation target resolution');
recipeMoovaReplayContractAssert(strpos($lifecycle, 'findForExternalSourceLine') !== false, 'External recipe cancellations should target usage rows by source line');
recipeMoovaReplayContractAssert(strpos($lifecycle, 'releaseForUsageIds') !== false, 'External recipe cancellations should release reservations by usage id');

recipeMoovaReplayContractAssert(strpos($reservations, 'public function releaseForUsageIds') !== false, 'Reservation service should expose usage-id release');
recipeMoovaReplayContractAssert(strpos($reservationRepo, 'findActiveForUsageIds') !== false, 'Reservation repository should find active reservations by usage ids');
recipeMoovaReplayContractAssert(strpos($usageRepo, 'findForExternalSourceLine') !== false, 'Usage repository should find usages by external source line');

foreach ([
    'MoovaNewOrderApplyService',
    'MoovaChangeOrderApplyService',
    'RecipeOrderLifecycleService',
    'same item from multiple Moova orders should share the legacy detail row',
    'cancel should release only the cancelled Moova source line',
    'payment replay should not duplicate recipe consumption movement',
] as $needle) {
    recipeMoovaReplayContractAssert(strpos($runtimeTest, $needle) !== false, 'Moova replay runtime test missing proof: ' . $needle);
}

echo "recipe-moova-replay-contract-ok\n";

function recipeMoovaReplayContractSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function recipeMoovaReplayContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
