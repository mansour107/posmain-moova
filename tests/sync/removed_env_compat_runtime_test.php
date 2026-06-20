<?php

/**
 * Runtime checks for env cleanup: legacy names must not drive config; canonical names must.
 */

$root = dirname(__DIR__, 2);

if (($argv[1] ?? '') === '--child-status') {
    removedEnvCompatRunStatusChild((string) ($argv[2] ?? ''));
    exit(0);
}

require_once $root . '/config/app_config.php';
require_once $root . '/classes/Sync/SyncRuntimeDbConfigFile.php';

removedEnvCompatTest('legacy recipe flag alone does not enable recipes', function () {
    removedEnvCompatWithEnv([
        'POSMAIN_ENABLE_RECIPES' => '1',
        'POSMAIN_RECIPE_MODE' => null,
    ], function () {
        $config = posmain_app_config();
        removedEnvCompatAssertSame(false, $config['recipe']['enabled'], 'recipe enabled');
        removedEnvCompatAssertSame(false, $config['features']['recipes'], 'features.recipes');
        removedEnvCompatAssertSame('off', $config['recipe']['mode'], 'recipe mode');
    });
});

removedEnvCompatTest('canonical recipe mode enables recipes', function () {
    removedEnvCompatWithEnv([
        'POSMAIN_ENABLE_RECIPES' => '0',
        'POSMAIN_RECIPE_MODE' => 'consume_pilot',
    ], function () {
        $config = posmain_app_config();
        removedEnvCompatAssertSame(true, $config['recipe']['enabled'], 'recipe enabled');
        removedEnvCompatAssertSame('consume_pilot', $config['recipe']['mode'], 'recipe mode');
    });
});

removedEnvCompatTest('legacy sync outbox alias is ignored', function () {
    removedEnvCompatWithEnv([
        'POSMAIN_ENABLE_SYNC_OUTBOX' => '0',
        'POSMAIN_SYNC_OUTBOX_ENABLED' => null,
    ], function () {
        $config = posmain_app_config();
        removedEnvCompatAssertSame(true, $config['features']['sync_outbox'], 'default outbox should stay on');
    });
});

removedEnvCompatTest('canonical sync outbox flag is honored', function () {
    removedEnvCompatWithEnv([
        'POSMAIN_ENABLE_SYNC_OUTBOX' => '1',
        'POSMAIN_SYNC_OUTBOX_ENABLED' => '0',
    ], function () {
        $config = posmain_app_config();
        removedEnvCompatAssertSame(false, $config['features']['sync_outbox'], 'outbox should be off');
    });
});

removedEnvCompatTest('legacy cloud sync alias is ignored', function () {
    removedEnvCompatWithEnv([
        'POSMAIN_ENABLE_CLOUD_SYNC' => '1',
        'POSMAIN_BRANCH_SYNC_ENABLED' => null,
    ], function () {
        $config = posmain_app_config();
        removedEnvCompatAssertSame(false, $config['features']['cloud_sync'], 'cloud sync default off');
    });
});

removedEnvCompatTest('canonical branch sync flag is honored', function () {
    removedEnvCompatWithEnv([
        'POSMAIN_ENABLE_CLOUD_SYNC' => '0',
        'POSMAIN_BRANCH_SYNC_ENABLED' => '1',
    ], function () {
        $config = posmain_app_config();
        removedEnvCompatAssertSame(true, $config['features']['cloud_sync'], 'cloud sync on');
    });
});

removedEnvCompatTest('legacy moova direct apply flag is ignored', function () {
    removedEnvCompatWithEnv([
        'POSMAIN_MOOVA_MODE' => 'disabled',
        'POSMAIN_ENABLE_MOOVA_DIRECT_APPLY' => '1',
        'POSMAIN_ENABLE_MOOVA_QUEUED_APPLY' => '1',
    ], function () {
        $config = posmain_app_config();
        removedEnvCompatAssertSame('disabled', $config['moova']['mode'], 'moova mode');
        removedEnvCompatAssertSame(false, $config['features']['moova_direct_apply'], 'direct apply');
        removedEnvCompatAssertSame(false, $config['features']['moova_queued_apply'], 'queued apply');
    });
});

removedEnvCompatTest('legacy sync status token is ignored', function () {
    removedEnvCompatWithEnv([
        'POSMAIN_SYNC_STATUS_TOKEN' => 'legacy-token',
        'POSMAIN_STATUS_TOKEN' => null,
    ], function () {
        $config = posmain_app_config();
        removedEnvCompatAssertSame('', $config['status_token'], 'status token empty');
    });
});

