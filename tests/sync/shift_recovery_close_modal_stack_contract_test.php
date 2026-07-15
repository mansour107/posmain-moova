<?php
declare(strict_types=1);

/**
 * Contract: stale-shift recovery must defer itself when #closeShiftModal opens,
 * otherwise Bootstrap (~z-index 1055) stacks behind the overlay (z-index 2000).
 */

function recoveryCloseStackAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
    echo 'OK: ' . $message . PHP_EOL;
}

$root = dirname(__DIR__, 2);
$overlay = (string) file_get_contents($root . '/elements/pos/shift_recovery_overlay.php');

recoveryCloseStackAssert($overlay !== '', 'overlay readable');

recoveryCloseStackAssert(
    strpos($overlay, 'data-bs-target="#closeShiftModal"') !== false
        && strpos($overlay, "state === 'stale_shift'") !== false,
    'stale_shift CTA must target #closeShiftModal'
);

recoveryCloseStackAssert(
    strpos($overlay, 'is-deferred-for-close') !== false
        && strpos($overlay, "document.addEventListener('show.bs.modal'") !== false
        && strpos($overlay, "document.addEventListener('hidden.bs.modal'") !== false
        && strpos($overlay, "e.target.id === 'closeShiftModal'") !== false,
    'recovery must hide/restore around closeShiftModal via document listeners'
);

echo "shift_recovery_close_modal_stack_contract_test.php PASS\n";
