<?php

class PrintJobService
{
    private const PRINTER_TYPES = ['receipt', 'kitchen', 'label', 'other'];
    private const CONNECTION_TYPES = ['browser', 'network', 'usb', 'file', 'cloud'];
    private const JOB_TYPES = ['receipt', 'kot', 'kitchen', 'z_report', 'x_report'];
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
            ");
            $stmt->bind_param('ssssiiii', $name, $printerType, $connectionType, $configJson, $tenant, $branch, $isActive, $id);
            $stmt->execute();
            if ($stmt->affected_rows < 1) {
                $stmt->close();
                throw new RuntimeException('PRINTER_NOT_FOUND');
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
        $createdBy = $this->optionalPositiveInt($data['created_by'] ?? null);

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
            'attempts' => (int) $row['attempts'],
            'last_error' => $row['last_error'] !== null ? (string) $row['last_error'] : null,
            'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'created_at' => (string) $row['created_at'],
            'printed_at' => $row['printed_at'] !== null ? (string) $row['printed_at'] : null,
        ];
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