removedEnvCompatTest('canonical status token is honored', function () {
    removedEnvCompatWithEnv([
        'POSMAIN_SYNC_STATUS_TOKEN' => 'legacy-token',
        'POSMAIN_STATUS_TOKEN' => 'canonical-token',
    ], function () {
        $config = posmain_app_config();
        removedEnvCompatAssertSame('canonical-token', $config['status_token'], 'status token');
    });
});

removedEnvCompatTest('sync db alias is ignored; canonical db name is used', function () {
    removedEnvCompatWithEnv([
        'POSMAIN_SYNC_DB_NAME' => 'sync_alias_db',
        'POSMAIN_DB_NAME' => 'canonical_db',
    ], function () {
        $config = posmain_app_config();
        removedEnvCompatAssertSame('canonical_db', $config['database']['name'], 'database name');
    });
});

removedEnvCompatTest('charset env vars are ignored; utf8mb4 is fixed', function () {
    removedEnvCompatWithEnv([
        'POSMAIN_DB_CHARSET' => 'latin1',
        'POSMAIN_ROUTER_DB_CHARSET' => 'latin1',
        'POSMAIN_ROUTER_ENABLED' => '1',
    ], function () {
        $config = posmain_app_config();
        removedEnvCompatAssertSame('utf8mb4', $config['database']['charset'], 'shop charset');
        removedEnvCompatAssertSame('utf8mb4', $config['router']['database']['charset'], 'router charset');
        removedEnvCompatAssertSame(true, $config['router']['require_encryption'], 'router encryption');
    });
});

removedEnvCompatTest('router require encryption env is ignored', function () {
    removedEnvCompatWithEnv([
        'POSMAIN_ROUTER_REQUIRE_ENCRYPTION' => '0',
        'POSMAIN_ROUTER_ENABLED' => '1',
    ], function () {
        $config = posmain_app_config();
        removedEnvCompatAssertSame(true, $config['router']['require_encryption'], 'require encryption');
    });
});

removedEnvCompatTest('db config export omits removed charset line', function () {
    $export = (new SyncRuntimeDbConfigFile())->exportEnv([
        'host' => 'db.example',
        'port' => 3306,
        'name' => 'shop_a',
        'user' => 'posmain',
        'pass' => 'secret',
        'charset' => 'utf8mb4',
    ]);
    removedEnvCompatAssert(strpos($export, 'POSMAIN_DB_HOST=db.example') !== false, 'export host');
    removedEnvCompatAssert(strpos($export, 'POSMAIN_DB_CHARSET') === false, 'export must not include charset');
});

removedEnvCompatTest('status endpoint returns 503 when token not configured', function () use ($root) {
    $emptyBranchEnv = tempnam(sys_get_temp_dir(), 'posmain-empty-branch-env-');
    file_put_contents($emptyBranchEnv, "# empty\n");
    $payload = removedEnvCompatInvokeStatusEndpoint($root, [
        'POSMAIN_STATUS_TOKEN' => null,
        'POSMAIN_SYNC_STATUS_TOKEN' => 'legacy-should-not-work',
        'POSMAIN_BRANCH_WORKER_ENV_FILE' => $emptyBranchEnv,
        'POSMAIN_DISABLE_UI_RUNTIME_CONFIG' => '1',
        'POSMAIN_DISABLE_BRANCH_ENV_FILE_FALLBACK' => '1',
    ], 'no-token', 'GET');
    @unlink($emptyBranchEnv);
    removedEnvCompatAssertSame(503, $payload['http_code'], 'http code');
    removedEnvCompatAssertSame('status_token_not_configured', $payload['json']['error'] ?? '', 'error code');
});

removedEnvCompatTest('status endpoint rejects legacy token when canonical unset', function () use ($root) {
    $emptyBranchEnv = tempnam(sys_get_temp_dir(), 'posmain-empty-branch-env-');
    file_put_contents($emptyBranchEnv, "# empty\n");
    $payload = removedEnvCompatInvokeStatusEndpoint($root, [
        'POSMAIN_STATUS_TOKEN' => null,
        'POSMAIN_SYNC_STATUS_TOKEN' => 'legacy-only',
        'POSMAIN_BRANCH_WORKER_ENV_FILE' => $emptyBranchEnv,
        'POSMAIN_DISABLE_UI_RUNTIME_CONFIG' => '1',
        'POSMAIN_DISABLE_BRANCH_ENV_FILE_FALLBACK' => '1',
    ], 'legacy-only', 'GET');
    @unlink($emptyBranchEnv);
    removedEnvCompatAssertSame(503, $payload['http_code'], 'http code without canonical token');
});

