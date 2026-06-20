<?php

class SyncObservabilityService
{
    public function localSummary(mysqli $conn, string $branchUuid): array
    {
        $branchUuid = strtolower(trim($branchUuid));

        return [
            'checkpoints' => $this->checkpoints($conn, $branchUuid),
            'outbox' => $this->outboxSummary($conn),
            'cloud_pull' => $this->latestWorkerRun($conn, 'cloud_sync_poller'),
            'outbox_push' => $this->latestWorkerRun($conn, 'sync_worker'),
        ];
    }

    public function hostedSummary(mysqli $conn, string $branchUuid): array
    {
        $branchUuid = strtolower(trim($branchUuid));

        return [
            'cloud_queue' => $this->hostedCloudQueueSummary($conn, $branchUuid),
            'last_seen_at' => $this->nullableScalar($conn, "
                SELECT last_seen_at
                FROM cloud_branches
                WHERE branch_uuid = ?
                LIMIT 1
            ", 's', $branchUuid),
        ];
    }

    private function checkpoints(mysqli $conn, string $branchUuid): array
    {
        if (!$this->tableExists($conn, 'sync_checkpoints')) {
            return [];
        }

        $stmt = $conn->prepare("
            SELECT stream_name, last_cursor, last_event_time, updated_at
            FROM sync_checkpoints
            WHERE branch_uuid = ?
            ORDER BY stream_name ASC
        ");
        $stmt->bind_param('s', $branchUuid);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'stream_name' => (string) ($row['stream_name'] ?? ''),
                'last_cursor' => (int) ($row['last_cursor'] ?? 0),
                'last_event_time' => (string) ($row['last_event_time'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }
        $stmt->close();

        return $rows;
    }

    private function outboxSummary(mysqli $conn): array
    {
        if (!$this->tableExists($conn, 'sync_outbox')) {
            return [];
        }

        return [
            'counts_by_status' => $this->countsByStatus($conn, 'sync_outbox', 'status'),
            'retryable_due' => (int) $this->scalar($conn, "
                SELECT COUNT(*) AS value FROM sync_outbox
                WHERE status IN ('pending','failed')
                  AND (next_retry_at IS NULL OR next_retry_at <= NOW(6))
            "),
            'dead_rows' => (int) $this->scalar($conn, "
                SELECT COUNT(*) AS value FROM sync_outbox WHERE status = 'dead'
            "),
            'last_success_at' => $this->nullableScalar($conn, "
                SELECT MAX(updated_at) AS value FROM sync_outbox WHERE status = 'synced'
            "),
            'recent_errors' => $this->rows($conn, "
                SELECT id, status, attempts, last_error, updated_at
                FROM sync_outbox
                WHERE last_error IS NOT NULL
                ORDER BY updated_at DESC, id DESC
                LIMIT 5
            "),
        ];
    }

    private function hostedCloudQueueSummary(mysqli $conn, string $branchUuid): array
    {
        if (!$this->tableExists($conn, 'cloud_sync_branch_events')) {
            return [];
        }

        $stmt = $conn->prepare("
            SELECT status, COUNT(*) AS count
            FROM cloud_sync_branch_events
            WHERE branch_uuid = ?
            GROUP BY status
        ");
        $stmt->bind_param('s', $branchUuid);
        $stmt->execute();
        $result = $stmt->get_result();
        $counts = [];
        while ($row = $result->fetch_assoc()) {
            $counts[(string) $row['status']] = (int) $row['count'];
        }
        $stmt->close();

        return [
            'counts_by_status' => $counts,
            'pending' => (int) ($counts['pending'] ?? 0),
            'dead' => (int) ($counts['dead'] ?? 0),
            'failed' => (int) ($counts['failed'] ?? 0) + (int) ($counts['ack_failed'] ?? 0),
        ];
    }

    private function latestWorkerRun(mysqli $conn, string $workerName): ?array
    {
        if (!$this->tableExists($conn, 'sync_worker_logs')) {
            return null;
        }

        $stmt = $conn->prepare("
            SELECT worker_name, status, message, created_at, metrics_json
            FROM sync_worker_logs
            WHERE worker_name = ?
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->bind_param('s', $workerName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return null;
        }

        return [
            'worker_name' => (string) ($row['worker_name'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'message' => (string) ($row['message'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'metrics' => json_decode((string) ($row['metrics_json'] ?? ''), true) ?: [],
        ];
    }

    private function countsByStatus(mysqli $conn, string $table, string $column): array
    {
        $rows = $this->rows($conn, "SELECT {$column} AS name, COUNT(*) AS count FROM {$table} GROUP BY {$column}");
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['name']] = (int) $row['count'];
        }

        return $counts;
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS c
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['c'] ?? 0) > 0;
    }

    private function scalar(mysqli $conn, string $sql): int
    {
        $row = $conn->query($sql)->fetch_assoc();

        return (int) ($row['value'] ?? $row['COUNT(*)'] ?? 0);
    }

    private function nullableScalar(mysqli $conn, string $sql, ?string $type = null, ?string $param = null)
    {
        if ($type !== null) {
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($type, $param);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            return $row['last_seen_at'] ?? $row['value'] ?? null;
        }

        $row = $conn->query($sql)->fetch_assoc();

        return $row['value'] ?? null;
    }

    private function rows(mysqli $conn, string $sql): array
    {
        $result = $conn->query($sql);
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        return $rows;
    }
}
