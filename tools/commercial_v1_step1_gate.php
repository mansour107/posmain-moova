<?php

/**
 * Commercial V1 Step 1 exit gate.
 *
 * Verifies:
 * - prohibited web utilities are gone stubs / absent from artifact
 * - release artifact excludes prohibited paths
 * - password reset service invariants
 * - optional live HTTP 404 proof against POSMAIN_TEST_HTTP_BASE
 */

if (PHP_SAPI !== 'cli') {
    require __DIR__ . '/../includes/http_gone.php';
}

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "ROOT_RESOLVE_FAILED\n");
    exit(1);
}

require_once $root . '/classes/PasswordService.php';
require_once $root . '/classes/Security/PasswordResetService.php';

$options = getopt('', ['skip-http', 'skip-artifact', 'json', 'help', 'evidence-dir:']);
if (isset($options['help'])) {
    echo "Usage: php tools/commercial_v1_step1_gate.php [--skip-http] [--skip-artifact] [--evidence-dir=DIR] [--json]\n";
    exit(0);
}

$checks = [];
$failures = [];

function step1_assert(array &$checks, array &$failures, string $name, bool $ok, string $detail = ''): void
{
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? (': ' . $detail) : '');
    }
}

$prohibited = require $root . '/config/prohibited_web_routes.php';
foreach ($prohibited as $relative) {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        step1_assert($checks, $failures, 'prohibited_absent:' . $relative, true, 'absent');
        continue;
    }
    $contents = (string) file_get_contents($path);
    $isGone = str_contains($contents, 'http_gone.php')
        || str_contains($contents, 'ENDPOINT_QUARANTINED')
        || str_contains($contents, 'production_guard_deny_route');
    $hasBackdoor = str_contains($contents, 'HORSTEC_SECURE')
        || str_contains($contents, 'username . "123"')
        || str_contains($contents, "username . '123'");
    step1_assert(
        $checks,
        $failures,
        'prohibited_safe:' . $relative,
        $isGone && !$hasBackdoor,
        $isGone ? ($hasBackdoor ? 'backdoor_markers' : 'safe_stub') : 'live_or_unguarded'
    );
}

step1_assert(
    $checks,
    $failures,
    'password_service_denies_legacy_when_forced',
    (static function (): bool {
        putenv('POSMAIN_DENY_LEGACY_PASSWORD_AUTH=1');
        $ok = PasswordService::denyLegacyPasswordAuth()
            && PasswordService::verifyPassword('secret', md5('secret')) === false;
        putenv('POSMAIN_DENY_LEGACY_PASSWORD_AUTH');
        return $ok;
    })()
);

step1_assert(
    $checks,
    $failures,
    'password_reset_service_source_exists',
    is_file($root . '/classes/Security/PasswordResetService.php')
        && is_file($root . '/tools/issue_password_reset.php')
        && is_file($root . '/tools/complete_password_reset.php')
        && is_file($root . '/tools/invalidate_legacy_password_hashes.php')
);

$artifact = null;
if (!isset($options['skip-artifact'])) {
    $out = $root . '/var/release/step1-gate-' . gmdate('YmdHis');
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/build_release_artifact.php')
        . ' --out=' . escapeshellarg($out) . ' --json';
    $output = shell_exec($cmd);
    $artifact = json_decode((string) $output, true);
    $artifactOk = is_array($artifact) && !empty($artifact['ok']);
    step1_assert(
        $checks,
        $failures,
        'release_artifact_build',
        $artifactOk,
        is_array($artifact) ? ('checksum=' . ($artifact['checksum_sha256'] ?? '')) : 'invalid_json'
    );
    step1_assert(
        $checks,
        $failures,
        'release_artifact_source_tree_clean',
        is_array($artifact) && !empty($artifact['source_tree_clean']),
        is_array($artifact)
            ? ('untracked_publishable=' . count($artifact['untracked_publishable_files'] ?? []))
            : 'invalid_json'
    );
    if ($artifactOk) {
        foreach ($prohibited as $relative) {
            $present = is_file($out . '/' . $relative);
            step1_assert(
                $checks,
                $failures,
                'artifact_excludes:' . $relative,
                !$present,
                $present ? 'present' : 'excluded'
            );
        }
        step1_assert(
            $checks,
            $failures,
            'artifact_excludes_tools',
            !is_dir($out . '/tools') && !is_dir($out . '/tests') && !is_dir($out . '/docs')
        );
    }
}

