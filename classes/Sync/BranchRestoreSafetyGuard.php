<?php

require_once __DIR__ . '/RestoreEventPhase.php';
require_once __DIR__ . '/OperationalSyncDomains.php';

class BranchRestoreSafetyGuard
{
    public const SCOPE_EMPTY = 'empty';
    public const DEFAULT_MAX_BACKUP_AGE_HOURS = 24;

    public function describePlan(
        mysqli $conn,
        string $branchUuid,
        array $phases,
        array $plan,
        array $config = []
    ): array {
        $normalizedPhases = $this->normalizePhases($phases);
        $manifestHash = $this->manifestHash($branchUuid, $normalizedPhases, $plan);
        $businessRows = $this->businessRowCounts($conn);
        $nonEmpty = array_filter($businessRows, static fn (int $count): bool => $count > 0);
        $allPhases = $this->isCompletePhaseSet($normalizedPhases);
        $cloudPullDisabled = empty($config['sync']['cloud_pull_enabled']);

        return [
            'scope' => self::SCOPE_EMPTY,
            'all_phases' => $allPhases,
            'cloud_pull_disabled' => $cloudPullDisabled,
            'business_database_empty' => $nonEmpty === [],
            'business_row_counts' => $businessRows,
            'non_empty_tables' => $nonEmpty,
            'manifest_hash' => $manifestHash,
            'expected_events' => (int) ($plan['fetched'] ?? 0),
            'confirmation_token' => self::confirmationToken($branchUuid, $manifestHash),
            'apply_allowed' => $allPhases && $cloudPullDisabled && $nonEmpty === [],
            'apply_requirements' => [
                'Run this dry-run immediately before apply and keep its manifest hash and event count.',
                'Use every restore phase; selected-scope repair is not implemented by this command.',
                'Apply only to an empty business database.',
                'Disable generic cloud pull and stop every branch sync worker.',
                'Provide a readable fresh local database backup and a new receipt path.',
                'Pass the exact dry-run manifest, event count and confirmation token.',
            ],
        ];
    }

    public function assertApplyAuthorized(
        mysqli $conn,
        string $branchUuid,
        array $phases,
        array $plan,
        array $config,
        array $options
    ): array {
        $evidence = $this->authorizationEvidence($conn, $branchUuid, $phases, $plan, $config, $options, true);
        if ($evidence['errors'] !== []) {
            throw new RuntimeException('Restore apply blocked: ' . implode(', ', $evidence['errors']));
        }

        return $evidence['safety'] + [
            'backup' => $evidence['backup'],
            'worker_pid' => $evidence['worker_pid'],
        ];
    }

    public function assertResumeAuthorized(
        mysqli $conn,
        string $branchUuid,
        array $phases,
        array $plan,
        array $config,
        array $options,
        array $run
    ): array {
        $evidence = $this->authorizationEvidence($conn, $branchUuid, $phases, $plan, $config, $options, false);
        $errors = $evidence['errors'];
        if (($run['status'] ?? '') !== 'running') {
            $errors[] = 'restore_resume_run_is_not_running';
        }
        if (strtolower((string) ($run['branch_uuid'] ?? '')) !== strtolower($branchUuid)) {
            $errors[] = 'restore_resume_branch_mismatch';
        }
        if ((int) ($run['contract_version'] ?? 0) !== (int) ($plan['contract_version'] ?? 0)
            || (string) ($run['source'] ?? '') !== 'cloud_snapshot'
            || (string) ($run['recovery_profile'] ?? '') !== (string) ($plan['recovery_profile'] ?? '')
            || (int) ($run['snapshot_checkpoint'] ?? -1) !== (int) ($plan['snapshot_checkpoint'] ?? -2)
            || (string) ($run['history_since_utc'] ?? '') !== (string) ($plan['history_since_utc'] ?? '')) {
            $errors[] = 'restore_resume_snapshot_binding_mismatch';
        }
        if (!hash_equals((string) ($run['manifest_hash'] ?? ''), (string) $evidence['safety']['manifest_hash'])) {
            $errors[] = 'restore_resume_manifest_mismatch';
        }
        if ((int) ($run['expected_events'] ?? -1) !== (int) $evidence['safety']['expected_events']) {
            $errors[] = 'restore_resume_expected_events_mismatch';
        }
        if (!hash_equals((string) ($run['confirmation_token'] ?? ''), (string) $evidence['safety']['confirmation_token'])) {
            $errors[] = 'restore_resume_confirmation_mismatch';
        }
        if (empty($evidence['backup']['ok'])
            || !hash_equals((string) ($run['backup_sha256'] ?? ''), (string) ($evidence['backup']['sha256'] ?? ''))) {
            $errors[] = 'restore_resume_backup_mismatch';
        }
        if ($errors !== []) {
            throw new RuntimeException('Restore resume blocked: ' . implode(', ', array_values(array_unique($errors))));
        }

        return $evidence['safety'] + [
            'backup' => $evidence['backup'],
            'worker_pid' => $evidence['worker_pid'],
            'resume_run_uuid' => (string) ($run['run_uuid'] ?? ''),
        ];
    }

