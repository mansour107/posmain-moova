<?php

putenv('POSMAIN_SESSION_LIFETIME_SECONDS');
putenv('POSMAIN_SESSION_IDLE_SECONDS');
putenv('POSMAIN_SESSION_ABSOLUTE_SECONDS');

require_once __DIR__ . '/../../includes/session_bootstrap.php';

$params = session_get_cookie_params();
sessionLifetimeAssert((int) ($params['lifetime'] ?? 0) === 86400, 'session cookie lifetime should default to 24 hours');
sessionLifetimeAssert((int) ini_get('session.gc_maxlifetime') === 86400, 'server session GC lifetime should default to 24 hours');
sessionLifetimeAssert(function_exists('posmain_session_lifetime_seconds'), 'session lifetime helper should exist');
sessionLifetimeAssert(posmain_session_lifetime_seconds() === 86400, 'session lifetime helper should return 24 hours by default');

$source = file_get_contents(__DIR__ . '/../../includes/session_bootstrap.php');
sessionLifetimeAssert(is_string($source), 'unable to read session bootstrap');
sessionLifetimeAssert(strpos($source, "posmain_env('POSMAIN_SESSION_IDLE_SECONDS', \$sessionLifetime)") !== false, 'idle expiry should default to configured session lifetime');
sessionLifetimeAssert(strpos($source, "posmain_env('POSMAIN_SESSION_ABSOLUTE_SECONDS', \$sessionLifetime)") !== false, 'absolute expiry should default to configured session lifetime');

echo "session-lifetime-contract-ok\n";

function sessionLifetimeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