removedEnvCompatTest('status endpoint rejects wrong bearer token', function () use ($root) {
    $emptyBranchEnv = tempnam(sys_get_temp_dir(), 'posmain-empty-branch-env-');
    file_put_contents($emptyBranchEnv, "# empty\n");
    $payload = removedEnvCompatInvokeStatusEndpoint($root, [
        'POSMAIN_STATUS_TOKEN' => 'expected-token',
        'POSMAIN_BRANCH_WORKER_ENV_FILE' => $emptyBranchEnv,
        'POSMAIN_DISABLE_UI_RUNTIME_CONFIG' => '1',
        'POSMAIN_DISABLE_BRANCH_ENV_FILE_FALLBACK' => '1',
    ], 'wrong-token', 'GET');
    @unlink($emptyBranchEnv);
    removedEnvCompatAssertSame(403, $payload['http_code'], 'http code');
    removedEnvCompatAssertSame('forbidden', $payload['json']['error'] ?? '', 'error code');
});

removedEnvCompatTest('status endpoint accepts bearer canonical token', function () use ($root) {
    $emptyBranchEnv = tempnam(sys_get_temp_dir(), 'posmain-empty-branch-env-');
    file_put_contents($emptyBranchEnv, "# empty\n");
    $payload = removedEnvCompatInvokeStatusEndpoint($root, [
        'POSMAIN_STATUS_TOKEN' => 'expected-token',
        'POSMAIN_DB_PORT' => getenv('POSMAIN_TEST_MYSQL_PORT') ?: '3307',
        'POSMAIN_BRANCH_WORKER_ENV_FILE' => $emptyBranchEnv,
        'POSMAIN_DISABLE_UI_RUNTIME_CONFIG' => '1',
        'POSMAIN_DISABLE_BRANCH_ENV_FILE_FALLBACK' => '1',
    ], 'expected-token', 'GET');
    @unlink($emptyBranchEnv);
    if ($payload['http_code'] === 503 && ($payload['json']['error'] ?? '') === 'db_connect_failed') {
        fwrite(STDERR, "skipped status success case: local test DB unavailable\n");
        return;
    }
    removedEnvCompatAssert(in_array($payload['http_code'], [200, 503], true), 'http code should be 200 or db-health 503');
    removedEnvCompatAssertSame('sync_status', $payload['json']['api'] ?? '', 'api marker');
    removedEnvCompatAssert(!array_key_exists('user', $payload['json']['database'] ?? []), 'db user redacted');
});

removedEnvCompatTest('status endpoint accepts X-POSMAIN-STATUS-TOKEN header', function () use ($root) {
    $emptyBranchEnv = tempnam(sys_get_temp_dir(), 'posmain-empty-branch-env-');
    file_put_contents($emptyBranchEnv, "# empty\n");
    $payload = removedEnvCompatInvokeStatusEndpoint($root, [
        'POSMAIN_STATUS_TOKEN' => 'header-token',
        'POSMAIN_DB_PORT' => getenv('POSMAIN_TEST_MYSQL_PORT') ?: '3307',
        'POSMAIN_BRANCH_WORKER_ENV_FILE' => $emptyBranchEnv,
        'POSMAIN_DISABLE_UI_RUNTIME_CONFIG' => '1',
        'POSMAIN_DISABLE_BRANCH_ENV_FILE_FALLBACK' => '1',
    ], 'header-token', 'GET', 'X-POSMAIN-STATUS-TOKEN');
    @unlink($emptyBranchEnv);
    if ($payload['http_code'] === 503 && ($payload['json']['error'] ?? '') === 'db_connect_failed') {
        fwrite(STDERR, "skipped header token success case: local test DB unavailable\n");
        return;
    }
    removedEnvCompatAssert(in_array($payload['http_code'], [200, 503], true), 'http code');
    removedEnvCompatAssertSame('sync_status', $payload['json']['api'] ?? '', 'api marker');
});

echo "removed-env-compat-runtime-ok\n";

function removedEnvCompatRunStatusChild(string $scenario): void
{
    $parts = explode('|', $scenario, 4);
    $envJson = $parts[0] ?? '{}';
    $token = $parts[1] ?? '';
    $method = strtoupper($parts[2] ?? 'GET');
    $headerMode = $parts[3] ?? 'Authorization';

    $env = json_decode($envJson, true);
    if (!is_array($env)) {
        fwrite(STDERR, "invalid child env json\n");
        exit(1);
    }

    foreach ($env as $key => $value) {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key]);
            continue;
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = (string) $value;
    }

    $_SERVER['REQUEST_METHOD'] = $method;
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_POSMAIN_STATUS_TOKEN']);
    if ($token !== '') {
        if ($headerMode === 'X-POSMAIN-STATUS-TOKEN') {
            $_SERVER['HTTP_X_POSMAIN_STATUS_TOKEN'] = $token;
        } else {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
    }

    require dirname(__DIR__, 2) . '/api/sync/status.php';
}

