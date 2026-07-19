<?php

require_once __DIR__ . '/RestoreEventPhase.php';

class BranchRestoreRunService
{
    public const STATUS_PREPARED = 'prepared';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public function prepare(mysqli $conn, array $binding): array
    {
        $row = $this->normalizeBinding($binding);
        $phaseState = [];
        foreach (RestoreEventPhase::all() as $phase) {
            $phaseState[$phase] = [
                'cursor' => 0,
                'complete' => false,
                'pages' => 0,
                'fetched' => 0,
                'mirrored' => 0,
                'skipped' => 0,
                'failed' => 0,
            ];
        }
        $phaseJson = $this->encodePhaseState($phaseState);
        $status = self::STATUS_PREPARED;

        $stmt = $conn->prepare("
            INSERT INTO sync_branch_restore_runs (
                run_uuid, branch_uuid, contract_version, source, recovery_profile,
                snapshot_checkpoint, history_since_utc, manifest_hash, expected_events,
                confirmation_token, backup_sha256, status, phase_state_json
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            'ssissississss',
            $row['run_uuid'],
            $row['branch_uuid'],
            $row['contract_version'],
            $row['source'],
            $row['recovery_profile'],
            $row['snapshot_checkpoint'],
            $row['history_since_utc'],
            $row['manifest_hash'],
            $row['expected_events'],
            $row['confirmation_token'],
            $row['backup_sha256'],
            $status,
            $phaseJson
        );
        $stmt->execute();

        return $this->requireRun($conn, $row['run_uuid']);
    }

    public function start(mysqli $conn, string $runUuid): array
    {
        $runUuid = $this->normalizeUuid($runUuid, 'run_uuid');
        $stmt = $conn->prepare("
            UPDATE sync_branch_restore_runs
            SET status = ?, started_at = COALESCE(started_at, NOW(6)), last_error = NULL
            WHERE run_uuid = ? AND status = ?
        ");
        $running = self::STATUS_RUNNING;
        $prepared = self::STATUS_PREPARED;
        $stmt->bind_param('sss', $running, $runUuid, $prepared);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            throw new RuntimeException('Restore run cannot start unless it is prepared.');
        }

        return $this->requireRun($conn, $runUuid);
    }

    public function assertResumeBinding(mysqli $conn, string $runUuid, array $binding): array
    {
        $binding['run_uuid'] = $runUuid;
        $expected = $this->normalizeBinding($binding);
        $run = $this->requireRun($conn, $expected['run_uuid']);
        if ($run['status'] !== self::STATUS_RUNNING) {
            throw new RuntimeException('Restore run is not incomplete and resumable.');
        }

        foreach ($this->bindingKeys() as $key) {
            if ((string) $run[$key] !== (string) $expected[$key]) {
                throw new RuntimeException('Restore resume binding mismatch: ' . $key);
            }
        }

        return $run;
    }

    public function advancePage(
        mysqli $conn,
        string $runUuid,
        string $phase,
        int $expectedCursor,
        int $nextCursor,
        bool $phaseComplete,
        array $metrics
    ): array {
        $runUuid = $this->normalizeUuid($runUuid, 'run_uuid');
        $phase = RestoreEventPhase::normalize($phase);
        if ($expectedCursor < 0 || $nextCursor < $expectedCursor) {
            throw new InvalidArgumentException('Restore page cursor cannot move backwards.');
        }
        $increments = $this->normalizeMetrics($metrics);

        $conn->begin_transaction();
        try {
            $run = $this->requireRun($conn, $runUuid, true);
            if ($run['status'] !== self::STATUS_RUNNING) {
                throw new RuntimeException('Restore progress requires a running run.');
            }
            $phaseState = $run['phase_state'];
            $storedCursor = (int) ($phaseState[$phase]['cursor'] ?? -1);
            if ($storedCursor !== $expectedCursor) {
                throw new RuntimeException('Restore page cursor changed before progress could be saved.');
            }
            if (!empty($phaseState[$phase]['complete'])) {
                throw new RuntimeException('Completed restore phase cannot advance.');
            }

            $phaseState[$phase] = [
                'cursor' => $nextCursor,
                'complete' => $phaseComplete,
                'pages' => (int) ($phaseState[$phase]['pages'] ?? 0) + $increments['pages'],
                'fetched' => (int) ($phaseState[$phase]['fetched'] ?? 0) + $increments['fetched'],
                'mirrored' => (int) ($phaseState[$phase]['mirrored'] ?? 0) + $increments['mirrored'],
                'skipped' => (int) ($phaseState[$phase]['skipped'] ?? 0) + $increments['skipped'],
                'failed' => (int) ($phaseState[$phase]['failed'] ?? 0) + $increments['failed'],
            ];
            $phaseJson = $this->encodePhaseState($phaseState);
            $stmt = $conn->prepare("
                UPDATE sync_branch_restore_runs
                SET phase_state_json = ?,
                    fetched = fetched + ?, mirrored = mirrored + ?,
                    skipped = skipped + ?, failed = failed + ?
                WHERE run_uuid = ? AND status = ?
            ");
            $running = self::STATUS_RUNNING;
            $stmt->bind_param(
                'siiiiss',
                $phaseJson,
                $increments['fetched'],
                $increments['mirrored'],
                $increments['skipped'],
                $increments['failed'],
                $runUuid,
                $running
            );
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                throw new RuntimeException('Restore progress update lost its running run.');
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        return $this->requireRun($conn, $runUuid);
    }

    public function complete(mysqli $conn, string $runUuid): array
    {
        $runUuid = $this->normalizeUuid($runUuid, 'run_uuid');
        $conn->begin_transaction();
        try {
            $run = $this->requireRun($conn, $runUuid, true);
            if ($run['status'] !== self::STATUS_RUNNING) {
                throw new RuntimeException('Only a running restore can complete.');
            }
            foreach (RestoreEventPhase::all() as $phase) {
                if (empty($run['phase_state'][$phase]['complete'])) {
                    throw new RuntimeException('Restore run cannot complete before every phase is complete.');
                }
            }
            if ((int) $run['failed'] !== 0
                || (int) $run['skipped'] !== 0
                || (int) $run['fetched'] !== (int) $run['expected_events']
                || (int) $run['mirrored'] !== (int) $run['expected_events']) {
                throw new RuntimeException('Restore run cannot complete before exact reconciliation.');
            }

            $completed = self::STATUS_COMPLETED;
            $running = self::STATUS_RUNNING;
            $stmt = $conn->prepare("
                UPDATE sync_branch_restore_runs
                SET status = ?, completed_at = NOW(6), last_error = NULL
                WHERE run_uuid = ? AND status = ?
            ");
            $stmt->bind_param('sss', $completed, $runUuid, $running);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                throw new RuntimeException('Restore completion lost its running run.');
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        return $this->requireRun($conn, $runUuid);
    }

    public function fail(mysqli $conn, string $runUuid, string $message): array
    {
        $runUuid = $this->normalizeUuid($runUuid, 'run_uuid');
        $message = substr(trim($message), 0, 4000);
        $failed = self::STATUS_FAILED;
        $running = self::STATUS_RUNNING;
        $stmt = $conn->prepare("
            UPDATE sync_branch_restore_runs
            SET status = ?, last_error = ?
            WHERE run_uuid = ? AND status = ?
        ");
        $stmt->bind_param('ssss', $failed, $message, $runUuid, $running);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            throw new RuntimeException('Only a running restore can be marked failed.');
        }

        return $this->requireRun($conn, $runUuid);
    }

    public function find(mysqli $conn, string $runUuid): ?array
    {
        $runUuid = $this->normalizeUuid($runUuid, 'run_uuid');
        $stmt = $conn->prepare('SELECT * FROM sync_branch_restore_runs WHERE run_uuid = ? LIMIT 1');
        $stmt->bind_param('s', $runUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function acquireWriterLock(mysqli $conn): bool
    {
        $name = 'posmain_branch_restore_writer';
        $timeout = 0;
        $stmt = $conn->prepare('SELECT GET_LOCK(?, ?) AS acquired');
        $stmt->bind_param('si', $name, $timeout);
        $stmt->execute();
        return (int) ($stmt->get_result()->fetch_assoc()['acquired'] ?? 0) === 1;
    }

    public function releaseWriterLock(mysqli $conn): void
    {
        $name = 'posmain_branch_restore_writer';
        $stmt = $conn->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->bind_param('s', $name);
        $stmt->execute();
    }

    public function newRunUuid(): string
    {
        return $this->generateUuid();
    }

    private function requireRun(mysqli $conn, string $runUuid, bool $forUpdate = false): array
    {
        $sql = 'SELECT * FROM sync_branch_restore_runs WHERE run_uuid = ? LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $runUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!is_array($row)) {
            throw new RuntimeException('Restore run was not found.');
        }

        return $this->hydrate($row);
    }

    private function hydrate(array $row): array
    {
        $decoded = json_decode((string) ($row['phase_state_json'] ?? ''), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Restore run phase state is invalid.');
        }
        $row['phase_state'] = $decoded;
        foreach (['contract_version', 'snapshot_checkpoint', 'expected_events', 'fetched', 'mirrored', 'skipped', 'failed'] as $key) {
            $row[$key] = (int) ($row[$key] ?? 0);
        }
        return $row;
    }

    private function normalizeBinding(array $binding): array
    {
        $runUuid = isset($binding['run_uuid']) && trim((string) $binding['run_uuid']) !== ''
            ? $this->normalizeUuid((string) $binding['run_uuid'], 'run_uuid')
            : $this->generateUuid();
        $branchUuid = $this->normalizeUuid((string) ($binding['branch_uuid'] ?? ''), 'branch_uuid');
        $contractVersion = (int) ($binding['contract_version'] ?? 0);
        if ($contractVersion !== 2) {
            throw new InvalidArgumentException('Resumable restore requires contract version 2.');
        }
        $source = strtolower(trim((string) ($binding['source'] ?? '')));
        if ($source !== 'cloud_snapshot') {
            throw new InvalidArgumentException('Resumable restore requires cloud_snapshot source.');
        }
        $profile = strtolower(trim((string) ($binding['recovery_profile'] ?? '')));
        if ($profile === '') {
            throw new InvalidArgumentException('Recovery profile is required.');
        }
        $checkpoint = filter_var($binding['snapshot_checkpoint'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);
        if ($checkpoint === false || $checkpoint === null) {
            throw new InvalidArgumentException('Snapshot checkpoint must be a non-negative integer.');
        }
        $historySinceUtc = trim((string) ($binding['history_since_utc'] ?? ''));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $historySinceUtc, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d\TH:i:s\Z') !== $historySinceUtc) {
            throw new InvalidArgumentException('History cutoff must be an exact UTC timestamp.');
        }
        $manifestHash = $this->normalizeHash((string) ($binding['manifest_hash'] ?? ''), 'manifest_hash');
        $expectedEvents = filter_var($binding['expected_events'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);
        if ($expectedEvents === false || $expectedEvents === null) {
            throw new InvalidArgumentException('Expected events must be a non-negative integer.');
        }
        $confirmationToken = trim((string) ($binding['confirmation_token'] ?? ''));
        if ($confirmationToken === '' || strlen($confirmationToken) > 100) {
            throw new InvalidArgumentException('Confirmation token is required and must be bounded.');
        }

        return [
            'run_uuid' => $runUuid,
            'branch_uuid' => $branchUuid,
            'contract_version' => $contractVersion,
            'source' => $source,
            'recovery_profile' => $profile,
            'snapshot_checkpoint' => (int) $checkpoint,
            'history_since_utc' => $historySinceUtc,
            'manifest_hash' => $manifestHash,
            'expected_events' => (int) $expectedEvents,
            'confirmation_token' => $confirmationToken,
            'backup_sha256' => $this->normalizeHash((string) ($binding['backup_sha256'] ?? ''), 'backup_sha256'),
        ];
    }

    private function bindingKeys(): array
    {
        return [
            'branch_uuid', 'contract_version', 'source', 'recovery_profile',
            'snapshot_checkpoint', 'history_since_utc', 'manifest_hash',
            'expected_events', 'confirmation_token', 'backup_sha256',
        ];
    }

    private function normalizeMetrics(array $metrics): array
    {
        $normalized = [];
        foreach (['pages', 'fetched', 'mirrored', 'skipped', 'failed'] as $key) {
            $value = filter_var($metrics[$key] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($value === false || $value === null) {
                throw new InvalidArgumentException('Restore metric must be a non-negative integer: ' . $key);
            }
            $normalized[$key] = (int) $value;
        }
        return $normalized;
    }

    private function encodePhaseState(array $state): string
    {
        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > 4096) {
            throw new RuntimeException('Restore phase state exceeded its bounded size.');
        }
        return $json;
    }

    private function normalizeHash(string $value, string $name): string
    {
        $value = strtolower(trim($value));
        if (!preg_match('/^[a-f0-9]{64}$/', $value)) {
            throw new InvalidArgumentException($name . ' must be a SHA-256 hash.');
        }
        return $value;
    }

    private function normalizeUuid(string $value, string $name): string
    {
        $value = strtolower(trim($value));
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $value)) {
            throw new InvalidArgumentException($name . ' must be a UUID.');
        }
        return $value;
    }

    private function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
