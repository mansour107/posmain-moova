<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Sync/BranchWorkerDaemon.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', [
    'json',
    'help',
    'skip-db',
    'env-file:',
    'backup-file:',
    'moova-acceptance-file:',
    'max-backup-age-hours::',
    'max-moova-acceptance-age-hours::',
]);

if (isset($options['help'])) {
    branchGoLivePrintUsage();
    exit(0);
}

$envFile = isset($options['env-file']) ? branchGoLiveLoadEnvFile((string) $options['env-file']) : null;
$config = posmain_app_config();
$result = branchGoLiveReadiness($config, $options, $envFile);

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    branchGoLivePrintHuman($result);
}

exit(!empty($result['ready_for_go_live']) ? 0 : 2);

function branchGoLivePrintUsage(): void
{
    fwrite(STDOUT, "Usage: php tools/branch_go_live_readiness.php [--json] [--skip-db] [--env-file=/etc/posmain/branch-worker.env] [--moova-acceptance-file=/absolute/path/to/acceptance.md] [--max-backup-age-hours=24] [--max-moova-acceptance-age-hours=24] --backup-file=/absolute/path/to/posmain-backup.sql\n");
    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "Checks branch worker go-live readiness without installing services, running migrations, or restoring data.\n");
    fwrite(STDOUT, "A branch is ready only when a readable backup file is provided, daemon preflight passes, and branch/cloud sync config is present.\n");
    fwrite(STDOUT, "When POSMAIN_MOOVA_APPLY_ENABLED=1, a readable Moova cashier acceptance file is also required.\n");
    fwrite(STDOUT, "Backup and Moova acceptance evidence must be fresh by default; use max age 0 only for an explicit operator override.\n");
}

function branchGoLiveReadiness(array $config, array $options, ?array $envFile = null): array
{
    $daemon = new BranchWorkerDaemon();
    $backupFile = isset($options['backup-file']) ? trim((string) $options['backup-file']) : '';
    $maxBackupAgeHours = branchGoLiveOptionInt($options, 'max-backup-age-hours', 24, 0, 8760);
    $maxMoovaAcceptanceAgeHours = branchGoLiveOptionInt($options, 'max-moova-acceptance-age-hours', 24, 0, 8760);
    $checks = [];
    $blockers = [];
    $warnings = [];

    $checks['daemon_jobs'] = [
        'ok' => true,
        'jobs' => $daemon->describeJobs(),
    ];

    if ($envFile !== null) {
        $checks['env_file'] = $envFile;
        foreach ($envFile['blockers'] ?? [] as $blocker) {
            $blockers[] = (string) $blocker;
        }
        foreach ($envFile['warnings'] ?? [] as $warning) {
            $warnings[] = (string) $warning;
        }
    }

    $checks['backup_file'] = branchGoLiveBackupFileCheck($backupFile, $maxBackupAgeHours);
    if (empty($checks['backup_file']['ok'])) {
        $blockers[] = (string) $checks['backup_file']['blocker'];
    }

    $checks['configuration'] = branchGoLiveConfigCheck($config);
    foreach ($checks['configuration']['blockers'] as $blocker) {
        $blockers[] = (string) $blocker;
    }
    foreach ($checks['configuration']['warnings'] as $warning) {
        $warnings[] = (string) $warning;
    }

    $moovaAcceptanceFile = isset($options['moova-acceptance-file']) ? trim((string) $options['moova-acceptance-file']) : '';
    $checks['moova_acceptance'] = branchGoLiveMoovaAcceptanceCheck($config, $moovaAcceptanceFile, $maxMoovaAcceptanceAgeHours);
    if (empty($checks['moova_acceptance']['ok']) && !empty($checks['moova_acceptance']['blocker'])) {
        $blockers[] = (string) $checks['moova_acceptance']['blocker'];
    }
    if (!empty($checks['moova_acceptance']['warning'])) {
        $warnings[] = (string) $checks['moova_acceptance']['warning'];
    }

    $checks['commands'] = branchGoLiveCommandTemplates($config, $backupFile);

    if (isset($options['skip-db'])) {
        $checks['daemon_preflight'] = [
            'ok' => false,
            'skipped' => true,
            'blocker' => 'database_check_skipped',
            'message' => 'Database preflight was skipped by --skip-db; do not use this result for shop go-live.',
        ];
        $blockers[] = 'database_check_skipped';
    } else {
        try {
            $conn = posmain_db_connect();
            $preflight = $daemon->preflight($conn, $config);
            $conn->close();
            $checks['daemon_preflight'] = $preflight;

            if (empty($preflight['ok'])) {
                $blockers[] = 'sync_schema_pending';
            }
            foreach ($preflight['warnings'] ?? [] as $warning) {
                $warnings[] = (string) $warning;
            }
        } catch (Throwable $e) {
            $checks['daemon_preflight'] = [
                'ok' => false,
                'error' => 'db_connect_failed',
                'message' => $e->getMessage(),
            ];
            $blockers[] = 'database_unreachable';
        }
    }

    $blockers = array_values(array_unique($blockers));
    $warnings = array_values(array_diff(array_unique($warnings), $blockers));
    $ready = empty($blockers);

    return [
        'ok' => $ready,
        'ready_for_go_live' => $ready,
        'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'database' => branchGoLiveDatabaseSummary($config),
        'checks' => $checks,
        'blockers' => $blockers,
        'warnings' => $warnings,
    ];
}

