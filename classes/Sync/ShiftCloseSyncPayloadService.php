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
            $summary['session_notes'],
            $summary['drawer_register_id'],
            $summary['drawer_fund_account_id'],
            $summary['drawer_business_day'],
            $summary['drawer_opened_by'],
            $summary['drawer_opening_cash'],
            $summary['drawer_expected_opening_cash'],
            $summary['drawer_opening_variance'],
            $summary['drawer_closed_by'],
            $summary['drawer_close_expected_snapshot']
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

        $drawerSession = [
            'uuid' => $drawerSessionUuid,
            'user_id' => (int) $row['user_id'],
            'tenant' => (int) $row['tenant'],
            'branch' => (int) $row['branch'],
            'register_id' => $this->intOrNull($row['drawer_register_id'] ?? null),
            'fund_account_id' => $this->intOrNull($row['drawer_fund_account_id'] ?? null),
            'opened_at' => $this->datetimeOrNull($row['opened_at'] ?? null),
            'business_day' => $row['drawer_business_day'] ?? null,
            'opened_by' => (int) ($row['drawer_opened_by'] ?? $row['user_id']),
            'opening_cash' => $row['drawer_opening_cash'] ?? 0,
            'expected_opening_cash' => $row['drawer_expected_opening_cash'] ?? null,
            'opening_variance' => $row['drawer_opening_variance'] ?? null,
            'closed_at' => $this->datetimeOrNull($row['closed_at'] ?? null),
            'closed_by' => $this->intOrNull($row['drawer_closed_by'] ?? null),
            'expected_cash' => $row['expected_cash'] ?? null,
            'counted_cash' => $row['counted_cash'] ?? null,
            'difference' => $row['difference'] ?? null,
            'close_expected_snapshot' => $row['drawer_close_expected_snapshot'] ?? null,
            'status' => (string) ($row['session_status'] ?? 'closed'),
            'variance_status' => (string) ($row['variance_status'] ?? 'none'),
            'variance_type' => (string) ($row['variance_type'] ?? 'none'),
            'notes' => $row['session_notes'] ?? null,
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
            'drawer_session' => $drawerSession,
            'close_summary' => $summary,
        ];
    }

    private function fetchRow(mysqli $conn, int $rowId): ?array
    {
        $stmt = $conn->prepare(
            'SELECT cs.*, cs.uuid AS close_uuid,
                    ds.uuid AS drawer_session_uuid, ds.user_id, ds.tenant, ds.branch,
                    ds.register_id AS drawer_register_id,
                    ds.fund_account_id AS drawer_fund_account_id,
                    ds.business_day AS drawer_business_day,
                    ds.opened_by AS drawer_opened_by,
                    ds.opening_cash AS drawer_opening_cash,
                    ds.expected_opening_cash AS drawer_expected_opening_cash,
                    ds.opening_variance AS drawer_opening_variance,
                    ds.closed_by AS drawer_closed_by,
                    ds.close_expected_snapshot AS drawer_close_expected_snapshot,
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

    private function intOrNull($value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $escaped = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }
}
