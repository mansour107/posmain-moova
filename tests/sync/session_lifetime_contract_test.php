<?php

putenv('POSMAIN_SESSION_LIFETIME_SECONDS');
putenv('POSMAIN_SESSION_IDLE_SECONDS');
putenv('POSMAIN_SESSION_ABSOLUTE_SECONDS');
putenv('POSMAIN_SESSION_SAVE_PATH');

require_once __DIR__ . '/../../includes/session_bootstrap.php';

$params = session_get_cookie_params();
sessionLifetimeAssert((int) ($params['lifetime'] ?? 0) === 86400, 'session cookie lifetime should default to 24 hours');
sessionLifetimeAssert((int) ini_get('session.gc_maxlifetime') === 86400, 'server session GC lifetime should default to 24 hours');
sessionLifetimeAssert(function_exists('posmain_session_lifetime_seconds'), 'session lifetime helper should exist');
sessionLifetimeAssert(posmain_session_lifetime_seconds() === 86400, 'session lifetime helper should return 24 hours by default');
sessionLifetimeAssert(function_exists('posmain_session_save_path'), 'session save path helper should exist');
sessionLifetimeAssert(strpos(session_save_path(), DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'sessions') !== false, 'session save path should default to app-owned var/sessions');

$source = file_get_contents(__DIR__ . '/../../includes/session_bootstrap.php');
sessionLifetimeAssert(is_string($source), 'unable to read session bootstrap');
sessionLifetimeAssert(strpos($source, "posmain_env('POSMAIN_SESSION_IDLE_SECONDS', \$sessionLifetime)") !== false, 'idle expiry should default to configured session lifetime');
sessionLifetimeAssert(strpos($source, "posmain_env('POSMAIN_SESSION_ABSOLUTE_SECONDS', \$sessionLifetime)") !== false, 'absolute expiry should default to configured session lifetime');
sessionLifetimeAssert(empty(sessionLifetimeRawSessionStartFiles()), 'all first-party session entry points should load session_bootstrap.php instead of raw session_start()');

echo "session-lifetime-contract-ok\n";

function sessionLifetimeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function sessionLifetimeRawSessionStartFiles(): array
{
    $root = realpath(__DIR__ . '/../..');
    if ($root === false) {
        throw new RuntimeException('unable to resolve project root');
    }

    $ignored = [
        $root . '/PhpSpreadsheet',
        $root . '/barcodegr',
        $root . '/docs/assets',
        $root . '/plugins',
        $root . '/src/Twilio',
        $root . '/tests',
        $root . '/vendor',
    ];

    $violations = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        $path = $file->getPathname();
        if ($file->getExtension() !== 'php' || sessionLifetimePathIgnored($path, $ignored)) {
            continue;
        }

        if ($path === $root . '/includes/session_bootstrap.php') {
            continue;
        }

        $contents = file_get_contents($path);
        if (is_string($contents) && strpos($contents, 'session_start(') !== false) {
            $violations[] = str_replace($root . '/', '', $path);
        }
    }

    sort($violations);
    return $violations;
}

function sessionLifetimePathIgnored(string $path, array $ignoredRoots): bool
{
    foreach ($ignoredRoots as $ignoredRoot) {
        if (strpos($path, $ignoredRoot . DIRECTORY_SEPARATOR) === 0) {
            return true;
        }
    }

    return false;
}
