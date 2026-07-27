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

$previousMode = getenv('POSMAIN_SIDE_EFFECT_MODE');
putenv('POSMAIN_SIDE_EFFECT_MODE=live');
try {
    sideEffectPolicyAssert(
        SideEffectPolicy::mode() === SideEffectPolicy::MODE_LIVE,
        'explicit live side-effect mode should be honored'
    );
    sideEffectPolicyAssert(
        SideEffectPolicy::inventoryBridgeShouldRollback(new RuntimeException('bridge exception')) === true,
        'an inventory bridge exception must rollback in live mode even without a structured error result'
    );
    sideEffectPolicyAssert(
        SideEffectPolicy::inventoryBridgeShouldRollback(
            new RuntimeException('bridge errors'),
            ['success' => false, 'errors' => ['inventory reversal failed']]
        ) === true,
        'structured inventory bridge errors must rollback in live mode'
    );
    sideEffectPolicyAssert(
        SideEffectPolicy::inventoryBridgeShouldRollback(
            new RuntimeException('bridge errors'),
            ['success' => true, 'errors' => [], 'accounting' => ['errors' => [['message' => 'journal failed']]]]
        ) === true,
        'nested accounting errors must rollback in live mode'
    );
    sideEffectPolicyAssert(
        SideEffectPolicy::inventoryBridgeShouldRollback(
            new RuntimeException('bridge errors'),
            ['success' => true, 'errors' => [], 'accounting' => ['errors' => []]]
        ) === false,
        'a successful live bridge result must not rollback'
    );
    sideEffectPolicyAssert(
        SideEffectPolicy::orderEventShouldRollback(new RuntimeException('event exception')) === true,
        'order event failures must rollback in live mode'
    );
} finally {
    if ($previousMode === false) {
        putenv('POSMAIN_SIDE_EFFECT_MODE');
    } else {
        putenv('POSMAIN_SIDE_EFFECT_MODE=' . $previousMode);
    }
}

echo "side-effect-policy-ok\n";

function sideEffectPolicyAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
