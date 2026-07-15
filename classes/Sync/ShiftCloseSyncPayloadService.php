<?php

class ShiftCloseSyncPayloadService
{
    /**
     * Build the only newly-emitted shift-close format. Restore readers retain
     * v1 compatibility, but branch writers always publish v2 by drawer UUID.
     */
    public function build(mysqli $conn, int $closeSummaryId, string $branchUuid, array $options = []): ?array
    {
        if ($closeSummaryId <= 0 || !$this->tableExists($conn, 'drawer_session_close_summaries')) {
            return null;
        }

        $row = $this->fetchRow($conn, $closeSummaryId);
        if (!$row) {
            return null;
        }

        $closeUuid = (string) $row['close_uuid'];
        $drawerSessionUuid = (string) $row['drawer_session_uuid'];
        $summary = $row;
        unset(
            $summary['drawer_session_uuid'],
            $summary['user_id'],
            $summary['tenant'],
            $summary['branch'],
            $summary['opened_at'],
            $summary['closed_at'],
            $summary['expected_cash'],
            $summary['counted_cash'],
            $summary['difference'],
            $summary['session_status'],
            $summary['variance_status'],
            $summary['variance_type'],
            $summary['session_notes']
        );

        $shift = [
            'local_drawer_session_id' => (int) $row['drawer_session_id'],
            'close_summary_id' => $closeSummaryId,
            'drawer_session_uuid' => $drawerSessionUuid,
            'close_uuid' => $closeUuid,
            'shift_number' => (string) $row['shift_number'],
            'cashier_user_id' => (int) $row['user_id'],
            'opened_at' => $this->datetimeOrNull($row['opened_at'] ?? null),
            'closed_at' => $this->datetimeOrNull($row['closed_at'] ?? null),
            'total_sales' => $row['total_sales'] ?? 0,
            'total_cash' => $row['cash_sales'] ?? 0,
            'total_card' => $row['non_cash_sales'] ?? 0,
            'actual_cash' => $row['counted_cash'] ?? null,
            'actual_card' => $row['counted_non_cash'] ?? null,
            'cash_deficit' => $row['difference'] ?? null,
            'card_deficit' => $row['non_cash_difference'] ?? null,
            'status' => (string) ($row['session_status'] ?? 'closed'),
            'variance_status' => (string) ($row['variance_status'] ?? 'none'),
            'variance_type' => (string) ($row['variance_type'] ?? 'none'),
        ];

        return [
            'schema_version' => 2,
            'snapshot_type' => 'shift_close',
            'branch_uuid' => $branchUuid,
            'source_system' => (string) ($options['source_system'] ?? 'pos'),
            'captured_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'close_uuid' => $closeUuid,
            'drawer_session_uuid' => $drawerSessionUuid,
            'shift' => $shift,
            'close_summary' => $summary,
        ];
    }

    private function fetchRow(mysqli $conn, int $rowId): ?array
    {
        $stmt = $conn->prepare(
            'SELECT cs.*, cs.uuid AS close_uuid,
                    ds.uuid AS drawer_session_uuid, ds.user_id, ds.tenant, ds.branch,
                    ds.opened_at, ds.closed_at, ds.expected_cash, ds.counted_cash,
                    ds.difference, ds.status AS session_status, ds.variance_status,
                    ds.variance_type, ds.notes AS session_notes
             FROM drawer_session_close_summaries cs
             INNER JOIN drawer_sessions ds ON ds.id = cs.drawer_session_id
             WHERE cs.id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $rowId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function datetimeOrNull($value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $timestamp = strtotime((string) $value);

        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $escaped = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }
}
