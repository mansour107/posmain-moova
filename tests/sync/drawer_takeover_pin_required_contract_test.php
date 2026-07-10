<?php

$endpoint = file_get_contents(__DIR__ . '/../../do/do_takeover_drawer_session.php');
$service = file_get_contents(__DIR__ . '/../../classes/Pos/Service/ShiftSessionService.php');
$wizard = file_get_contents(__DIR__ . '/../../js/pos_shift_count_wizard.js');

function takeoverPinAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

takeoverPinAssert($endpoint !== false && $service !== false && $wizard !== false, 'unable to read takeover sources');

takeoverPinAssert(
    strpos($endpoint, 'auth_guard_has_permission(\'pos.shift.force_close\'') === false
        || strpos($endpoint, 'No permission short-circuit') !== false,
    'takeover endpoint must not short-circuit on force_close permission'
);
takeoverPinAssert(
    strpos($endpoint, 'ManagerApprovalRequiredException') !== false
        && strpos($endpoint, '$managerApprovalId < 1') !== false,
    'takeover endpoint must require manager_approval_id'
);
takeoverPinAssert(
    strpos($endpoint, 'validateApprovedPermissionOverride') !== false,
    'takeover endpoint must validate approval before money write'
);

takeoverPinAssert(
    strpos($service, 'POS drawer takeover always requires a consumed manager PIN approval') !== false,
    'forceCloseDrawerForUser must document takeover PIN requirement'
);
takeoverPinAssert(
    preg_match('/\$isTakeover[\s\S]*?ManagerApprovalRequiredException/', $service) === 1,
    'takeover branch must throw without approval id'
);

takeoverPinAssert(
    strpos($wizard, 'Always require manager PIN step-up') !== false,
    'wizard must always request PIN before takeover POST'
);
takeoverPinAssert(
    strpos($wizard, "runTakeover('')") === false
        && strpos($wizard, 'runTakeover("")') === false,
    'wizard must not POST takeover with empty approval first'
);

echo "drawer_takeover_pin_required_contract_test: OK\n";
