<?php

$root = dirname(__DIR__, 2);
$js = (string) file_get_contents($root . '/js/pos_barcode.js');
$auth = (string) file_get_contents($root . '/includes/auth_guard.php');
$rbac = (string) file_get_contents($root . '/includes/rbac_route_guard.php');
$policy = (string) file_get_contents($root . '/classes/Pos/Security/PosOrderAccessPolicy.php');
$orderApi = (string) file_get_contents($root . '/js/pos_order_api.js');

posTableVisibleLockedAssert(
    strpos($js, "addClass('pos-action-locked')") !== false,
    'table tab must use pos-action-locked instead of hiding'
);
posTableVisibleLockedAssert(
    strpos($js, ".toggle(canTable)") === false,
    'table tab must not be hidden with toggle(canTable)'
);
posTableVisibleLockedAssert(
    strpos($js, 'ensurePermissionOrOverride(\'pos.table.open\'') !== false,
    'table tab click must request manager override for pos.table.open'
);
posTableVisibleLockedAssert(
    strpos($js, 'POSMAIN_TABLE_OPEN_OVERRIDE') !== false,
    'table override approval must be stored for follow-up requests'
);
posTableVisibleLockedAssert(
    strpos($js, 'posmainTableOpenOverrideParams') !== false,
    'get_tables requests must forward manager approval when present'
);
posTableVisibleLockedAssert(
    strpos($auth, 'auth_guard_pos_lane_has_permission_or_override') !== false,
    'auth guard must allow manager approval override on POS lane'
);
posTableVisibleLockedAssert(
    strpos($rbac, 'auth_guard_pos_lane_has_permission_or_override') !== false,
    'rbac route guard must honor manager approval override on POS lane'
);
posTableVisibleLockedAssert(
    strpos($policy, 'require_pos_lane_permission') !== false,
    'POS API must check acting-user lane permission with override support'
);
posTableVisibleLockedAssert(
    strpos($orderApi, 'POSMAIN_TABLE_OPEN_OVERRIDE') !== false,
    'order API payload must include table override approval id'
);

echo "pos-table-visible-locked-contract-ok\n";

function posTableVisibleLockedAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
