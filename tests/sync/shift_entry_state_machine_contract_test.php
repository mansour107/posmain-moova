<?php

/**
 * Disposable-MySQL style unit checks for ShiftEntryService state labels and
 * register lock helpers (no live DB required for source/contract assertions).
 */

$root = dirname(__DIR__, 2);
require_once $root . '/classes/Pos/Service/ShiftEntryService.php';
require_once $root . '/classes/Pos/Service/PosRegisterService.php';

shiftEntryAssert(
    ShiftEntryService::STATE_SELLING_READY === 'selling_ready',
    'selling_ready constant'
);
shiftEntryAssert(
    ShiftEntryService::STATE_REGISTER_TRANSFER_REQUIRED === 'register_transfer_required',
    'transfer constant'
);
shiftEntryAssert(
    ShiftEntryService::STATE_STALE_SHIFT === 'stale_shift',
    'stale constant'
);
shiftEntryAssert(
    PosRegisterService::COOKIE_NAME === 'posmain_register_token',
    'register cookie name'
);

$drawerSource = (string) file_get_contents($root . '/classes/Pos/Service/DrawerSessionService.php');
shiftEntryAssert(strpos($drawerSource, 'open_register_lock') !== false, 'drawer open_register_lock');
shiftEntryAssert(strpos($drawerSource, 'transferOpenSessionRegister') !== false, 'drawer transfer helper');
shiftEntryAssert(strpos($drawerSource, 'REGISTER_DRAWER_ALREADY_OPEN') !== false, 'register conflict code');

$entrySource = (string) file_get_contents($root . '/classes/Pos/Service/ShiftEntryService.php');
shiftEntryAssert(strpos($entrySource, 'currentBusinessDayForBranch') !== false, 'business-day aware');
shiftEntryAssert(strpos($entrySource, 'requirePairedRegister') !== false, 'register pairing required');

echo "shift-entry-state-machine-contract-ok\n";

function shiftEntryAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
