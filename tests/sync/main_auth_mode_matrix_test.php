<?php

/**
 * Executable auth-mode matrix for POSMAIN_MAIN_AUTH_MODE resolution.
 * Does not rely on source-string greps.
 */

require_once __DIR__ . '/../../config/app_config.php';

$cases = [
    [
        'name' => 'branch explicit pin',
        'env' => ['POSMAIN_MAIN_AUTH_MODE' => 'pin'],
        'args' => ['local', false, 'branch', '0'],
        'expect' => 'pin',
    ],
    [
        'name' => 'branch explicit password',
        'env' => ['POSMAIN_MAIN_AUTH_MODE' => 'password'],
        'args' => ['local', false, 'branch', '0'],
        'expect' => 'password',
    ],
    [
        'name' => 'branch auto defaults to pin',
        'env' => ['POSMAIN_MAIN_AUTH_MODE' => 'auto'],
        'args' => ['local', false, 'branch', '0'],
        'expect' => 'pin',
    ],
    [
        'name' => 'cloud auto defaults to password',
        'env' => ['POSMAIN_MAIN_AUTH_MODE' => 'auto'],
        'args' => ['production', true, 'cloud', '0'],
        'expect' => 'password',
    ],
    [
        'name' => 'cloud explicit password',
        'env' => ['POSMAIN_MAIN_AUTH_MODE' => 'password'],
        'args' => ['production', true, 'cloud', '0'],
        'expect' => 'password',
    ],
    [
        'name' => 'router auto defaults to password',
        'env' => ['POSMAIN_MAIN_AUTH_MODE' => 'auto'],
        'args' => ['local', false, 'branch', '1'],
        'expect' => 'password',
    ],
    [
        'name' => 'cloud pin rejected',
        'env' => ['POSMAIN_MAIN_AUTH_MODE' => 'pin'],
        'args' => ['production', true, 'cloud', '0'],
        'expect_error' => 'MAIN_AUTH_MODE_UNSAFE',
    ],
    [
        'name' => 'router pin rejected',
        'env' => ['POSMAIN_MAIN_AUTH_MODE' => 'pin'],
        'args' => ['local', false, 'branch', '1'],
        'expect_error' => 'MAIN_AUTH_MODE_UNSAFE',
    ],
    [
        'name' => 'fake_cloud pin rejected',
        'env' => ['POSMAIN_MAIN_AUTH_MODE' => 'pin'],
        'args' => ['local', false, 'fake_cloud', '0'],
        'expect_error' => 'MAIN_AUTH_MODE_UNSAFE',
    ],
    [
        'name' => 'invalid mode rejected',
        'env' => ['POSMAIN_MAIN_AUTH_MODE' => 'totp'],
        'args' => ['local', false, 'branch', '0'],
        'expect_error' => 'MAIN_AUTH_MODE_INVALID',
    ],
];

foreach ($cases as $case) {
    mainAuthModeWithEnv($case['env'], function () use ($case) {
        $args = $case['args'];
        $caught = null;
        $mode = null;
        try {
            $mode = posmain_resolve_main_auth_mode($args[0], $args[1], $args[2], $args[3]);
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        if (!empty($case['expect_error'])) {
            if ($caught === null) {
                throw new RuntimeException($case['name'] . ': expected error ' . $case['expect_error'] . ', got mode ' . $mode);
            }
            if ($caught->getMessage() !== $case['expect_error']) {
                throw new RuntimeException(
                    $case['name'] . ': expected ' . $case['expect_error'] . ', got ' . $caught->getMessage()
                );
            }
            return;
        }

        if ($caught !== null) {
            throw $caught;
        }
        if ($mode !== $case['expect']) {
            throw new RuntimeException($case['name'] . ': expected ' . $case['expect'] . ', got ' . $mode);
        }
    });
}

// Env example contracts: branch documents pin+secret; cloud documents password and rejects pin.
$root = dirname(__DIR__, 2);
$envExample = (string) file_get_contents($root . '/.env.example');
mainAuthModeAssert(
    preg_match('/POSMAIN_ROLE=branch[\\s\\S]*?POSMAIN_MAIN_AUTH_MODE=pin/m', $envExample) === 1,
    '.env.example branch section must document POSMAIN_MAIN_AUTH_MODE=pin'
);
mainAuthModeAssert(
    preg_match('/POSMAIN_ROLE=branch[\\s\\S]*?POSMAIN_PIN_SECRET=/m', $envExample) === 1,
    '.env.example branch section must document POSMAIN_PIN_SECRET'
);
mainAuthModeAssert(
    preg_match('/POSMAIN_ROLE=cloud[\\s\\S]*?POSMAIN_MAIN_AUTH_MODE=password/m', $envExample) === 1,
    '.env.example cloud section must document POSMAIN_MAIN_AUTH_MODE=password'
);
mainAuthModeAssert(
    strpos($envExample, 'MAIN_AUTH_MODE_UNSAFE') !== false,
    '.env.example must document unsafe pin rejection on cloud/router'
);
mainAuthModeAssert(
    preg_match('/^#\\s*POSMAIN_MAIN_AUTH_MODE=pin\\s*$/m', $envExample) === 1,
    '.env.example branch docs must include commented POSMAIN_MAIN_AUTH_MODE=pin'
);
$cloudBlock = '';
if (preg_match('/# --- Hosted cloud[\\s\\S]*?(?=# --- Optional|\\z)/', $envExample, $cloudMatch) === 1) {
    $cloudBlock = $cloudMatch[0];
}
mainAuthModeAssert($cloudBlock !== '', '.env.example must include hosted cloud section');
mainAuthModeAssert(
    preg_match('/^#\\s*POSMAIN_MAIN_AUTH_MODE=pin\\s*$/m', $cloudBlock) !== 1,
    '.env.example cloud section must not set POSMAIN_MAIN_AUTH_MODE=pin'
);
mainAuthModeAssert(
    preg_match('/^#\\s*POSMAIN_MAIN_AUTH_MODE=password\\s*$/m', $cloudBlock) === 1,
    '.env.example cloud section must set POSMAIN_MAIN_AUTH_MODE=password'
);

$branchWorkerEnv = (string) file_get_contents($root . '/deploy/branch-worker/branch-worker.env.example');
mainAuthModeAssert(
    strpos($branchWorkerEnv, 'POSMAIN_MAIN_AUTH_MODE=pin') !== false,
    'branch-worker.env.example must set POSMAIN_MAIN_AUTH_MODE=pin'
);
mainAuthModeAssert(
    strpos($branchWorkerEnv, 'POSMAIN_PIN_SECRET=') !== false,
    'branch-worker.env.example must set POSMAIN_PIN_SECRET placeholder'
);

echo "main-auth-mode-matrix-ok\n";

function mainAuthModeWithEnv(array $values, callable $callback): void
{
    $keys = ['POSMAIN_MAIN_AUTH_MODE', 'POSMAIN_ROLE', 'POSMAIN_ROUTER_ENABLED', 'POSMAIN_ENV'];
    $original = [];
    foreach ($keys as $key) {
        $current = getenv($key);
        $original[$key] = $current === false ? null : $current;
        putenv($key);
        unset($_ENV[$key]);
    }
    foreach ($values as $key => $value) {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key]);
            continue;
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }
    try {
        $callback();
    } finally {
        foreach ($original as $key => $value) {
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key]);
                continue;
            }
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

function mainAuthModeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