if (!isset($options['skip-http'])) {
    $base = rtrim((string) (getenv('POSMAIN_TEST_HTTP_BASE') ?: 'http://127.0.0.1:8010'), '/');
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 5,
            'ignore_errors' => true,
            'header' => "Accept: text/plain,application/json\r\n",
        ],
    ]);
    $sample = [
        'fix_passwords.php',
        'fix_passwords.php?key=HORSTEC_SECURE_2024',
        'delete_fix_file.php',
        'debug_db.php',
        'check_db_structure.php',
        'tools/issue_password_reset.php',
        'scripts/recover_owner_pin.php',
        'docs/goals/posmain-commercial-v1/goal.md',
    ];
    foreach ($sample as $path) {
        $headers = [];
        $http_response_header = [];
        @file_get_contents($base . '/' . ltrim($path, '/'), false, $context);
        $headers = $http_response_header ?? [];
        $status = 0;
        if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', (string) $headers[0], $m) === 1) {
            $status = (int) $m[1];
        }
        $ok = in_array($status, [404, 403, 410], true);
        step1_assert(
            $checks,
            $failures,
            'http_denied:' . $path,
            $ok,
            'status=' . $status
        );
    }
}

// Secrets / session / CSRF / uploads baseline checks.
$sessionBootstrap = (string) file_get_contents($root . '/includes/session_bootstrap.php');
step1_assert(
    $checks,
    $failures,
    'session_httponly_samesite',
    str_contains($sessionBootstrap, "'httponly' => true")
        && str_contains($sessionBootstrap, "'samesite' =>")
        && str_contains($sessionBootstrap, 'session_set_cookie_params(posmain_session_cookie_options())')
);
step1_assert(
    $checks,
    $failures,
    'session_regenerate_helper',
    str_contains($sessionBootstrap, 'function posmain_session_regenerate')
        && str_contains($sessionBootstrap, 'session_regenerate_id(true)')
);
step1_assert($checks, $failures, 'csrf_helper_exists', is_file($root . '/includes/csrf.php') && str_contains((string) file_get_contents($root . '/includes/csrf.php'), 'function require_csrf'));
step1_assert(
    $checks,
    $failures,
    'production_error_suppression',
    str_contains($sessionBootstrap, "ini_set('display_errors', '0')")
        && str_contains($sessionBootstrap, "ini_set('display_startup_errors', '0')")
);
$uploadsHtaccess = is_file($root . '/uploads/.htaccess') ? (string) file_get_contents($root . '/uploads/.htaccess') : '';
$router = is_file($root . '/router.php') ? (string) file_get_contents($root . '/router.php') : '';
step1_assert(
    $checks,
    $failures,
    'uploads_non_executable',
    str_contains($uploadsHtaccess, 'php')
        && (str_contains($uploadsHtaccess, 'denied') || str_contains($uploadsHtaccess, 'RemoveHandler'))
        && str_contains($router, "str_starts_with(\$pathOnly, 'uploads/')")
);
step1_assert($checks, $failures, 'router_exists', $router !== '');
$dockerfile = (string) file_get_contents($root . '/Dockerfile.posmain-php');
step1_assert($checks, $failures, 'docker_uses_router', str_contains($dockerfile, 'router.php'));
foreach ([
    'ajax/get_table_amount.php',
    'ajax/get_table_items.php',
    'ajax/get_table_order.php',
] as $relative) {
    $endpoint = (string) file_get_contents($root . '/' . $relative);
    step1_assert(
        $checks,
        $failures,
        'table_read_rbac:' . $relative,
        str_contains($endpoint, "rbac_guard_route('{$relative}')")
    );
}
$tableWorkspaceBrowser = (string) file_get_contents($root . '/tables.php');
step1_assert(
    $checks,
    $failures,
    'table_split_catalog_text_escaped',
    str_contains($tableWorkspaceBrowser, 'posTablePageEscapeHtml(item.name)')
        && !str_contains($tableWorkspaceBrowser, '<td>${item.name}</td>')
);