function branchGoLiveBackupFileCheck(string $backupFile, int $maxAgeHours): array
{
    if ($backupFile === '') {
        return [
            'ok' => false,
            'max_age_hours' => $maxAgeHours,
            'blocker' => 'backup_file_not_provided',
            'message' => 'Create a database dump first, then pass --backup-file=/absolute/path/to/dump.sql.',
        ];
    }

    if (!is_file($backupFile)) {
        return [
            'ok' => false,
            'max_age_hours' => $maxAgeHours,
            'blocker' => 'backup_file_not_found',
            'path' => $backupFile,
            'message' => 'The backup file path does not exist or is not a regular file.',
        ];
    }

    if (!is_readable($backupFile)) {
        return [
            'ok' => false,
            'max_age_hours' => $maxAgeHours,
            'blocker' => 'backup_file_not_readable',
            'path' => $backupFile,
            'message' => 'The backup file exists but is not readable by this process.',
        ];
    }

    $bytes = filesize($backupFile);
    if ($bytes === false || $bytes <= 0) {
        return [
            'ok' => false,
            'max_age_hours' => $maxAgeHours,
            'blocker' => 'backup_file_empty',
            'path' => $backupFile,
            'message' => 'The backup file is empty.',
        ];
    }

    $modifiedAt = (int) filemtime($backupFile);
    $ageSeconds = max(0, time() - $modifiedAt);
    if ($maxAgeHours > 0 && $ageSeconds > $maxAgeHours * 3600) {
        return [
            'ok' => false,
            'blocker' => 'backup_file_too_old',
            'path' => $backupFile,
            'bytes' => $bytes,
            'modified_at_utc' => gmdate('Y-m-d\TH:i:s\Z', $modifiedAt),
            'age_seconds' => $ageSeconds,
            'max_age_hours' => $maxAgeHours,
            'message' => 'The backup file is older than the allowed go-live evidence age.',
        ];
    }

    return [
        'ok' => true,
        'path' => $backupFile,
        'bytes' => $bytes,
        'modified_at_utc' => gmdate('Y-m-d\TH:i:s\Z', $modifiedAt),
        'age_seconds' => $ageSeconds,
        'max_age_hours' => $maxAgeHours,
    ];
}

function branchGoLiveConfigCheck(array $config): array
{
    $role = (string) ($config['role'] ?? '');
    $branchUuid = trim((string) ($config['branch']['uuid'] ?? ''));
    $cloudBaseUrl = trim((string) ($config['branch']['cloud_base_url'] ?? ''));
    $branchSecret = (string) ($config['sync']['branch_secret'] ?? '');
    $blockers = [];
    $warnings = [];

    if ($role !== 'branch') {
        $blockers[] = 'role_is_not_branch';
    }
    if ($branchUuid === '') {
        $blockers[] = 'branch_uuid_missing';
    } elseif (branchGoLiveLooksLikePlaceholder($branchUuid)) {
        $blockers[] = 'branch_uuid_placeholder';
    }
    if ($cloudBaseUrl === '') {
        $blockers[] = 'cloud_base_url_missing';
    } elseif (branchGoLiveLooksLikePlaceholder($cloudBaseUrl)) {
        $blockers[] = 'cloud_base_url_placeholder';
    }
    if ($branchSecret === '') {
        $blockers[] = 'branch_sync_secret_missing';
    } elseif (branchGoLiveLooksLikePlaceholder($branchSecret)) {
        $blockers[] = 'branch_sync_secret_placeholder';
    }
    if (empty($config['sync']['moova_apply_enabled'])) {
        $warnings[] = 'moova_apply_disabled_until_operator_enables';
    }

    return [
        'ok' => empty($blockers),
        'role' => $role,
        'branch_uuid_configured' => $branchUuid !== '',
        'cloud_base_url_configured' => $cloudBaseUrl !== '',
        'branch_sync_secret_configured' => $branchSecret !== '',
        'branch_sync_enabled' => !empty($config['sync']['branch_sync_enabled']) && !empty($config['sync']['worker_enabled']),
        'moova_poller_enabled' => !empty($config['sync']['moova_poller_enabled']),
        'moova_apply_enabled' => !empty($config['sync']['moova_apply_enabled']),
        'blockers' => $blockers,
        'warnings' => $warnings,
    ];
}

