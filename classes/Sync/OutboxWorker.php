<?php

class OutboxWorker
{
    public function claimBatch(mysqli $conn, string $workerId, int $batchSize, int $lockSeconds = 120, ?string $branchUuid = null): array
    {
        if ($batchSize <= 0) {
            return [];
        }

        $lockSeconds = max(1, $lockSeconds);
        $limit = max(1, $batchSize);

        $ids = $this->selectClaimableIds($conn, $limit, $branchUuid);
        if ($ids === []) {
            return [];
        }

        $idList = implode(',', array_map('intval', $ids));
        $worker = $conn->real_escape_string($workerId);
        $branchSql = $this->branchFilterSql($conn, $branchUuid);

        $conn->query("
            UPDATE sync_outbox
            SET status = 'syncing',
                locked_by = '{$worker}',
                locked_until = DATE_ADD(NOW(6), INTERVAL {$lockSeconds} SECOND),
                attempts = attempts + 1
            WHERE id IN ({$idList})
              AND (
                (status IN ('pending', 'failed') AND (next_retry_at IS NULL OR next_retry_at <= NOW(6)))
                OR (status = 'syncing' AND locked_until < NOW(6))
              )
              {$branchSql}
        ");

        return $this->selectOwnedRows($conn, $workerId, $ids, $branchUuid);
    }

    private function selectClaimableIds(mysqli $conn, int $limit, ?string $branchUuid): array
    {
        $branchSql = $this->branchFilterSql($conn, $branchUuid);
        $result = $conn->query("
            SELECT id
            FROM sync_outbox
            WHERE (
                (status IN ('pending', 'failed') AND (next_retry_at IS NULL OR next_retry_at <= NOW(6)))
                OR (status = 'syncing' AND locked_until < NOW(6))
            )
              {$branchSql}
            ORDER BY id ASC
            LIMIT {$limit}
        ");

        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    private function selectOwnedRows(mysqli $conn, string $workerId, array $ids, ?string $branchUuid): array
    {
        $idList = implode(',', array_map('intval', $ids));
        $worker = $conn->real_escape_string($workerId);
        $branchSql = $this->branchFilterSql($conn, $branchUuid);
        $result = $conn->query("
            SELECT *
            FROM sync_outbox
            WHERE id IN ({$idList})
              AND status = 'syncing'
              AND locked_by = '{$worker}'
              {$branchSql}
            ORDER BY id ASC
        ");

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        return $rows;
    }

    private function branchFilterSql(mysqli $conn, ?string $branchUuid): string
    {
        $branchUuid = trim((string) $branchUuid);
        if ($branchUuid === '') {
            return '';
        }

        return "AND branch_uuid = '" . $conn->real_escape_string($branchUuid) . "'";
    }
}
