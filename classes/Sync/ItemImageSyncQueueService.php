<?php

require_once __DIR__ . '/ItemImagePathService.php';
require_once __DIR__ . '/SchemaReadinessGuard.php';

class ItemImageSyncQueueService
{
    public function ensureSchema(mysqli $conn): void
    {
        (new SyncSchemaReadinessGuard())->assertReady($conn);
    }

    public function enqueueFromImgsRow(
        mysqli $conn,
        string $branchUuid,
        array $row,
        string $direction = 'branch_to_cloud'
    ): ?int {
        $imgsId = (int) ($row['id'] ?? 0);
        $itemId = (int) ($row['itemid'] ?? 0);
        $fileName = ItemImagePathService::sanitizeFileName((string) ($row['iname'] ?? ''));
        if ($imgsId <= 0 || $itemId <= 0 || $fileName === null) {
            return null;
        }

        if (!empty($row['isdeleted'])) {
            return $this->markSkipped($conn, $branchUuid, $imgsId, $direction, 'image_deleted');
        }

        $fileSize = max(0, (int) ($row['size'] ?? 0));
        $absolutePath = ItemImagePathService::absolutePath($fileName);
        $sha256 = $absolutePath !== null ? ItemImagePathService::fileSha256($absolutePath) : null;
        if ($direction === 'branch_to_cloud' && ($absolutePath === null || $sha256 === null)) {
            return $this->upsertQueueRow($conn, $branchUuid, $imgsId, $itemId, $fileName, $fileSize, $sha256, $direction, 'missing_file', 'local_file_missing');
        }

        return $this->upsertQueueRow($conn, $branchUuid, $imgsId, $itemId, $fileName, $fileSize, $sha256, $direction, 'pending', null);
    }

