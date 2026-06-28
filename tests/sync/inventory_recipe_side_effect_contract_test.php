<?php

$root = realpath(__DIR__ . '/../..');
$mutation = file_get_contents($root . '/classes/Pos/Service/PosOrderMutationService.php');

inventoryRecipeAssert(strpos($mutation, 'SideEffectPolicy::inventoryBridgeShouldRollback') !== false, 'inventory bridge should consult SideEffectPolicy');
inventoryRecipeAssert(strpos($mutation, 'SideEffectPolicy::orderEventShouldRollback') !== false, 'order events should consult SideEffectPolicy');
inventoryRecipeAssert(strpos($mutation, 'recordInventoryInvoiceBridgeLines') !== false, 'mutation service should own inventory bridge hook');

$cofe = file_get_contents($root . '/ajax/cofe_create_order.php');
inventoryRecipeAssert(strpos($cofe, 'PosOrderController') !== false, 'cofe path should use canonical mutation pipeline');

echo "inventory-recipe-side-effect-contract-ok\n";

function inventoryRecipeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