function branchGoLiveMoovaAcceptanceCheck(array $config, string $acceptanceFile, int $maxAgeHours): array
{
    $requiredMarkers = branchGoLiveMoovaAcceptanceRequiredMarkers();
    $required = !empty($config['sync']['moova_apply_enabled']);
    if (!$required) {
        return [
            'ok' => true,
            'required' => false,
            'required_markers' => $requiredMarkers,
            'max_age_hours' => $maxAgeHours,
            'message' => 'Moova automatic apply is disabled; cashier acceptance evidence is not required for this readiness result.',
        ];
    }

    if ($acceptanceFile === '') {
        return [
            'ok' => false,
            'required' => true,
            'required_markers' => $requiredMarkers,
            'max_age_hours' => $maxAgeHours,
            'blocker' => 'moova_apply_acceptance_missing',
            'message' => 'POSMAIN_MOOVA_APPLY_ENABLED=1 requires --moova-acceptance-file evidence before go-live.',
        ];
    }

    if (!is_file($acceptanceFile)) {
        return [
            'ok' => false,
            'required' => true,
            'required_markers' => $requiredMarkers,
            'max_age_hours' => $maxAgeHours,
            'blocker' => 'moova_acceptance_file_not_found',
            'path' => $acceptanceFile,
            'message' => 'The Moova cashier acceptance evidence path does not exist or is not a regular file.',
        ];
    }

    if (!is_readable($acceptanceFile)) {
        return [
            'ok' => false,
            'required' => true,
            'required_markers' => $requiredMarkers,
            'max_age_hours' => $maxAgeHours,
            'blocker' => 'moova_acceptance_file_not_readable',
            'path' => $acceptanceFile,
            'message' => 'The Moova cashier acceptance evidence exists but is not readable by this process.',
        ];
    }

    $bytes = filesize($acceptanceFile);
    if ($bytes === false || $bytes <= 0) {
        return [
            'ok' => false,
            'required' => true,
            'required_markers' => $requiredMarkers,
            'max_age_hours' => $maxAgeHours,
            'blocker' => 'moova_acceptance_file_empty',
            'path' => $acceptanceFile,
            'message' => 'The Moova cashier acceptance evidence file is empty.',
        ];
    }

    $contents = file_get_contents($acceptanceFile);
    if (!is_string($contents)) {
        return [
            'ok' => false,
            'required' => true,
            'required_markers' => $requiredMarkers,
            'max_age_hours' => $maxAgeHours,
            'blocker' => 'moova_acceptance_file_read_failed',
            'path' => $acceptanceFile,
            'message' => 'The Moova cashier acceptance evidence file could not be read.',
        ];
    }

    $missingMarkers = branchGoLiveMoovaAcceptanceMissingMarkers($contents, $requiredMarkers);
    if ($missingMarkers) {
        return [
            'ok' => false,
            'required' => true,
            'required_markers' => $requiredMarkers,
            'missing_markers' => $missingMarkers,
            'max_age_hours' => $maxAgeHours,
            'blocker' => 'moova_acceptance_markers_missing',
            'path' => $acceptanceFile,
            'bytes' => $bytes,
            'message' => 'The Moova cashier acceptance evidence is missing required pass markers.',
        ];
    }

    $modifiedAt = (int) filemtime($acceptanceFile);
    $ageSeconds = max(0, time() - $modifiedAt);
    if ($maxAgeHours > 0 && $ageSeconds > $maxAgeHours * 3600) {
        return [
            'ok' => false,
            'required' => true,
            'required_markers' => $requiredMarkers,
            'missing_markers' => [],
            'blocker' => 'moova_acceptance_file_too_old',
            'path' => $acceptanceFile,
            'bytes' => $bytes,
            'modified_at_utc' => gmdate('Y-m-d\TH:i:s\Z', $modifiedAt),
            'age_seconds' => $ageSeconds,
            'max_age_hours' => $maxAgeHours,
            'message' => 'The Moova cashier acceptance evidence is older than the allowed go-live evidence age.',
        ];
    }

    return [
        'ok' => true,
        'required' => true,
        'required_markers' => $requiredMarkers,
        'missing_markers' => [],
        'path' => $acceptanceFile,
        'bytes' => $bytes,
        'modified_at_utc' => gmdate('Y-m-d\TH:i:s\Z', $modifiedAt),
        'age_seconds' => $ageSeconds,
        'max_age_hours' => $maxAgeHours,
    ];
}

