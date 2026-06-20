<?php

require_once __DIR__ . '/../../config/app_config.php';

moovaModeConfigTest('direct widget mode enables direct apply and keeps worker apply off', function () {
    moovaModeWithEnv([
        'POSMAIN_MOOVA_MODE' => 'direct_widget',
        'POSMAIN_MOOVA_APPLY_ENABLED' => null,
    ], function () {
        $config = posmain_app_config();

        moovaModeAssertSame('direct_widget', $config['moova']['mode'], 'mode mismatch');
        moovaModeAssertSame(true, $config['features']['moova_direct_apply'], 'direct apply should be enabled');
        moovaModeAssertSame(false, $config['features']['moova_queued_apply'], 'queued apply should be disabled');
        moovaModeAssertSame(false, $config['sync']['moova_apply_enabled'], 'worker apply should be disabled');
        moovaModeAssertSame(true, $config['moova']['queued_worker_requires_acceptance'], 'queued acceptance gate should be documented in config');
    });
});

moovaModeConfigTest('queued worker mode still requires explicit worker apply flag', function () {
    moovaModeWithEnv([
        'POSMAIN_MOOVA_MODE' => 'queued_worker',
        'POSMAIN_MOOVA_APPLY_ENABLED' => '0',
    ], function () {
        $config = posmain_app_config();

        moovaModeAssertSame('queued_worker', $config['moova']['mode'], 'mode mismatch');
        moovaModeAssertSame(false, $config['features']['moova_direct_apply'], 'direct apply should be disabled');
        moovaModeAssertSame(true, $config['features']['moova_queued_apply'], 'queued apply should be enabled');
        moovaModeAssertSame(false, $config['sync']['moova_apply_enabled'], 'worker apply should require POSMAIN_MOOVA_APPLY_ENABLED=1');
    });
});

moovaModeConfigTest('hybrid mode enables both direct and queued worker apply when flagged', function () {
    moovaModeWithEnv([
        'POSMAIN_MOOVA_MODE' => 'hybrid',
        'POSMAIN_MOOVA_APPLY_ENABLED' => '1',
    ], function () {
        $config = posmain_app_config();

        moovaModeAssertSame('hybrid', $config['moova']['mode'], 'mode mismatch');
        moovaModeAssertSame(true, $config['features']['moova_direct_apply'], 'direct apply should be enabled');
        moovaModeAssertSame(true, $config['features']['moova_queued_apply'], 'queued feature should be enabled');
        moovaModeAssertSame(true, $config['sync']['moova_apply_enabled'], 'worker apply should be enabled');
    });
});

moovaModeConfigTest('empty mode defaults to direct widget', function () {
    moovaModeWithEnv([
        'POSMAIN_MOOVA_MODE' => null,
        'POSMAIN_MOOVA_APPLY_ENABLED' => '0',
    ], function () {
        $config = posmain_app_config();

        moovaModeAssertSame('direct_widget', $config['moova']['mode'], 'empty mode should default to direct_widget');
        moovaModeAssertSame(true, $config['features']['moova_direct_apply'], 'direct apply should be enabled');
        moovaModeAssertSame(false, $config['sync']['moova_apply_enabled'], 'worker apply should stay disabled');
    });
});

echo "moova-mode-config-ok\n";

function moovaModeConfigTest(string $name, callable $test): void
{
    try {
        $test();
    } catch (Throwable $e) {
        fwrite(STDERR, $name . ': ' . $e->getMessage() . "\n");
        exit(1);
    }
}

function moovaModeWithEnv(array $values, callable $callback): void
{
    $keys = [
        'POSMAIN_MOOVA_MODE',
        'POSMAIN_MOOVA_APPLY_ENABLED',
    ];
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

function moovaModeAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
