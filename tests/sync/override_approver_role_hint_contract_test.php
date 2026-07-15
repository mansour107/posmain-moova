<?php

$root = dirname(__DIR__, 2);

function overrideApproverRoleHintAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$permissionService = file_get_contents($root . '/classes/Security/PermissionService.php');
$layout = file_get_contents($root . '/includes/layout_capabilities.php');
$capsJs = file_get_contents($root . '/js/posmain_capabilities.js');
$pinPad = file_get_contents($root . '/js/pin_pad.js');
$posJs = file_get_contents($root . '/js/pos_barcode.js');
$styles = file_get_contents($root . '/includes/pin_pad_styles.php');

overrideApproverRoleHintAssert(
    strpos($permissionService, 'function approverRolesForPermission') !== false,
    'PermissionService should resolve approver roles for a permission'
);
overrideApproverRoleHintAssert(
    strpos($permissionService, 'function approverRoleIndex') !== false,
    'PermissionService should expose approver role index'
);
overrideApproverRoleHintAssert(
    strpos($layout, 'POSMAIN_APPROVER_ROLES') !== false,
    'POS context script should inject POSMAIN_APPROVER_ROLES'
);
overrideApproverRoleHintAssert(
    strpos($capsJs, 'formatApproverRoleHint') !== false,
    'capabilities helper should format approver role hint'
);
overrideApproverRoleHintAssert(
    strpos($pinPad, 'ppm-role-hint') !== false,
    'PIN pad modal should render role hint element'
);
overrideApproverRoleHintAssert(
    strpos($posJs, 'formatApproverRoleHint') !== false,
    'requestManagerOverride should pass role hint into PIN pad'
);
overrideApproverRoleHintAssert(
    strpos($styles, '.ppm-role-hint') !== false,
    'PIN pad styles should include role hint styling'
);

echo "override-approver-role-hint-contract-ok\n";