function branchGoLiveOptionInt(array $options, string $key, int $default, int $min, int $max): int
{
    if (!array_key_exists($key, $options) || $options[$key] === false || $options[$key] === '') {
        return $default;
    }

    return max($min, min($max, (int) $options[$key]));
}

function branchGoLiveMoovaAcceptanceRequiredMarkers(): array
{
    return [
        'queued_new_order=pass',
        'queued_edit_order=pass',
        'queued_cancel_order=pass',
        'pos_drop_recovery=pass',
        'moova_drop_recovery=pass',
    ];
}

function branchGoLiveMoovaAcceptanceMissingMarkers(string $contents, array $requiredMarkers): array
{
    $normalized = strtolower(str_replace(["\r\n", "\r"], "\n", $contents));
    $missing = [];
    foreach ($requiredMarkers as $marker) {
        if (!str_contains($normalized, strtolower($marker))) {
            $missing[] = $marker;
        }
    }

    return $missing;
}

function branchGoLiveLoadEnvFile(string $path): array
{
    $path = trim($path);
    if ($path === '') {
        return [
            'ok' => false,
            'blockers' => ['env_file_path_missing'],
            'warnings' => [],
            'loaded_keys' => [],
        ];
    }

    if (!is_file($path) || !is_readable($path)) {
        return [
            'ok' => false,
            'path' => $path,
            'blockers' => ['env_file_not_readable'],
            'warnings' => [],
            'loaded_keys' => [],
        ];
    }

    $loaded = [];
    $blockers = [];
    $warnings = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return [
            'ok' => false,
            'path' => $path,
            'blockers' => ['env_file_read_failed'],
            'warnings' => [],
            'loaded_keys' => [],
        ];
    }

    foreach ($lines as $lineNumber => $line) {
        $trimmed = trim((string) $line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        if (str_starts_with($trimmed, 'export ')) {
            $trimmed = trim(substr($trimmed, 7));
        }
        if (!str_contains($trimmed, '=')) {
            $warnings[] = 'env_file_ignored_line_' . ((int) $lineNumber + 1);
            continue;
        }

        [$key, $value] = explode('=', $trimmed, 2);
        $key = trim($key);
        $value = branchGoLiveUnquoteEnvValue(trim($value));
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            $warnings[] = 'env_file_invalid_key_' . ((int) $lineNumber + 1);
            continue;
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        $loaded[$key] = $value;
    }

    $required = [
        'POSMAIN_ROLE',
        'POSMAIN_DB_HOST',
        'POSMAIN_DB_PORT',
        'POSMAIN_DB_NAME',
        'POSMAIN_DB_USER',
        'POSMAIN_BRANCH_UUID',
        'POSMAIN_CLOUD_BASE_URL',
        'POSMAIN_BRANCH_SYNC_SECRET',
    ];

    foreach ($required as $key) {
        if (!array_key_exists($key, $loaded) || trim((string) $loaded[$key]) === '') {
            $blockers[] = 'env_file_missing_' . $key;
            continue;
        }
        if (branchGoLiveLooksLikePlaceholder((string) $loaded[$key])) {
            $blockers[] = 'env_file_placeholder_' . $key;
        }
    }

    foreach (['POSMAIN_DB_PASS', 'POSMAIN_BRANCH_NAME'] as $key) {
        if (array_key_exists($key, $loaded) && branchGoLiveLooksLikePlaceholder((string) $loaded[$key])) {
            $warnings[] = 'env_file_placeholder_' . $key;
        }
    }

    return [
        'ok' => empty($blockers),
        'path' => $path,
        'loaded_keys' => array_keys($loaded),
        'blockers' => array_values(array_unique($blockers)),
        'warnings' => array_values(array_unique($warnings)),
    ];
}

