<?php

$root = dirname(__DIR__, 2);
$save = file_get_contents($root . '/ajax/moova_save_integration.php');
$service = file_get_contents($root . '/classes/Moova/MoovaPosMenuReconcileService.php');
$integration = file_get_contents($root . '/moova_integration.php');

moovaReconcileContractAssert(strpos($save, 'MoovaPosMenuReconcileService') !== false, 'save integration should use menu reconcile service');
moovaReconcileContractAssert(strpos($service, 'moova_menu_sync_payload.php') !== false, 'reconcile service should use full menu payload endpoint');
moovaReconcileContractAssert(strpos($service, 'menuSyncMode') !== false, 'reconcile service should request full menu sync mode');
moovaReconcileContractAssert(strpos($service, 'menu-sync/reconcile') !== false, 'reconcile service should support explicit reconcile fallback');
moovaReconcileContractAssert(strpos($service, 'itemsDeactivated') !== false, 'reconcile service should report removed items from Moova import summary');
moovaReconcileContractAssert(strpos($integration, 'result.autoSync.message') !== false, 'integration UI should surface reconcile summary message');

echo "moova-pos-menu-reconcile-contract-ok\n";

function moovaReconcileContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
