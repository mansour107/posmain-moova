<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Stepwise.php';
require_once __DIR__ . '/../classes/Updates/SchemaMigrationRunner.php';
require_once __DIR__ . '/../classes/Router/ShopRouter.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This target updater must run from the command line.\n");
    exit(1);
}

$options = getopt('', ['mode:', 'kind:', 'steps:', 'backup-file:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Database JSON is required on stdin. Use --mode=apply|verify --kind=default|shop|router --steps=/absolute/path [--backup-file=/absolute/file].\n");
    exit(0);
}

$mode = strtolower(trim((string) ($options['mode'] ?? '')));
$kind = strtolower(trim((string) ($options['kind'] ?? '')));
$steps = trim((string) ($options['steps'] ?? ''));
$backupFile = trim((string) ($options['backup-file'] ?? ''));
if (!in_array($mode, ['apply', 'verify'], true)) {
    fwrite(STDERR, "UPDATE_TARGET_MODE_INVALID\n");
    exit(1);
}
if (!in_array($kind, ['default', 'shop', 'router'], true)) {
    fwrite(STDERR, "UPDATE_TARGET_KIND_INVALID\n");
    exit(1);
}
if ($steps === '' || $steps[0] !== '/' || !is_dir($steps)) {
    fwrite(STDERR, "UPDATE_TARGET_STEPS_INVALID\n");
    exit(1);
}
if ($mode === 'apply' && ($backupFile === '' || !is_file($backupFile) || !is_readable($backupFile))) {
    fwrite(STDERR, "UPDATE_TARGET_BACKUP_REQUIRED\n");
    exit(1);
}

try {
    $payload = json_decode((string) file_get_contents('php://stdin'), true);
    $database = is_array($payload) ? (array) ($payload['database'] ?? []) : [];
    foreach (['host', 'name', 'user'] as $required) {
        if (trim((string) ($database[$required] ?? '')) === '') {
            throw new RuntimeException('UPDATE_TARGET_DATABASE_CONFIG_MISSING:' . $required);
        }
    }
    $conn = PosmainShopRouter::connectDatabase($database);
    try {
        if ($kind === 'router') {
            $router = new PosmainShopRouter();
            $applied = $mode === 'apply' ? $router->install($conn) : [];
            $missing = updateTargetMissingRouterTables($conn);
            $upgrades = $router->upgradeStatements($conn);
            $verification = [
                'ok' => $missing === [] && $upgrades === [],
                'router_missing_tables' => $missing,
                'router_upgrade_labels' => array_keys($upgrades),
            ];
            $result = [
                'ok' => $verification['ok'],
                'mode' => $mode,
                'kind' => $kind,
                'router_applied' => $applied,
                'stepwise_applied' => [],
                'schema_applied' => [],
                'verification' => $verification,
            ];
        } else {
            $stepwise = new Stepwise($conn, $steps, ['ledger_table' => 'stepwise_ledger']);
            $stepwiseApplied = [];
            $stepwiseSkipped = [];
            $schemaApplied = [];
            if ($mode === 'apply') {
                $before = $stepwise->plan();
                if ($before['drift'] !== []) {
                    throw new RuntimeException('STEPWISE_MIGRATION_DRIFT');
                }
                $stepwiseResult = $stepwise->apply('update_worker', true);
                $stepwiseApplied = $stepwiseResult['applied'];
                $stepwiseSkipped = $stepwiseResult['skipped'];
                $schemaApplied = (new PosmainSchemaMigrationRunner())->apply($conn, $backupFile);
            }
            $stepwisePlan = $stepwise->plan(false);
            $schemaPending = (new PosmainSchemaMigrationRunner())->pending($conn);
            $verification = [
                'ok' => $stepwisePlan['pending'] === []
                    && $stepwisePlan['drift'] === []
                    && $schemaPending === [],
                'stepwise_pending' => array_map(
                    static fn(array $step): string => (string) $step['step_key'],
                    $stepwisePlan['pending']
                ),
                'stepwise_drift' => $stepwisePlan['drift'],
                'schema_pending' => array_keys($schemaPending),
            ];
            $result = [
                'ok' => $verification['ok'],
                'mode' => $mode,
                'kind' => $kind,
                'router_applied' => [],
                'stepwise_applied' => $stepwiseApplied,
                'stepwise_skipped' => $stepwiseSkipped,
                'schema_applied' => $schemaApplied,
                'verification' => $verification,
            ];
        }
    } finally {
        $conn->close();
    }

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

function updateTargetMissingRouterTables(mysqli $conn): array
{
    $missing = [];
    foreach (['app_sessions', 'security_audit_log', 'failed_login_attempts', 'router_shops', 'router_login_aliases', 'router_branch_routes'] as $table) {
        $escaped = $conn->real_escape_string($table);
        if ($conn->query("SHOW TABLES LIKE '{$escaped}'")->num_rows === 0) {
            $missing[] = $table;
        }
    }
    return $missing;
}
