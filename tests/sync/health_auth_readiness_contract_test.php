<?php

/**
 * Executable health/readiness contracts for main auth mode, role, and PIN secret readiness.
 * Invokes api/health.php in isolated subprocesses so secrets are never asserted by value.
 */

$root = dirname(__DIR__, 2);
$healthScript = $root . '/api/health.php';
$statusToken = 'health-auth-test-token-' . getmypid();

$source = (string) file_get_contents($healthScript);
foreach ([
    'posmainHealthMainAuthCheck',
    'main_auth_mode',
    'deployment_role',
    'pin_secret_ready',
    'MAIN_AUTH_MODE_UNSAFE',
    'PIN_SECRET_MISSING',
] as $needle) {
    healthAuthAssert(strpos($source, $needle) !== false, 'health.php missing ' . $needle);
}
// Response builders must report readiness only — never a pin_secret field or secret value.
healthAuthAssert(
    strpos($source, "posmain_pin_secret()") !== false
        && strpos($source, "'pin_secret'") === false
        && strpos($source, '"pin_secret"') === false,
    'health must report pin_secret_ready only, never pin_secret'
);

$branchReady = healthAuthRun($healthScript, [
    'POSMAIN_ENV' => 'local',
    'POSMAIN_ROLE' => 'branch',
    'POSMAIN_MAIN_AUTH_MODE' => 'pin',
    'POSMAIN_PIN_SECRET' => 'posmain-health-test-pin-secret-do-not-use',
    'POSMAIN_ROUTER_ENABLED' => '0',
    'POSMAIN_STATUS_TOKEN' => $statusToken,
    'POSMAIN_PRODUCTION_MODE' => '0',
    // Point DB at unlikely host so database check fails closed without depending on Docker.
    'POSMAIN_DB_HOST' => '127.0.0.1',
    'POSMAIN_DB_PORT' => '1',
    'POSMAIN_DB_USER' => 'nobody',
    'POSMAIN_DB_PASS' => 'nobody',
    'POSMAIN_DB_NAME' => 'posmain_health_auth_missing',
], '');

healthAuthAssert(isset($branchReady['payload']['main_auth_mode']), 'branch health must include main_auth_mode');
healthAuthAssert($branchReady['payload']['main_auth_mode'] === 'pin', 'branch pin mode expected');
healthAuthAssert($branchReady['payload']['deployment_role'] === 'branch', 'branch deployment_role expected');
healthAuthAssert(!empty($branchReady['payload']['pin_secret_ready']), 'branch pin_secret_ready should be true');
healthAuthAssert(
    empty($branchReady['payload']['checks']) || !healthAuthPayloadContainsSecret($branchReady['payload'], 'posmain-health-test-pin-secret-do-not-use'),
    'health must never echo PIN secret'
);
$mainAuthCheck = $branchReady['payload']['checks']['main_auth'] ?? null;
// Without detail token, checks may be omitted; top-level fields are required.
healthAuthAssert(
    array_key_exists('main_auth_mode', $branchReady['payload'])
        && array_key_exists('deployment_role', $branchReady['payload'])
        && array_key_exists('pin_secret_ready', $branchReady['payload']),
    'top-level auth readiness fields required'
);

$branchMissingSecret = healthAuthRun($healthScript, [
    'POSMAIN_ENV' => 'local',
    'POSMAIN_ROLE' => 'branch',
    'POSMAIN_MAIN_AUTH_MODE' => 'pin',
    'POSMAIN_PIN_SECRET' => '',
    'POSMAIN_ROUTER_ENABLED' => '0',
    'POSMAIN_STATUS_TOKEN' => $statusToken,
    'POSMAIN_PRODUCTION_MODE' => '0',
    'POSMAIN_DB_HOST' => '127.0.0.1',
    'POSMAIN_DB_PORT' => '1',
    'POSMAIN_DB_USER' => 'nobody',
    'POSMAIN_DB_PASS' => 'nobody',
    'POSMAIN_DB_NAME' => 'posmain_health_auth_missing',
], 'detail=1&token=' . rawurlencode($statusToken), ['clear_pin_secret' => true]);

healthAuthAssert($branchMissingSecret['payload']['ok'] === false, 'pin without secret must be unhealthy');
healthAuthAssert(empty($branchMissingSecret['payload']['pin_secret_ready']), 'pin_secret_ready false when missing');
$missingCheck = $branchMissingSecret['payload']['checks']['main_auth'] ?? [];
healthAuthAssert(($missingCheck['error'] ?? '') === 'PIN_SECRET_MISSING', 'missing secret error expected');
healthAuthAssert(
    !healthAuthPayloadContainsSecret($branchMissingSecret['payload'], 'posmain-health-test-pin-secret-do-not-use'),
    'missing-secret response must not leak secrets'
);

$cloudUnsafe = healthAuthRun($healthScript, [
    'POSMAIN_ENV' => 'production',
    'POSMAIN_ROLE' => 'cloud',
    'POSMAIN_MAIN_AUTH_MODE' => 'pin',
    'POSMAIN_PIN_SECRET' => 'posmain-health-test-pin-secret-do-not-use',
    'POSMAIN_ROUTER_ENABLED' => '0',
    'POSMAIN_STATUS_TOKEN' => $statusToken,
    'POSMAIN_PRODUCTION_MODE' => '1',
    'POSMAIN_DB_HOST' => '127.0.0.1',
    'POSMAIN_DB_PORT' => '1',
    'POSMAIN_DB_USER' => 'nobody',
    'POSMAIN_DB_PASS' => 'nobody',
    'POSMAIN_DB_NAME' => 'posmain_health_auth_missing',
], 'detail=1&token=' . rawurlencode($statusToken));

