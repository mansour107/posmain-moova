<?php

/**
 * Stale sessionStorage pos_shift_closed must not force logout on a fresh
 * server-rendered selling surface (e.g. after manager takeover / force-close).
 */

$source = (string) file_get_contents(__DIR__ . '/../../includes/pos_session_guard.php');
$endOverride = (string) file_get_contents(__DIR__ . '/../../do/do_end_drawer_override.php');

function posSessionGuardStaleCloseAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

posSessionGuardStaleCloseAssert($source !== '', 'pos_session_guard readable');

posSessionGuardStaleCloseAssert(
    strpos($source, 'backFwd') !== false
        && strpos($source, "sessionStorage.getItem('pos_shift_closed') === '1'") !== false
        && preg_match('/backFwd\s*\n?\s*&&\s*\n?\s*sessionStorage\.getItem\(\'pos_shift_closed\'\)/', $source) === 1,
    'pageshow fast-path must require back-forward navigation before forcing reauth'
);

posSessionGuardStaleCloseAssert(
    strpos($source, 'Fresh server render of an open selling surface') !== false
        || strpos($source, 'clearShiftClosedFlag()') !== false,
    'fresh selling surface must clear stale pos_shift_closed flag'
);

posSessionGuardStaleCloseAssert(
    strpos($endOverride, "'redirect' => 'pos_barcode.php?logout=1'") === false
        && strpos($endOverride, "'redirect' => 'pos_barcode.php'") !== false,
    'end override must not full-logout via logout=1 under PIN main auth'
);

echo "pos-session-guard-stale-close-flag-contract-ok\n";
