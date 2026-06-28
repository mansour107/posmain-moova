<?php

require_once __DIR__ . '/../../classes/Pos/Service/SideEffectPolicy.php';

sideEffectPolicyAssert(SideEffectPolicy::mode() === SideEffectPolicy::MODE_SHADOW, 'side effects run in shadow mode');
sideEffectPolicyAssert(
    SideEffectPolicy::inventoryBridgeShouldRollback(new RuntimeException('x'), ['success' => false]) === false,
    'inventory bridge failures should not rollback orders'
);
sideEffectPolicyAssert(
    SideEffectPolicy::orderEventShouldRollback(new RuntimeException('x')) === false,
    'order event failures should not rollback orders'
);

echo "side-effect-policy-ok\n";

function sideEffectPolicyAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
