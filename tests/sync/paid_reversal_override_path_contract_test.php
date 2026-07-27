<?php

$root = dirname(__DIR__, 2);

function paidReversalOverridePathAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$endpoint = file_get_contents($root . '/ajax/refund_order.php');
$manifest = file_get_contents($root . '/config/rbac_route_manifest.php');
$js = file_get_contents($root . '/js/pos_barcode.js');
$markup = file_get_contents($root . '/includes/pos_content.php');
$css = file_get_contents($root . '/dist/css/pos_barcode.css');

paidReversalOverridePathAssert(
    strpos($endpoint, 'require_pos_lane_permission($reversalPermission') !== false,
    'refund endpoint should enforce action-specific POS lane permission'
);
paidReversalOverridePathAssert(
    strpos($endpoint, "pos.void.paid") !== false && strpos($endpoint, "pos.refund") !== false,
    'refund endpoint should map void/refund to distinct permission keys'
);
paidReversalOverridePathAssert(
    preg_match("/'ajax\\/refund_order\\.php'\\s*=>\\s*\\[[^\\]]*permission'\\s*=>\\s*''/", $manifest) === 1,
    'route manifest must not hard-require pos.refund before action is known'
);
paidReversalOverridePathAssert(
    strpos($js, 'openPaidOrderReversalModal(orderId, refundEligible, voidEligible, {') !== false,
    'recent-orders click should open modal without pre-approving the wrong permission'
);
paidReversalOverridePathAssert(
    strpos($js, 'paidReversalNeedsApproval') !== false,
    'submit path should recognize approval-required responses'
);
paidReversalOverridePathAssert(
    strpos($js, 'paidReversalFriendlyError') !== false,
    'UI should map raw permission codes to Arabic messages'
);
paidReversalOverridePathAssert(
    strpos($markup, 'pos-paid-reversal-close') !== false,
    'paid reversal modal should use absolute close button class'
);
paidReversalOverridePathAssert(
    strpos($markup, 'pos-recent-orders-close') !== false,
    'recent orders modal should use absolute close button class'
);
paidReversalOverridePathAssert(
    strpos($css, '#paidOrderReversalModal .pos-paid-reversal-close') !== false,
    'CSS should pin paid-reversal close button to inset-inline-end'
);
paidReversalOverridePathAssert(
    strpos($css, '#recentOrdersModal .pos-recent-orders-close') !== false,
    'CSS should pin recent-orders close button to inset-inline-end'
);

echo "paid-reversal-override-path-contract-ok\n";
