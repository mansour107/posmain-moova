<?php

class PrintJobService
{
    private const PRINTER_TYPES = ['receipt', 'kitchen', 'label', 'other'];
    private const CONNECTION_TYPES = ['browser', 'network', 'usb', 'file', 'cloud'];
    private const JOB_TYPES = ['receipt', 'kot', 'kitchen', 'z_report', 'x_report', 'report', 'label', 'document'];
    private const FINAL_STATUSES = ['printed', 'failed', 'cancelled'];

    public function savePrinter(mysqli $conn, array $data): array
    {
        $id = $this->optionalPositiveInt($data['id'] ?? null);
        $name = $this->requiredText($data['name'] ?? '', 120, 'PRINTER_NAME_REQUIRED');
        $printerType = $this->enum($data['printer_type'] ?? $data['type'] ?? '', self::PRINTER_TYPES, 'PRINTER_TYPE_INVALID');
        $connectionType = $this->enum($data['connection_type'] ?? 'browser', self::CONNECTION_TYPES, 'PRINTER_CONNECTION_INVALID');
        $configJson = $this->jsonOrNull($data['config'] ?? $data['config_json'] ?? null, 'PRINTER_CONFIG_INVALID');
        $tenant = $this->nonNegativeInt($data['tenant'] ?? 0, 'TENANT_INVALID');
        $branch = $this->nonNegativeInt($data['branch'] ?? 0, 'BRANCH_INVALID');
        $isActive = $this->boolInt($data['is_active'] ?? true);

        if ($id) {
            $stmt = $conn->prepare("
                UPDATE printers
                SET name = ?,
                    printer_type = ?,
                    connection_type = ?,
                    config_json = ?,
                    tenant = ?,
                    branch = ?,
                    is_active = ?
                WHERE id = ?
                  AND tenant = ?
                  AND branch = ?
            ");
            $stmt->bind_param(
                'ssssiiiiii',
                $name,
                $printerType,
                $connectionType,
                $configJson,
                $tenant,
                $branch,
                $isActive,
                $id,
                $tenant,
                $branch
            );
            $stmt->execute();
            if ($stmt->affected_rows < 1) {
                $stmt->close();
                return $this->getPrinterInScope($conn, $id, [
                    'tenant' => $tenant,
                    'branch' => $branch,
                ]);
            }
            $stmt->close();

            return $this->printerById($conn, $id, false);
        }

        $stmt = $conn->prepare("
            INSERT INTO printers (
                name, printer_type, connection_type, config_json,
                tenant, branch, is_active
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('ssssiii', $name, $printerType, $connectionType, $configJson, $tenant, $branch, $isActive);
        $stmt->execute();
        $id = (int) $conn->insert_id;
        $stmt->close();

        return $this->printerById($conn, $id, false);
    }

    public function listActivePrinters(mysqli $conn, array $scope = []): array
    {
        $tenant = $this->nonNegativeInt($scope['tenant'] ?? 0, 'TENANT_INVALID');
        $branch = $this->nonNegativeInt($scope['branch'] ?? 0, 'BRANCH_INVALID');
        $printerType = isset($scope['printer_type'])
            ? $this->enum($scope['printer_type'], self::PRINTER_TYPES, 'PRINTER_TYPE_INVALID')
            : null;

        $sql = "
            SELECT *
            FROM printers
            WHERE tenant = ?
              AND branch = ?
              AND is_active = 1
        ";
        $types = 'ii';
        $params = [$tenant, $branch];
        if ($printerType !== null) {
            $sql .= " AND printer_type = ?";
            $types .= 's';
            $params[] = $printerType;
        }
        $sql .= " ORDER BY printer_type, name, id";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $printers = [];
        while ($row = $result->fetch_assoc()) {
            $printers[] = $this->formatPrinter($row);
        }
        $stmt->close();

        return $printers;
    }

    public function listPrinters(mysqli $conn, array $scope = []): array
    {
        $tenant = $this->nonNegativeInt($scope['tenant'] ?? 0, 'TENANT_INVALID');
        $branch = $this->nonNegativeInt($scope['branch'] ?? 0, 'BRANCH_INVALID');
        $stmt = $conn->prepare("
            SELECT *
            FROM printers
            WHERE tenant = ?
              AND branch = ?
            ORDER BY is_active DESC, printer_type, name, id
        ");
        $stmt->bind_param('ii', $tenant, $branch);
        $stmt->execute();
        $result = $stmt->get_result();

        $printers = [];
        while ($row = $result->fetch_assoc()) {
            $printers[] = $this->formatPrinter($row);
        }
        $stmt->close();

        return $printers;
    }

    public function getPrinter(mysqli $conn, int $printerId, bool $activeOnly = false): array
    {
        return $this->printerById($conn, $printerId, $activeOnly);
    }

    public function getPrinterInScope(
        mysqli $conn,
        int $printerId,
        array $scope,
        bool $activeOnly = false
    ): array {
        $printer = $this->printerById($conn, $printerId, $activeOnly);
        $tenant = $this->nonNegativeInt($scope['tenant'] ?? 0, 'TENANT_INVALID');
        $branch = $this->nonNegativeInt($scope['branch'] ?? 0, 'BRANCH_INVALID');
        if ($printer['tenant'] !== $tenant || $printer['branch'] !== $branch) {
            throw new RuntimeException('PRINTER_NOT_FOUND');
        }

        return $printer;
    }

    public function enqueue(mysqli $conn, array $data): array
    {
        $jobType = $this->enum($data['job_type'] ?? $data['type'] ?? '', self::JOB_TYPES, 'PRINT_JOB_TYPE_INVALID');
        $orderId = $this->optionalPositiveInt($data['order_id'] ?? null);
        $drawerSessionId = $this->optionalPositiveInt($data['drawer_session_id'] ?? null);
        $printerId = $this->optionalPositiveInt($data['printer_id'] ?? null);
        if ($printerId !== null) {
            $this->printerById($conn, $printerId, true);
        }
        $payloadJson = $this->payloadJson($data['payload'] ?? $data['payload_json'] ?? null);
        $payloadHash = hash('sha256', $payloadJson);
        $idempotencyKey = $this->nullableText($data['idempotency_key'] ?? null, 191);
        $maxAttempts = min(25, max(1, (int) ($data['max_attempts'] ?? 5)));
        $createdBy = $this->optionalPositiveInt($data['created_by'] ?? null);

        if (!$this->supportsReliableDelivery($conn)) {
            if ($idempotencyKey !== null) {
                throw new RuntimeException('PRINT_RELIABLE_SCHEMA_REQUIRED');
            }
            return $this->enqueueLegacy(
                $conn,
                $jobType,
                $orderId,
                $drawerSessionId,
                $printerId,
                $payloadJson,
                $createdBy
            );
        }

        $stmt = $conn->prepare("
            INSERT INTO print_jobs (
                job_type, order_id, drawer_session_id, printer_id,
                payload_json, idempotency_key, payload_hash, max_attempts, created_by
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            'siiisssii',
            $jobType,
            $orderId,
            $drawerSessionId,
            $printerId,
            $payloadJson,
            $idempotencyKey,
            $payloadHash,
            $maxAttempts,
            $createdBy
        );
        try {
            $stmt->execute();
            $id = (int) $conn->insert_id;
            $stmt->close();
        } catch (mysqli_sql_exception $exception) {
            $stmt->close();
            if ($idempotencyKey === null || (int) $exception->getCode() !== 1062) {
                throw $exception;
            }

            $existing = $this->jobByIdempotencyKey($conn, $idempotencyKey);
            if (
                $existing['payload_hash'] !== $payloadHash
                || $existing['job_type'] !== $jobType
                || $existing['order_id'] !== $orderId
                || $existing['printer_id'] !== $printerId
            ) {
                throw new RuntimeException('PRINT_IDEMPOTENCY_CONFLICT');
            }

            return $existing;
        }

        return $this->jobById($conn, $id);
    }

    public function queuedJobs(mysqli $conn, int $limit = 50): array
    {
        $limit = min(100, max(1, $limit));
        $stmt = $conn->prepare("
            SELECT *
            FROM print_jobs
            WHERE status = 'queued'
            ORDER BY created_at, id
            LIMIT ?
        ");
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $jobs = [];
        while ($row = $result->fetch_assoc()) {
            $jobs[] = $this->formatJob($row);
        }
        $stmt->close();

        return $jobs;
    }

    public function recentJobs(mysqli $conn, array $scope = [], int $limit = 50): array
    {
        $tenant = $this->nonNegativeInt($scope['tenant'] ?? 0, 'TENANT_INVALID');
        $branch = $this->nonNegativeInt($scope['branch'] ?? 0, 'BRANCH_INVALID');
        $limit = min(100, max(1, $limit));
        $stmt = $conn->prepare("
            SELECT jobs.*
            FROM print_jobs jobs
            INNER JOIN printers printer ON printer.id = jobs.printer_id
            WHERE printer.tenant = ?
              AND printer.branch = ?
            ORDER BY jobs.created_at DESC, jobs.id DESC
            LIMIT ?
        ");
        $stmt->bind_param('iii', $tenant, $branch, $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $jobs = [];
        while ($row = $result->fetch_assoc()) {
            $jobs[] = $this->formatJob($row);
        }
        $stmt->close();

        return $jobs;
    }

    public function claim(mysqli $conn, int $jobId, string $workerId, int $lockSeconds = 45): array
    {
        $this->requireReliableDeliverySchema($conn);
        $jobId = $this->positiveInt($jobId, 'PRINT_JOB_REQUIRED');
        $workerId = $this->requiredText($workerId, 64, 'PRINT_WORKER_REQUIRED');
        $lockSeconds = min(300, max(10, $lockSeconds));

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("
                SELECT *
                FROM print_jobs
                WHERE id = ?
                FOR UPDATE
            ");
            $stmt->bind_param('i', $jobId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) {
                throw new RuntimeException('PRINT_JOB_NOT_FOUND');
            }
            if ((string) $row['status'] !== 'queued') {
                throw new RuntimeException('PRINT_JOB_NOT_QUEUED');
            }
            if (
                $row['next_retry_at'] !== null
                && strtotime((string) $row['next_retry_at']) > time()
            ) {
                throw new RuntimeException('PRINT_JOB_RETRY_NOT_DUE');
            }
            if (
                trim((string) ($row['locked_by'] ?? '')) !== ''
                && $row['locked_until'] !== null
                && strtotime((string) $row['locked_until']) > time()
            ) {
                throw new RuntimeException('PRINT_JOB_ALREADY_CLAIMED');
            }

            $lockUntil = date('Y-m-d H:i:s.u', time() + $lockSeconds);
            $update = $conn->prepare("
                UPDATE print_jobs
                SET locked_by = ?,
                    locked_until = ?
                WHERE id = ?
                  AND status = 'queued'
            ");
            $update->bind_param('ssi', $workerId, $lockUntil, $jobId);
            $update->execute();
            if ($update->affected_rows !== 1) {
                $update->close();
                throw new RuntimeException('PRINT_JOB_CLAIM_FAILED');
            }
            $update->close();
            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }

        return $this->jobById($conn, $jobId);
    }

    public function claimNext(mysqli $conn, string $workerId, int $lockSeconds = 45): ?array
    {
        $this->requireReliableDeliverySchema($conn);
        $stmt = $conn->prepare("
            SELECT jobs.id
            FROM print_jobs jobs
            INNER JOIN printers printer ON printer.id = jobs.printer_id
            WHERE jobs.status = 'queued'
              AND printer.connection_type IN ('network', 'usb')
              AND (jobs.next_retry_at IS NULL OR jobs.next_retry_at <= NOW(6))
              AND (jobs.locked_until IS NULL OR jobs.locked_until <= NOW(6))
            ORDER BY jobs.created_at, jobs.id
            LIMIT 1
        ");
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }

        try {
            return $this->claim($conn, (int) $row['id'], $workerId, $lockSeconds);
        } catch (RuntimeException $exception) {
            if (in_array($exception->getMessage(), [
                'PRINT_JOB_ALREADY_CLAIMED',
                'PRINT_JOB_NOT_QUEUED',
                'PRINT_JOB_RETRY_NOT_DUE',
                'PRINT_JOB_CLAIM_FAILED',
            ], true)) {
                // Another worker won the race between the candidate read and
                // the row-locked claim. This is normal contention, not a
                // worker-fatal error; the next tick will select another job.
                return null;
            }
            throw $exception;
        }
    }

    public function completeClaim(
        mysqli $conn,
        int $jobId,
        string $workerId,
        array $deliveryReceipt
    ): array {
        $this->requireReliableDeliverySchema($conn);
        $jobId = $this->positiveInt($jobId, 'PRINT_JOB_REQUIRED');
        $workerId = $this->requiredText($workerId, 64, 'PRINT_WORKER_REQUIRED');
        $receiptJson = $this->jsonOrNull($deliveryReceipt, 'PRINT_DELIVERY_RECEIPT_INVALID');
        $printedAt = date('Y-m-d H:i:s');

        $stmt = $conn->prepare("
            UPDATE print_jobs
            SET status = 'printed',
                attempts = attempts + 1,
                last_error = NULL,
                next_retry_at = NULL,
                locked_by = NULL,
                locked_until = NULL,
                delivery_receipt_json = ?,
                printed_at = ?
            WHERE id = ?
              AND status = 'queued'
              AND locked_by = ?
        ");
        $stmt->bind_param('ssis', $receiptJson, $printedAt, $jobId, $workerId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected !== 1) {
            throw new RuntimeException('PRINT_JOB_CLAIM_LOST');
        }

        return $this->jobById($conn, $jobId);
    }

    public function failClaim(
        mysqli $conn,
        int $jobId,
        string $workerId,
        string $error,
        int $retryDelaySeconds = 15
    ): array {
        $this->requireReliableDeliverySchema($conn);
        $jobId = $this->positiveInt($jobId, 'PRINT_JOB_REQUIRED');
        $workerId = $this->requiredText($workerId, 64, 'PRINT_WORKER_REQUIRED');
        $error = $this->requiredText($error, 500, 'PRINT_JOB_ERROR_REQUIRED');
        $retryDelaySeconds = min(3600, max(1, $retryDelaySeconds));

        $job = $this->jobById($conn, $jobId);
        if ($job['status'] !== 'queued' || $job['locked_by'] !== $workerId) {
            throw new RuntimeException('PRINT_JOB_CLAIM_LOST');
        }
        $finalFailure = ($job['attempts'] + 1) >= $job['max_attempts'];
        $status = $finalFailure ? 'failed' : 'queued';
        $nextRetryAt = $finalFailure ? null : date('Y-m-d H:i:s.u', time() + $retryDelaySeconds);

        $stmt = $conn->prepare("
            UPDATE print_jobs
            SET status = ?,
                attempts = attempts + 1,
                last_error = ?,
                next_retry_at = ?,
                locked_by = NULL,
                locked_until = NULL
            WHERE id = ?
              AND status = 'queued'
              AND locked_by = ?
        ");
        $stmt->bind_param('sssis', $status, $error, $nextRetryAt, $jobId, $workerId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected !== 1) {
            throw new RuntimeException('PRINT_JOB_CLAIM_LOST');
        }

        return $this->jobById($conn, $jobId);
    }

    public function failClaimWithoutRetry(
        mysqli $conn,
        int $jobId,
        string $workerId,
        string $error
    ): array {
        $this->requireReliableDeliverySchema($conn);
        $jobId = $this->positiveInt($jobId, 'PRINT_JOB_REQUIRED');
        $workerId = $this->requiredText($workerId, 64, 'PRINT_WORKER_REQUIRED');
        $error = $this->requiredText($error, 500, 'PRINT_JOB_ERROR_REQUIRED');

        $stmt = $conn->prepare("
            UPDATE print_jobs
            SET status = 'failed',
                attempts = attempts + 1,
                last_error = ?,
                next_retry_at = NULL,
                locked_by = NULL,
                locked_until = NULL
            WHERE id = ?
              AND status = 'queued'
              AND locked_by = ?
        ");
        $stmt->bind_param('sis', $error, $jobId, $workerId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected !== 1) {
            throw new RuntimeException('PRINT_JOB_CLAIM_LOST');
        }

        return $this->jobById($conn, $jobId);
    }

    public function releaseForRetry(mysqli $conn, int $jobId): array
    {
        $this->requireReliableDeliverySchema($conn);
        $jobId = $this->positiveInt($jobId, 'PRINT_JOB_REQUIRED');
        $stmt = $conn->prepare("
            UPDATE print_jobs
            SET status = 'queued',
                next_retry_at = NULL,
                locked_by = NULL,
                locked_until = NULL
            WHERE id = ?
              AND status = 'failed'
        ");
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected !== 1) {
            throw new RuntimeException('PRINT_JOB_NOT_FAILED');
        }

        return $this->jobById($conn, $jobId);
    }

    public function supportsReliableDelivery(mysqli $conn): bool
    {
        foreach ([
            'idempotency_key',
            'payload_hash',
            'max_attempts',
            'next_retry_at',
            'locked_by',
            'locked_until',
            'delivery_receipt_json',
        ] as $column) {
            if (!$this->columnExists($conn, 'print_jobs', $column)) {
                return false;
            }
        }

        return true;
    }

    public function markPrinted(mysqli $conn, int $jobId): array
    {
        return $this->markJob($conn, $jobId, 'printed', null);
    }

    public function markFailed(mysqli $conn, int $jobId, string $error): array
    {
        return $this->markJob($conn, $jobId, 'failed', $this->requiredText($error, 500, 'PRINT_JOB_ERROR_REQUIRED'));
    }

    public function cancel(mysqli $conn, int $jobId, string $reason): array
    {
        return $this->markJob($conn, $jobId, 'cancelled', $this->requiredText($reason, 500, 'PRINT_JOB_CANCEL_REASON_REQUIRED'));
    }

    public function cloneForReprint(mysqli $conn, int $jobId, string $reason, ?int $createdBy = null): array
    {
        $reason = $this->requiredText($reason, 255, 'REPRINT_REASON_REQUIRED');
        $source = $this->jobById($conn, $jobId);
        $payload = is_array($source['payload']) ? $source['payload'] : [];
        $payload['reprint'] = [
            'source_job_id' => $source['id'],
            'reason' => $reason,
        ];

        return $this->enqueue($conn, [
            'job_type' => $source['job_type'],
            'order_id' => $source['order_id'],
            'drawer_session_id' => $source['drawer_session_id'],
            'printer_id' => $source['printer_id'],
            'payload' => $payload,
            'created_by' => $createdBy,
        ]);
    }

    public function jobById(mysqli $conn, int $jobId): array
    {
        $jobId = $this->positiveInt($jobId, 'PRINT_JOB_REQUIRED');
        $stmt = $conn->prepare("SELECT * FROM print_jobs WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new RuntimeException('PRINT_JOB_NOT_FOUND');
        }

        return $this->formatJob($row);
    }

    public function jobByIdempotencyKey(mysqli $conn, string $idempotencyKey): array
    {
        $this->requireReliableDeliverySchema($conn);
        $idempotencyKey = $this->requiredText($idempotencyKey, 191, 'PRINT_IDEMPOTENCY_KEY_REQUIRED');
        $stmt = $conn->prepare("SELECT * FROM print_jobs WHERE idempotency_key = ? LIMIT 1");
        $stmt->bind_param('s', $idempotencyKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new RuntimeException('PRINT_JOB_NOT_FOUND');
        }

        return $this->formatJob($row);
    }

    private function markJob(mysqli $conn, int $jobId, string $status, ?string $error): array
    {
        if (!in_array($status, self::FINAL_STATUSES, true)) {
            throw new InvalidArgumentException('PRINT_JOB_STATUS_INVALID');
        }

        $this->requireQueuedJob($conn, $jobId);
        $printedAt = $status === 'printed' ? date('Y-m-d H:i:s') : null;

        $stmt = $conn->prepare("
            UPDATE print_jobs
            SET status = ?,
                attempts = attempts + 1,
                last_error = ?,
                printed_at = ?
            WHERE id = ?
              AND status = 'queued'
        ");
        $stmt->bind_param('sssi', $status, $error, $printedAt, $jobId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected !== 1) {
            throw new RuntimeException('PRINT_JOB_NOT_QUEUED');
        }

        return $this->jobById($conn, $jobId);
    }

    private function requireQueuedJob(mysqli $conn, int $jobId): array
    {
        $job = $this->jobById($conn, $jobId);
        if ($job['status'] !== 'queued') {
            throw new RuntimeException('PRINT_JOB_NOT_QUEUED');
        }

        return $job;
    }

    private function printerById(mysqli $conn, int $id, bool $activeOnly): array
    {
        $id = $this->positiveInt($id, 'PRINTER_REQUIRED');
        $sql = "SELECT * FROM printers WHERE id = ?";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " LIMIT 1";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new RuntimeException('PRINTER_NOT_FOUND');
        }

        return $this->formatPrinter($row);
    }

    private function formatPrinter(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'printer_type' => (string) $row['printer_type'],
            'connection_type' => (string) $row['connection_type'],
            'config' => $row['config_json'] !== null ? json_decode((string) $row['config_json'], true) : null,
            'tenant' => (int) $row['tenant'],
            'branch' => (int) $row['branch'],
            'is_active' => (int) $row['is_active'] === 1,
        ];
    }

    private function formatJob(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'job_type' => (string) $row['job_type'],
            'order_id' => $row['order_id'] !== null ? (int) $row['order_id'] : null,
            'drawer_session_id' => $row['drawer_session_id'] !== null ? (int) $row['drawer_session_id'] : null,
            'printer_id' => $row['printer_id'] !== null ? (int) $row['printer_id'] : null,
            'status' => (string) $row['status'],
            'payload' => $row['payload_json'] !== null ? json_decode((string) $row['payload_json'], true) : null,
            'idempotency_key' => isset($row['idempotency_key']) && $row['idempotency_key'] !== null
                ? (string) $row['idempotency_key']
                : null,
            'payload_hash' => isset($row['payload_hash']) && $row['payload_hash'] !== null
                ? (string) $row['payload_hash']
                : null,
            'attempts' => (int) $row['attempts'],
            'max_attempts' => isset($row['max_attempts']) ? (int) $row['max_attempts'] : 1,
            'last_error' => $row['last_error'] !== null ? (string) $row['last_error'] : null,
            'next_retry_at' => isset($row['next_retry_at']) && $row['next_retry_at'] !== null
                ? (string) $row['next_retry_at']
                : null,
            'locked_by' => isset($row['locked_by']) && $row['locked_by'] !== null
                ? (string) $row['locked_by']
                : null,
            'locked_until' => isset($row['locked_until']) && $row['locked_until'] !== null
                ? (string) $row['locked_until']
                : null,
            'delivery_receipt' => isset($row['delivery_receipt_json']) && $row['delivery_receipt_json'] !== null
                ? json_decode((string) $row['delivery_receipt_json'], true)
                : null,
            'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'created_at' => (string) $row['created_at'],
            'printed_at' => $row['printed_at'] !== null ? (string) $row['printed_at'] : null,
        ];
    }

    private function enqueueLegacy(
        mysqli $conn,
        string $jobType,
        ?int $orderId,
        ?int $drawerSessionId,
        ?int $printerId,
        string $payloadJson,
        ?int $createdBy
    ): array {
        $stmt = $conn->prepare("
            INSERT INTO print_jobs (
                job_type, order_id, drawer_session_id, printer_id,
                payload_json, created_by
            )
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('siiisi', $jobType, $orderId, $drawerSessionId, $printerId, $payloadJson, $createdBy);
        $stmt->execute();
        $id = (int) $conn->insert_id;
        $stmt->close();

        return $this->jobById($conn, $id);
    }

    private function requireReliableDeliverySchema(mysqli $conn): void
    {
        if (!$this->supportsReliableDelivery($conn)) {
            throw new RuntimeException('PRINT_RELIABLE_SCHEMA_REQUIRED');
        }
    }

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS c
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['c'] ?? 0) > 0;
    }

    private function payloadJson($value): string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException('PRINT_JOB_PAYLOAD_INVALID');
            }
            $value = $decoded;
        }

        if (!is_array($value) || $value === []) {
            throw new InvalidArgumentException('PRINT_JOB_PAYLOAD_INVALID');
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new InvalidArgumentException('PRINT_JOB_PAYLOAD_INVALID');
        }

        return $json;
    }

    private function jsonOrNull($value, string $code): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException($code);
            }
            $value = $decoded;
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException($code);
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new InvalidArgumentException($code);
        }

        return $json;
    }

    private function enum($value, array $allowed, string $code): string
    {
        $value = strtolower(trim((string) $value));
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($code);
        }

        return $value;
    }

    private function requiredText($value, int $maxLength, string $code): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            throw new InvalidArgumentException($code);
        }

        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $maxLength);
        }

        return substr($text, 0, $maxLength);
    }

    private function nullableText($value, int $maxLength): ?string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }

        return $this->requiredText($text, $maxLength, 'PRINT_TEXT_INVALID');
    }

    private function positiveInt($value, string $code): int
    {
        $value = (int) $value;
        if ($value < 1) {
            throw new InvalidArgumentException($code);
        }

        return $value;
    }

    private function optionalPositiveInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (int) $value;
        return $value > 0 ? $value : null;
    }

    private function nonNegativeInt($value, string $code): int
    {
        $value = (int) $value;
        if ($value < 0) {
            throw new InvalidArgumentException($code);
        }

        return $value;
    }

    private function boolInt($value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_numeric($value)) {
            return (int) $value === 1 ? 1 : 0;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
    }
}