// Write-surface classification receipt.
$writeSurfaceCmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/audit_write_paths.php') . ' --json';
$writeSurfaceRaw = shell_exec($writeSurfaceCmd);
$writeSurface = json_decode((string) $writeSurfaceRaw, true);
step1_assert($checks, $failures, 'write_surface_scan_json', is_array($writeSurface) && isset($writeSurface['surfaces']));
$classificationReceipt = [
    'generated_at' => gmdate('c'),
    'source' => 'tools/audit_write_paths.php',
    'summary' => is_array($writeSurface) ? ($writeSurface['summary'] ?? []) : [],
    'surface_count' => is_array($writeSurface) ? count($writeSurface['surfaces'] ?? []) : 0,
    'classes' => [
        'production' => 'A — active production path',
        'internal_operator' => 'C — admin/operator maintenance',
        'migration_only' => 'D/CLI — tools, scripts, migrations',
        'prohibited' => 'config/prohibited_web_routes.php',
    ],
    'prohibited_routes' => $prohibited,
];
$configuredEvidenceDir = trim((string) ($options['evidence-dir'] ?? getenv('POSMAIN_EVIDENCE_DIR') ?: ''));
$evidenceDir = $configuredEvidenceDir !== ''
    ? ($configuredEvidenceDir[0] === '/' ? $configuredEvidenceDir : $root . '/' . ltrim($configuredEvidenceDir, '/'))
    : $root . '/var/evidence/posmain-commercial-v1';
@mkdir($evidenceDir, 0755, true);
file_put_contents(
    $evidenceDir . '/write-surface-classification-latest.json',
    json_encode($classificationReceipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
);
step1_assert($checks, $failures, 'write_surface_receipt_written', is_file($evidenceDir . '/write-surface-classification-latest.json'));

// Evidence bundle identity.
$gitCommit = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD 2>/dev/null'));
$gitBranch = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' branch --show-current 2>/dev/null'));
$composerLockHash = is_file($root . '/composer.lock') ? hash_file('sha256', $root . '/composer.lock') : null;
step1_assert($checks, $failures, 'git_commit_identity', $gitCommit !== '');
step1_assert($checks, $failures, 'composer_lock_present', is_file($root . '/composer.lock'));
step1_assert(
    $checks,
    $failures,
    'artifact_commit_matches_evidence',
    isset($options['skip-artifact'])
        || (is_array($artifact) && hash_equals($gitCommit, (string) ($artifact['source_commit'] ?? ''))),
    is_array($artifact) ? ('artifact_commit=' . ($artifact['source_commit'] ?? '')) : 'artifact_missing'
);
$receipt = [
    'gate' => 'commercial_v1_step1',
    'created_at' => gmdate('c'),
    'ok' => $failures === [],
    'failures' => $failures,
    'checks' => $checks,
    'artifact' => $artifact,
    'identity' => [
        'git_commit' => $gitCommit,
        'git_branch' => $gitBranch,
        'composer_lock_sha256' => $composerLockHash,
    ],
    'write_surface_receipt' => $evidenceDir . '/write-surface-classification-latest.json',
];
$receiptPath = $evidenceDir . '/step1-gate-' . gmdate('YmdHis') . '.json';
file_put_contents($receiptPath, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
file_put_contents(
    $evidenceDir . '/step1-bundle-latest.json',
    json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
);

if (!empty($options['json']) || array_key_exists('json', $options)) {
    echo json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo ($receipt['ok'] ? 'OK' : 'FAIL') . ' commercial-v1-step1 checks=' . count($checks)
        . ' failures=' . count($failures) . ' receipt=' . $receiptPath . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
}

exit($receipt['ok'] ? 0 : 1);