function removedEnvCompatInvokeStatusEndpoint(string $root, array $env, string $token, string $method, string $headerMode = 'Authorization'): array
{
    $scenario = json_encode($env, JSON_UNESCAPED_SLASHES)
        . '|' . $token
        . '|' . $method
        . '|' . $headerMode;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --child-status ' . escapeshellarg($scenario);
    $output = shell_exec($cmd);
    if (!is_string($output) || trim($output) === '') {
        throw new RuntimeException('status child produced no output');
    }
    $json = json_decode(trim($output), true);
    if (!is_array($json)) {
        throw new RuntimeException('status child returned invalid json: ' . $output);
    }

    return [
        'http_code' => removedEnvCompatInferStatusHttpCode($json),
        'json' => $json,
        'raw' => trim($output),
    ];
}

function removedEnvCompatInferStatusHttpCode(array $json): int
{
    $error = (string) ($json['error'] ?? '');
    if ($error === 'method_not_allowed') {
        return 405;
    }
    if ($error === 'forbidden') {
        return 403;
    }
    if ($error === 'status_token_not_configured' || $error === 'db_connect_failed') {
        return 503;
    }
    if (($json['api'] ?? '') === 'sync_status') {
        return !empty($json['ok']) && !empty($json['healthy']) ? 200 : 503;
    }

    return !empty($json['ok']) ? 200 : 503;
}

function removedEnvCompatTest(string $name, callable $test): void
{
    try {
        $test();
    } catch (Throwable $e) {
        fwrite(STDERR, $name . ': ' . $e->getMessage() . "\n");
        exit(1);
    }
}

function removedEnvCompatWithEnv(array $values, callable $callback): void
{
    $keys = array_unique(array_merge(
        array_keys($values),
        [
            'POSMAIN_ENABLE_RECIPES',
            'POSMAIN_RECIPE_MODE',
            'POSMAIN_ENABLE_SYNC_OUTBOX',
            'POSMAIN_SYNC_OUTBOX_ENABLED',
            'POSMAIN_ENABLE_CLOUD_SYNC',
            'POSMAIN_BRANCH_SYNC_ENABLED',
            'POSMAIN_MOOVA_MODE',
            'POSMAIN_ENABLE_MOOVA_DIRECT_APPLY',
            'POSMAIN_ENABLE_MOOVA_QUEUED_APPLY',
            'POSMAIN_MOOVA_APPLY_ENABLED',
            'POSMAIN_SYNC_STATUS_TOKEN',
            'POSMAIN_STATUS_TOKEN',
            'POSMAIN_SYNC_DB_NAME',
            'POSMAIN_DB_NAME',
            'POSMAIN_DB_CHARSET',
            'POSMAIN_ROUTER_DB_CHARSET',
            'POSMAIN_ROUTER_REQUIRE_ENCRYPTION',
            'POSMAIN_ROUTER_ENABLED',
        'POSMAIN_DISABLE_UI_RUNTIME_CONFIG',
        'POSMAIN_DISABLE_BRANCH_ENV_FILE_FALLBACK',
        'POSMAIN_BRANCH_WORKER_ENV_FILE',
        ]
    ));

    $original = [];
    foreach ($keys as $key) {
        $current = getenv($key);
        $original[$key] = $current === false ? null : $current;
        putenv($key);
        unset($_ENV[$key]);
    }

    putenv('POSMAIN_DISABLE_UI_RUNTIME_CONFIG=1');
    $_ENV['POSMAIN_DISABLE_UI_RUNTIME_CONFIG'] = '1';
    putenv('POSMAIN_DISABLE_BRANCH_ENV_FILE_FALLBACK=1');
    $_ENV['POSMAIN_DISABLE_BRANCH_ENV_FILE_FALLBACK'] = '1';

    $emptyBranchEnv = tempnam(sys_get_temp_dir(), 'posmain-empty-branch-env-');
    if ($emptyBranchEnv === false) {
        throw new RuntimeException('unable to create empty branch env file');
    }
    file_put_contents($emptyBranchEnv, "# empty\n");
    putenv('POSMAIN_BRANCH_WORKER_ENV_FILE=' . $emptyBranchEnv);
    $_ENV['POSMAIN_BRANCH_WORKER_ENV_FILE'] = $emptyBranchEnv;

    foreach ($values as $key => $value) {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key]);
            continue;
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = (string) $value;
    }

    try {
        $callback();
    } finally {
        @unlink($emptyBranchEnv);
        foreach ($original as $key => $value) {
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key]);
            } else {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
    }
}

function removedEnvCompatAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removedEnvCompatAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}