    private function authorizationEvidence(
        mysqli $conn,
        string $branchUuid,
        array $phases,
        array $plan,
        array $config,
        array $options,
        bool $requireEmpty
    ): array {
        $safety = $this->describePlan($conn, $branchUuid, $phases, $plan, $config);
        $errors = [];
        if (($options['scope'] ?? '') !== self::SCOPE_EMPTY) {
            $errors[] = 'restore_scope_must_be_empty';
        }
        if (empty($safety['all_phases'])) {
            $errors[] = 'restore_apply_requires_all_phases';
        }
        if (empty($safety['cloud_pull_disabled'])) {
            $errors[] = 'generic_cloud_pull_must_be_disabled';
        }
        if ($requireEmpty && empty($safety['business_database_empty'])) {
            $errors[] = 'restore_target_business_database_is_not_empty';
        }
        if (empty($options['workers_stopped'])) {
            $errors[] = 'restore_workers_stopped_acknowledgement_missing';
        }

        $pidCheck = $this->workerPidCheck((string) ($options['worker_pid_file'] ?? ''));
        if (!empty($pidCheck['active'])) {
            $errors[] = 'restore_worker_process_is_still_active';
        }
        $expectedEvents = filter_var($options['expected_events'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);
        if ($expectedEvents === false || $expectedEvents === null) {
            $errors[] = 'restore_expected_events_missing';
        } elseif ((int) $expectedEvents !== (int) $safety['expected_events']) {
            $errors[] = 'restore_event_count_changed_since_dry_run';
        }
        if (!hash_equals((string) $safety['manifest_hash'], trim((string) ($options['dry_run_manifest'] ?? '')))) {
            $errors[] = 'restore_dry_run_manifest_mismatch';
        }
        if (!hash_equals((string) $safety['confirmation_token'], trim((string) ($options['confirmation_token'] ?? '')))) {
            $errors[] = 'restore_confirmation_token_mismatch';
        }

        $backup = $this->backupEvidence(
            (string) ($options['backup_file'] ?? ''),
            isset($options['max_backup_age_hours'])
                ? max(0, (int) $options['max_backup_age_hours'])
                : self::DEFAULT_MAX_BACKUP_AGE_HOURS
        );
        if (empty($backup['ok'])) {
            $errors[] = (string) ($backup['blocker'] ?? 'restore_backup_invalid');
        }

        return [
            'safety' => $safety,
            'errors' => array_values(array_unique($errors)),
            'backup' => $backup,
            'worker_pid' => $pidCheck,
        ];
    }

    public function backupEvidence(string $path, int $maxAgeHours = self::DEFAULT_MAX_BACKUP_AGE_HOURS): array
    {
        $path = trim($path);
        if ($path === '') {
            return ['ok' => false, 'blocker' => 'restore_backup_file_missing'];
        }
        if (!is_file($path)) {
            return ['ok' => false, 'blocker' => 'restore_backup_file_not_found', 'path' => $path];
        }
        if (!is_readable($path)) {
            return ['ok' => false, 'blocker' => 'restore_backup_file_not_readable', 'path' => $path];
        }

        $bytes = filesize($path);
        if ($bytes === false || $bytes <= 0) {
            return ['ok' => false, 'blocker' => 'restore_backup_file_empty', 'path' => $path];
        }

        $modifiedAt = filemtime($path);
        $ageSeconds = $modifiedAt === false ? PHP_INT_MAX : max(0, time() - (int) $modifiedAt);
        if ($maxAgeHours > 0 && $ageSeconds > ($maxAgeHours * 3600)) {
            return [
                'ok' => false,
                'blocker' => 'restore_backup_file_too_old',
                'path' => $path,
                'bytes' => (int) $bytes,
                'age_seconds' => $ageSeconds,
                'max_age_hours' => $maxAgeHours,
            ];
        }

        $hash = hash_file('sha256', $path);
        if (!is_string($hash) || $hash === '') {
            return ['ok' => false, 'blocker' => 'restore_backup_hash_failed', 'path' => $path];
        }

        return [
            'ok' => true,
            'path' => $path,
            'bytes' => (int) $bytes,
            'sha256' => $hash,
            'modified_at_utc' => gmdate('c', (int) $modifiedAt),
            'age_seconds' => $ageSeconds,
            'max_age_hours' => $maxAgeHours,
        ];
    }

    public static function confirmationToken(string $branchUuid, string $manifestHash): string
    {
        $branchPart = substr(str_replace('-', '', strtolower($branchUuid)), 0, 12);
        return 'RESTORE_EMPTY_' . strtoupper($branchPart) . '_' . strtoupper(substr($manifestHash, 0, 16));
    }

    private function manifestHash(string $branchUuid, array $phases, array $plan): string
    {
        $phaseRows = [];
        foreach ((array) ($plan['phases'] ?? []) as $phase) {
            if (!is_array($phase)) {
                continue;
            }
            $phaseRows[] = [
                'phase' => (string) ($phase['phase'] ?? ''),
                'source' => (string) ($phase['source'] ?? ''),
                'fetched' => (int) ($phase['fetched'] ?? 0),
                'pages' => (int) ($phase['pages'] ?? 0),
            ];
        }

        $json = json_encode([
            'contract' => 'posmain_empty_branch_restore_v1',
            'branch_uuid' => strtolower($branchUuid),
            'phases' => $phases,
            'fetched' => (int) ($plan['fetched'] ?? 0),
            'phase_rows' => $phaseRows,
        ], JSON_UNESCAPED_SLASHES);

        return hash('sha256', (string) $json);
    }

    private function normalizePhases(array $phases): array
    {
        $normalized = [];
        foreach ($phases as $phase) {
            $value = RestoreEventPhase::normalize((string) $phase);
            if (!in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }
        return $normalized;
    }

    private function isCompletePhaseSet(array $phases): bool
    {
        $expected = RestoreEventPhase::all();
        sort($expected);
        sort($phases);
        return $phases === $expected;
    }

    private function businessRowCounts(mysqli $conn): array
    {
        $tables = [
            'myitems',
            'item_categories',
            'tables',
            'ot_head',
            'fat_details',
            'order_payments',
            'payment_refunds',
            'credit_notes',
            'credit_note_lines',
            'journal_heads',
            'journal_entries',
            'drawer_sessions',
            'drawer_movements',
            'drawer_count_attempts',
            'drawer_session_resolutions',
            'drawer_session_close_summaries',
            'closed_orders',
            'pos_customers',
            'pos_customer_phones',
            'pos_customer_addresses',
        ];
        foreach (OperationalSyncDomains::bulkRowDomains() as $definition) {
            $table = trim((string) ($definition['table'] ?? ''));
            if ($table !== '') {
                $tables[] = $table;
            }
        }

        $counts = [];
        foreach (array_values(array_unique($tables)) as $table) {
            if (!$this->tableExists($conn, $table)) {
                continue;
            }
            $escaped = str_replace('`', '``', $table);
            $row = $conn->query("SELECT COUNT(*) AS c FROM `{$escaped}`")->fetch_assoc();
            $counts[$table] = (int) ($row['c'] ?? 0);
        }
        ksort($counts);
        return $counts;
    }

    private function workerPidCheck(string $path): array
    {
        $path = trim($path);
        if ($path === '' || !is_file($path)) {
            return ['path' => $path, 'present' => false, 'active' => false];
        }

        $pid = (int) trim((string) @file_get_contents($path));
        $active = $pid > 0 && function_exists('posix_kill') && @posix_kill($pid, 0);
        return [
            'path' => $path,
            'present' => true,
            'pid' => $pid,
            'active' => $active,
        ];
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $escaped = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");
        $exists = $result && $result->num_rows > 0;
        if ($result) {
            $result->free();
        }
        return $exists;
    }
}
