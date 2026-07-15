<?php

$loginScreen = (string) file_get_contents(__DIR__ . '/../../includes/pos_login_screen.php');

function shiftCloseAckAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

shiftCloseAckAssert($loginScreen !== '', 'pos_login_screen readable');
shiftCloseAckAssert(
    strpos($loginScreen, 'shiftCloseResultTimerBar') !== false
        && strpos($loginScreen, 'shiftCloseTimerShrink') !== false,
    'close ack must include 5s shrinking timer bar'
);
shiftCloseAckAssert(
    strpos($loginScreen, "DURATION_MS = 5000") !== false,
    'close ack auto-dismiss must be 5 seconds'
);
shiftCloseAckAssert(
    strpos($loginScreen, "var LOGIN_URL = <?=") !== false
        && strpos($loginScreen, "'do/do_logout.php'") !== false
        && strpos($loginScreen, '$closeAckRedirectUrl') !== false,
    'close ack must redirect to logout/login (or index.php when identity already cleared)'
);
shiftCloseAckAssert(
    strpos($loginScreen, '$showCloseAckOnly') !== false
        && strpos($loginScreen, 'unlock-shell') !== false,
    'close ack must render without PIN unlock shell'
);
shiftCloseAckAssert(
    strpos($loginScreen, 'متابعة لفتح نقطة البيع') === false,
    'close ack must not invite opening POS from the result modal'
);

$posBarcode = (string) file_get_contents(__DIR__ . '/../../pos_barcode.php');
shiftCloseAckAssert(
    strpos($posBarcode, '$hasShiftCloseAck') !== false
        && strpos($posBarcode, "pos_shift_close_result") !== false,
    'pin main-auth logout path must render close ack before redirecting to index.php'
);

echo "shift_close_ack_modal_contract_test: OK\n";