    public function scanBranchUploadQueue(mysqli $conn, string $branchUuid): int
    {
        $this->ensureSchema($conn);
        $branchUuid = strtolower(trim($branchUuid));
        if ($branchUuid === '') {
            return 0;
        }

        $queued = 0;
        $result = $conn->query("
            SELECT id, iname, itemid, size, COALESCE(isdeleted, 0) AS isdeleted
            FROM imgs
            WHERE itemid > 0
              AND COALESCE(clprofile, 0) = 0
              AND COALESCE(isdeleted, 0) = 0
            ORDER BY id ASC
        ");
        if (!$result) {
            return 0;
        }

        while ($row = $result->fetch_assoc()) {
            $id = $this->enqueueFromImgsRow($conn, $branchUuid, $row, 'branch_to_cloud');
            if ($id !== null) {
                $queued++;
            }
        }
        $result->free();

        return $queued;
    }

    public function scanBranchDownloadQueue(mysqli $conn, string $branchUuid): int
    {
        $this->ensureSchema($conn);
        $branchUuid = strtolower(trim($branchUuid));
        if ($branchUuid === '') {
            return 0;
        }

        $queued = 0;
        $result = $conn->query("
            SELECT id, iname, itemid, size, COALESCE(isdeleted, 0) AS isdeleted
            FROM imgs
            WHERE itemid > 0
              AND COALESCE(clprofile, 0) = 0
              AND COALESCE(isdeleted, 0) = 0
            ORDER BY id ASC
        ");
        if (!$result) {
            return 0;
        }

        while ($row = $result->fetch_assoc()) {
            $fileName = ItemImagePathService::sanitizeFileName((string) ($row['iname'] ?? ''));
            if ($fileName === null) {
                continue;
            }

            $absolutePath = ItemImagePathService::absolutePath($fileName);
            if ($absolutePath !== null) {
                continue;
            }

            $id = $this->enqueueFromImgsRow($conn, $branchUuid, $row, 'cloud_to_branch');
            if ($id !== null) {
                $queued++;
            }
        }
        $result->free();

        return $queued;
    }

    public function claimBatch(
        mysqli $conn,
        string $branchUuid,
        string $direction,
        string $workerId,
        int $limit,
        int $lockSeconds = 120
    ): array {
        $this->ensureSchema($conn);
        $branchUuid = strtolower(trim($branchUuid));
        $direction = $direction === 'cloud_to_branch' ? 'cloud_to_branch' : 'branch_to_cloud';
        $limit = max(1, min(20, $limit));
        $lockSeconds = max(30, $lockSeconds);
        $workerId = substr(trim($workerId), 0, 120);
        if ($workerId === '' || $branchUuid === '') {
            return [];
        }

        $claimed = [];
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("
                SELECT id
                FROM sync_image_queue
                WHERE branch_uuid = ?
                  AND direction = ?
                  AND status IN ('pending', 'failed')
                  AND attempts < 8
                  AND (locked_until IS NULL OR locked_until < UTC_TIMESTAMP(6))
                ORDER BY id ASC
                LIMIT ?
                FOR UPDATE
            ");
            $stmt->bind_param('ssi', $branchUuid, $direction, $limit);
            $stmt->execute();
            $result = $stmt->get_result();
            $ids = [];
            while ($row = $result->fetch_assoc()) {
                $ids[] = (int) ($row['id'] ?? 0);
            }
            $stmt->close();

            if ($ids === []) {
                $conn->commit();

                return [];
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $types = str_repeat('i', count($ids));
            $update = $conn->prepare("
                UPDATE sync_image_queue
                SET status = 'uploading',
                    locked_by = ?,
                    locked_until = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND),
                    updated_at = UTC_TIMESTAMP(6)
                WHERE id IN ({$placeholders})
            ");
            $params = array_merge([$workerId, $lockSeconds], $ids);
            $bindTypes = 'si' . $types;
            $update->bind_param($bindTypes, ...$params);
            $update->execute();
            $update->close();

            $select = $conn->prepare("SELECT * FROM sync_image_queue WHERE id IN ({$placeholders}) ORDER BY id ASC");
            $select->bind_param($types, ...$ids);
            $select->execute();
            $rows = $select->get_result();
            while ($row = $rows->fetch_assoc()) {
                $claimed[] = $row;
            }
            $select->close();

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        return $claimed;
    }

    public function markSynced(mysqli $conn, int $queueId, ?string $sha256 = null): void
    {
        $stmt = $conn->prepare("
            UPDATE sync_image_queue
            SET status = 'synced',
                file_sha256 = COALESCE(?, file_sha256),
                synced_at = UTC_TIMESTAMP(6),
                locked_until = NULL,
                locked_by = NULL,
                last_error = NULL,
                updated_at = UTC_TIMESTAMP(6)
            WHERE id = ?
        ");
        $stmt->bind_param('si', $sha256, $queueId);
        $stmt->execute();
        $stmt->close();
    }

    public function markFailed(mysqli $conn, int $queueId, string $error): void
    {
        $error = substr(trim($error), 0, 500);
        $stmt = $conn->prepare("
            UPDATE sync_image_queue
            SET status = 'failed',
                attempts = attempts + 1,
                last_error = ?,
                locked_until = NULL,
                locked_by = NULL,
                updated_at = UTC_TIMESTAMP(6)
            WHERE id = ?
        ");
        $stmt->bind_param('si', $error, $queueId);
        $stmt->execute();
        $stmt->close();
    }

    public function markSkipped(mysqli $conn, string $branchUuid, int $imgsId, string $direction, string $reason): ?int
    {
        return $this->upsertQueueRow(
            $conn,
            $branchUuid,
            $imgsId,
            0,
            '',
            0,
            null,
            $direction,
            'skipped',
            substr($reason, 0, 500)
        );
    }

    public function releaseStaleLocks(mysqli $conn): int
    {
        $this->ensureSchema($conn);
        $conn->query("
            UPDATE sync_image_queue
            SET status = IF(status = 'uploading', 'pending', status),
                locked_until = NULL,
                locked_by = NULL,
                updated_at = UTC_TIMESTAMP(6)
            WHERE status = 'uploading'
              AND locked_until IS NOT NULL
              AND locked_until < UTC_TIMESTAMP(6)
        ");

        return (int) $conn->affected_rows;
    }

    public function countByStatus(mysqli $conn, string $branchUuid, string $direction = ''): array
    {
        $this->ensureSchema($conn);
        $branchUuid = strtolower(trim($branchUuid));
        $counts = [
            'pending' => 0,
            'uploading' => 0,
            'synced' => 0,
            'failed' => 0,
            'missing_file' => 0,
            'skipped' => 0,
            'total' => 0,
        ];

        if ($branchUuid === '') {
            return $counts;
        }

        $sql = "
            SELECT status, COUNT(*) AS c
            FROM sync_image_queue
            WHERE branch_uuid = ?
        ";
        if ($direction !== '') {
            $sql .= " AND direction = ?";
            $stmt = $conn->prepare($sql . ' GROUP BY status');
            $stmt->bind_param('ss', $branchUuid, $direction);
        } else {
            $stmt = $conn->prepare($sql . ' GROUP BY status');
            $stmt->bind_param('s', $branchUuid);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $status = (string) ($row['status'] ?? '');
            $count = (int) ($row['c'] ?? 0);
            if (array_key_exists($status, $counts)) {
                $counts[$status] = $count;
            }
            $counts['total'] += $count;
        }
        $stmt->close();

        return $counts;
    }

    private function upsertQueueRow(
        mysqli $conn,
        string $branchUuid,
        int $imgsId,
        int $itemId,
        string $fileName,
        int $fileSize,
        ?string $sha256,
        string $direction,
        string $status,
        ?string $lastError
    ): ?int {
        $this->ensureSchema($conn);
        $branchUuid = strtolower(trim($branchUuid));
        if ($branchUuid === '' || $imgsId <= 0) {
            return null;
        }

        $existing = $this->findQueueRow($conn, $branchUuid, $imgsId, $direction);
        if ($existing !== null) {
            $existingStatus = (string) ($existing['status'] ?? '');
            $existingHash = (string) ($existing['file_sha256'] ?? '');
            $existingName = (string) ($existing['file_name'] ?? '');
            if ($existingStatus === 'synced'
                && $sha256 !== null
                && $existingHash === $sha256
                && $existingName === $fileName) {
                return (int) ($existing['id'] ?? 0);
            }

            if (in_array($existingStatus, ['synced', 'skipped', 'missing_file'], true) && $status === 'pending') {
                $stmt = $conn->prepare("
                    UPDATE sync_image_queue
                    SET item_id = ?,
                        file_name = ?,
                        file_size = ?,
                        file_sha256 = ?,
                        status = ?,
                        last_error = ?,
                        attempts = 0,
                        locked_until = NULL,
                        locked_by = NULL,
                        synced_at = NULL,
                        updated_at = UTC_TIMESTAMP(6)
                    WHERE id = ?
                ");
                $queueId = (int) ($existing['id'] ?? 0);
                $stmt->bind_param('isisssi', $itemId, $fileName, $fileSize, $sha256, $status, $lastError, $queueId);
                $stmt->execute();
                $stmt->close();

                return $queueId;
            }

            if ($existingStatus === 'pending' || $existingStatus === 'failed' || $existingStatus === 'uploading') {
                $stmt = $conn->prepare("
                    UPDATE sync_image_queue
                    SET item_id = ?,
                        file_name = ?,
                        file_size = ?,
                        file_sha256 = COALESCE(?, file_sha256),
                        last_error = ?,
                        updated_at = UTC_TIMESTAMP(6)
                    WHERE id = ?
                ");
                $queueId = (int) ($existing['id'] ?? 0);
                $stmt->bind_param('isissi', $itemId, $fileName, $fileSize, $sha256, $lastError, $queueId);
                $stmt->execute();
                $stmt->close();

                return $queueId;
            }

            return (int) ($existing['id'] ?? 0);
        }

        $stmt = $conn->prepare("
            INSERT INTO sync_image_queue (
                branch_uuid, imgs_id, item_id, file_name, file_size, file_sha256, direction, status, last_error
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            'siisissss',
            $branchUuid,
            $imgsId,
            $itemId,
            $fileName,
            $fileSize,
            $sha256,
            $direction,
            $status,
            $lastError
        );
        $stmt->execute();
        $queueId = (int) $conn->insert_id;
        $stmt->close();

        return $queueId > 0 ? $queueId : null;
    }

    private function findQueueRow(mysqli $conn, string $branchUuid, int $imgsId, string $direction): ?array
    {
        $stmt = $conn->prepare("
            SELECT *
            FROM sync_image_queue
            WHERE branch_uuid = ?
              AND imgs_id = ?
              AND direction = ?
            LIMIT 1
        ");
        $stmt->bind_param('sis', $branchUuid, $imgsId, $direction);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }
}