healthAuthAssert($cloudUnsafe['payload']['ok'] === false, 'cloud+pin must be unhealthy');
healthAuthAssert(($cloudUnsafe['payload']['deployment_role'] ?? '') === 'cloud', 'cloud role reported');
$unsafeCheck = $cloudUnsafe['payload']['checks']['main_auth'] ?? [];
healthAuthAssert(($unsafeCheck['error'] ?? '') === 'MAIN_AUTH_MODE_UNSAFE', 'cloud+pin must report MAIN_AUTH_MODE_UNSAFE');

$routerUnsafe = healthAuthRun($healthScript, [
    'POSMAIN_ENV' => 'local',
    'POSMAIN_ROLE' => 'branch',
    'POSMAIN_MAIN_AUTH_MODE' => 'pin',
    'POSMAIN_PIN_SECRET' => 'posmain-health-test-pin-secret-do-not-use',
    'POSMAIN_ROUTER_ENABLED' => '1',
    'POSMAIN_STATUS_TOKEN' => $statusToken,
    'POSMAIN_PRODUCTION_MODE' => '0',
    'POSMAIN_DB_HOST' => '127.0.0.1',
    'POSMAIN_DB_PORT' => '1',
    'POSMAIN_DB_USER' => 'nobody',
    'POSMAIN_DB_PASS' => 'nobody',
    'POSMAIN_DB_NAME' => 'posmain_health_auth_missing',
], 'detail=1&token=' . rawurlencode($statusToken));

healthAuthAssert($routerUnsafe['payload']['ok'] === false, 'router+pin must be unhealthy');
$routerCheck = $routerUnsafe['payload']['checks']['main_auth'] ?? [];
healthAuthAssert(($routerCheck['error'] ?? '') === 'MAIN_AUTH_MODE_UNSAFE', 'router+pin must report MAIN_AUTH_MODE_UNSAFE');

$cloudPassword = healthAuthRun($healthScript, [
    'POSMAIN_ENV' => 'production',
    'POSMAIN_ROLE' => 'cloud',
    'POSMAIN_MAIN_AUTH_MODE' => 'password',
    'POSMAIN_PIN_SECRET' => '',
    'POSMAIN_ROUTER_ENABLED' => '1',
    'POSMAIN_STATUS_TOKEN' => $statusToken,
    'POSMAIN_PRODUCTION_MODE' => '1',
    'POSMAIN_DB_HOST' => '127.0.0.1',
    'POSMAIN_DB_PORT' => '1',
    'POSMAIN_DB_USER' => 'nobody',
    'POSMAIN_DB_PASS' => 'nobody',
    'POSMAIN_DB_NAME' => 'posmain_health_auth_missing',
], 'detail=1&token=' . rawurlencode($statusToken), ['clear_pin_secret' => true]);

healthAuthAssert(($cloudPassword['payload']['main_auth_mode'] ?? '') === 'password', 'cloud password mode expected');
$cloudAuth = $cloudPassword['payload']['checks']['main_auth'] ?? [];
healthAuthAssert(!empty($cloudAuth['ok']), 'cloud password main_auth check should pass even if DB is down');

echo "health-auth-readiness-contract-ok\n";

/**
 * @return array{code:int, payload:array}
 */
function healthAuthRun(string $script, array $env, string $queryString, array $options = []): array
{
    $cmdEnv = [];
    foreach ($_ENV as $key => $value) {
        if (is_string($key)) {
            $cmdEnv[$key] = (string) $value;
        }
    }
    foreach (['POSMAIN_PIN_SECRET', 'POSMAIN_MAIN_AUTH_MODE', 'POSMAIN_ROLE', 'POSMAIN_ROUTER_ENABLED', 'POSMAIN_ENV', 'POSMAIN_STATUS_TOKEN', 'POSMAIN_DB_HOST', 'POSMAIN_DB_PORT', 'POSMAIN_DB_USER', 'POSMAIN_DB_PASS', 'POSMAIN_DB_NAME', 'POSMAIN_PRODUCTION_MODE'] as $key) {
        unset($cmdEnv[$key]);
    }
    foreach ($env as $key => $value) {
        $cmdEnv[$key] = (string) $value;
    }
    if (!empty($options['clear_pin_secret'])) {
        // Whitespace-only keeps the env key present (blocks .env refill) but trims to missing.
        $cmdEnv['POSMAIN_PIN_SECRET'] = ' ';
    }
    $cmdEnv['QUERY_STRING'] = $queryString;
    $cmdEnv['POSMAIN_DISABLE_BRANCH_ENV_FILE_FALLBACK'] = '1';

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open([PHP_BINARY, $script], $descriptors, $pipes, dirname($script, 2), $cmdEnv);
    if (!is_resource($process)) {
        throw new RuntimeException('failed to start health.php subprocess');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    $payload = json_decode((string) $stdout, true);
    if (!is_array($payload)) {
        throw new RuntimeException('health.php did not return JSON: stdout=' . $stdout . ' stderr=' . $stderr . ' code=' . $code);
    }

    return ['code' => $code, 'payload' => $payload, 'stderr' => $stderr];
}

function healthAuthPayloadContainsSecret(array $payload, string $secret): bool
{
    $encoded = json_encode($payload);
    return is_string($encoded) && $secret !== '' && strpos($encoded, $secret) !== false;
}

function healthAuthAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
