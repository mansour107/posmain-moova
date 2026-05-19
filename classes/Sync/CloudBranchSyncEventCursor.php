<?php

class CloudBranchSyncEventCursor
{
    public function fetchPendingAfter(mysqli $conn, string $branchUuid, int $afterCursor, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $conn->prepare("
            SELECT *
            FROM cloud_sync_branch_events
            WHERE branch_uuid = ?
              AND status = 'pending'
              AND id > ?
            ORDER BY id ASC
            LIMIT {$limit}
        ");
        $stmt->bind_param('si', $branchUuid, $afterCursor);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $row['cursor'] = (int) $row['id'];
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    public function ackByEventForBranch(
        mysqli $conn,
        string $branchUuid,
        string $eventUuid,
        string $idempotencyKey,
        string $status,
        ?string $error = null
    ): int {
        $allowed = ['ack_applied', 'ack_declined', 'ack_failed', 'dead'];
        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException('Invalid cloud sync branch event ack status.');
        }

        $stmt = $conn->prepare("
            UPDATE cloud_sync_branch_events
            SET status = ?,
                last_error = ?,
                acknowledged_at = NOW(6)
            WHERE branch_uuid = ?
              AND event_uuid = ?
              AND idempotency_key = ?
        ");
        $stmt->bind_param('sssss', $status, $error, $branchUuid, $eventUuid, $idempotencyKey);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        return $affected;
    }
}