function branchGoLiveUnquoteEnvValue(string $value): string
{
    $length = strlen($value);
    if ($length >= 2) {
        $first = $value[0];
        $last = $value[$length - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            return substr($value, 1, -1);
        }
    }

    return $value;
}

function branchGoLiveLooksLikePlaceholder(string $value): bool
{
    $normalized = strtolower(trim($value));
    if ($normalized === '') {
        return false;
    }

    foreach (['replace-with', 'change-me', 'changeme', 'todo-', 'your-', '<', '>'] as $needle) {
        if (str_contains($normalized, $needle)) {
            return true;
        }
    }

    return in_array($normalized, [
        'https://cloud.example.com',
        'example.com',
        'secret',
        'password',
    ], true);
}

function branchGoLiveCommandTemplates(array $config, string $backupFile): array
{
    $db = $config['database'] ?? [];
    $host = branchGoLiveShellToken((string) ($db['host'] ?? '127.0.0.1'));
    $port = (int) ($db['port'] ?? 3306);
    $user = branchGoLiveShellToken((string) ($db['user'] ?? 'root'));
    $name = branchGoLiveShellToken((string) ($db['name'] ?? 'kody2'));
    $safeDbName = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) ($db['name'] ?? 'kody2'));
    $backupTarget = $backupFile !== ''
        ? branchGoLiveShellToken($backupFile)
        : 'backups/posmain-' . $safeDbName . '-$(date +%Y%m%d-%H%M%S).sql';
    $restoreSource = $backupFile !== ''
        ? branchGoLiveShellToken($backupFile)
        : '/absolute/path/to/verified-posmain-backup.sql';

    return [
        'backup' => [
            'note' => 'Run this before migrations or service enablement. Do not commit database passwords; let mysql prompt or use a protected local option file.',
            'command' => "mysqldump --single-transaction --routines --triggers --default-character-set=utf8mb4 --host={$host} --port={$port} --user={$user} {$name} > {$backupTarget}",
        ],
        'schema_dry_run' => [
            'command' => 'php tools/run_migrations.php --dry-run',
        ],
        'daemon_preflight' => [
            'command' => 'php cli/branch_worker_daemon.php --preflight --strict',
        ],
        'rollback_restore' => [
            'note' => 'Stop branch worker services first. Restore only after operator approval because this overwrites the target database.',
            'command' => "mysql --host={$host} --port={$port} --user={$user} --default-character-set=utf8mb4 {$name} < {$restoreSource}",
        ],
        'safe_service_disable' => [
            'command' => 'Set POSMAIN_SYNC_WORKER_ENABLED=0 and POSMAIN_MOOVA_APPLY_ENABLED=0, then stop the installed supervisor service.',
        ],
    ];
}

function branchGoLiveDatabaseSummary(array $config): array
{
    $db = $config['database'] ?? [];

    return [
        'host' => (string) ($db['host'] ?? ''),
        'port' => (int) ($db['port'] ?? 0),
        'name' => (string) ($db['name'] ?? ''),
        'user' => (string) ($db['user'] ?? ''),
        'charset' => (string) ($db['charset'] ?? ''),
        'password_configured' => (string) ($db['pass'] ?? '') !== '',
    ];
}

function branchGoLiveShellToken(string $value): string
{
    if ($value !== '' && preg_match('/^[A-Za-z0-9_@%+=:,\\.\\/-]+$/', $value)) {
        return $value;
    }

    return escapeshellarg($value);
}

function branchGoLivePrintHuman(array $result): void
{
    $status = !empty($result['ready_for_go_live']) ? 'READY' : 'NOT READY';
    fwrite(STDOUT, "Branch go-live readiness: {$status}\n");

    if (!empty($result['blockers'])) {
        fwrite(STDOUT, "\nBlockers:\n");
        foreach ($result['blockers'] as $blocker) {
            fwrite(STDOUT, "- {$blocker}\n");
        }
    }

    if (!empty($result['warnings'])) {
        fwrite(STDOUT, "\nWarnings:\n");
        foreach ($result['warnings'] as $warning) {
            fwrite(STDOUT, "- {$warning}\n");
        }
    }

    fwrite(STDOUT, "\nNext commands:\n");
    foreach (($result['checks']['commands'] ?? []) as $name => $entry) {
        fwrite(STDOUT, "- {$name}: " . (string) ($entry['command'] ?? '') . "\n");
    }
}
