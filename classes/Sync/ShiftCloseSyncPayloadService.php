<?php

require_once __DIR__ . '/PosOrderSnapshotBuilder.php';

class ShiftCloseSyncPayloadService
{
    public function build(mysqli $conn, int $closedOrderId, string $branchUuid, array $options = []): ?array
    {
        if ($closedOrderId <= 0 || !$this->tableExists($conn, 'closed_orders')) {
            return null;
        }

        $row = $this->fetchRow($conn, $closedOrderId);
        if (!$row) {
            return null;
        }

        $closeUuid = PosOrderSnapshotBuilder::deterministicUuid($branchUuid, 'closed_orders:' . $closedOrderId);
        $closedAt = $this->datetimeOrNull($row['endtime'] ?? null);
        if ($closedAt === null && !empty($row['date'])) {
            $closedAt = $this->datetimeOrNull(($row['date'] ?? '') . ' ' . ($row['endtime'] ?? '00:00:00'));
        }

        $shift = [
            'local_closed_order_id' => $closedOrderId,
            'close_uuid' => $closeUuid,
            'shift_number' => (string) ($row['shift'] ?? ''),
            'cashier_user_id' => null,
            'opened_at' => $this->datetimeOrNull($row['strttime'] ?? null),
            'closed_at' => $closedAt,
            'total_sales' => $row['total_sales'] ?? 0,
            'total_cash' => $row['cash'] ?? ($row['fund_after'] ?? 0),
            'total_card' => 0,
            'actual_cash' => $row['cash'] ?? null,
            'actual_card' => null,
            'cash_deficit' => null,
            'card_deficit' => null,
            'legacy' => $row,
        ];

        return [
            'schema_version' => 1,
            'snapshot_type' => 'shift_close',
            'branch_uuid' => $branchUuid,
            'source_system' => (string) ($options['source_system'] ?? 'pos'),
            'captured_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'close_uuid' => $closeUuid,
            'shift' => $shift,
        ];
    }

    private function fetchRow(mysqli $conn, int $rowId): ?array
    {
        $stmt = $conn->prepare('SELECT * FROM closed_orders WHERE id = ? LIMIT 1');
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

        return $result && $result->num_rows > 0;
    }
}
