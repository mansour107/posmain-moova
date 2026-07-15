<?php

/**
 * CSRF tokens minted after session_write_close() must still persist.
 * header.php closes the session before page body/modals render.
 */

declare(strict_types=1);

ob_start();

require_once dirname(__DIR__, 2) . '/includes/csrf.php';

function csrfPersistAssert(bool $cond, string $msg): void
{
    if (!$cond) {
        ob_end_clean();
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
    echo "OK: {$msg}\n";
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    if (function_exists('posmain_session_start')) {
        posmain_session_start();
    } else {
        session_start();
    }
}

$_SESSION['userid'] = 99;
$_SESSION['login'] = 'csrf_persist_test';
unset($_SESSION['posmain_csrf_tokens']['shift_resolve_persist_test']);

session_write_close();
csrfPersistAssert(session_status() !== PHP_SESSION_ACTIVE, 'session is inactive after write_close');
csrfPersistAssert(session_id() !== '', 'session id remains after write_close');

$token = csrf_token('shift_resolve_persist_test');
csrfPersistAssert(strlen($token) >= 64, 'token minted after write_close');

// Fresh request simulation: reopen and verify stored token.
if (function_exists('posmain_session_start')) {
    posmain_session_start();
} else {
    session_start();
}

$stored = (string) ($_SESSION['posmain_csrf_tokens']['shift_resolve_persist_test'] ?? '');
csrfPersistAssert($stored === $token, 'minted token persists across session reopen');
csrfPersistAssert(verify_csrf_token($token, 'shift_resolve_persist_test'), 'persisted token verifies');

echo "csrf-persist-after-write-close-ok\n";
ob_end_flush();
