<?php

$root = realpath(__DIR__ . '/../..');
$health = file_get_contents($root . '/api/health.php');
$reconciliation = file_get_contents($root . '/tools/order_creation_reconciliation.php');
$config = file_get_contents($root . '/config/app_config.php');

productionGatesAssert(strpos($health, 'posmainHealthOrderCreationCheck') !== false, 'health should include order creation check');
productionGatesAssert(strpos($reconciliation, 'sync_outbox') !== false, 'reconciliation should count outbox events');
productionGatesAssert(strpos($config, 'POSMAIN_ORDER_ROUTER') === false, 'app config should not expose order router flag');
productionGatesAssert(strpos($config, 'POSMAIN_ORDER_SIDE_EFFECT_MODE') === false, 'app config should not expose side effect flag');

echo "production-gates-contract-ok\n";

function productionGatesAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
