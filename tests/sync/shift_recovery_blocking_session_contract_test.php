<?php
declare(strict_types=1);

/**
 * Contract: branch_blocked recovery actions must target the blocking drawer session,
 * not a stale operator drawer_session_id.
 */

function recoveryBlockingSessionAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
    echo 'OK: ' . $message . PHP_EOL;
}

$root = dirname(__DIR__, 2);
$overlay = file_get_contents($root . '/elements/pos/shift_recovery_overlay.php');
$barcode = file_get_contents($root . '/pos_barcode.php');

recoveryBlockingSessionAssert(is_string($overlay) && $overlay !== '', 'overlay readable');
recoveryBlockingSessionAssert(is_string($barcode) && $barcode !== '', 'pos_barcode readable');

recoveryBlockingSessionAssert(
    strpos($overlay, "if (\$state === 'branch_blocked')") !== false
        && strpos($overlay, "(int) (\$blocking['id'] ?? 0)") !== false,
    'branch_blocked must prefer blocking session id'
);

recoveryBlockingSessionAssert(
    strpos($barcode, 'POS branch_blocked heal failed') !== false
        && strpos($barcode, "SELECT status FROM drawer_sessions WHERE id = ? LIMIT 1") !== false,
    'pos_barcode must heal stale closed blocking sessions on load'
);

recoveryBlockingSessionAssert(
    strpos($overlay, 'DRAWER_SESSION_NOT_OPEN') !== false
        && strpos($overlay, 'window.location.reload()') !== false,
    'override failure for closed drawer should reload the page'
);

echo "shift_recovery_blocking_session_contract_test.php PASS\n";
