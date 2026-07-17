<?php

require_once __DIR__ . '/BranchIdentity.php';

class InventoryCountSyncPayloadService
{
    private const COUNT_FIELDS = [
        'id', 'count_uuid', 'pos_tenant', 'pos_branch', 'branch_uuid', 'store_id',
        'status', 'count_type', 'hide_expected_qty', 'include_zero_stock',
        'assigned_user_id', 'created_by', 'submitted_by', 'approved_by', 'closed_by',
        'created_at', 'submitted_at', 'approved_at', 'closed_at', 'updated_at',
        'notes', 'sync_revision',
    ];
    private const LINE_FIELDS = [
        'id', 'count_id', 'item_id', 'unit_id', 'unit_conversion_to_base',
        'snapshot_qty', 'counted_qty', 'variance_qty', 'variance_percent',
        'variance_cost', 'snapshot_last_movement_id', 'stale_count_conflict',
        'reason_code_id', 'counted_by', 'counted_at', 'notes', 'created_at', 'updated_at',
    ];
    private const STATUSES = ['draft', 'submitted', 'approved', 'rejected', 'closed', 'cancelled'];
    private const COUNT_TYPES = ['full', 'category', 'selected', 'spot'];

    public function build(mysqli $conn, string $branchUuid, int $countId): array
    {
        $branchUuid = strtolower(trim($branchUuid));
        if (!SyncBranchIdentity::isUuid($branchUuid) || $countId < 1) {
            throw new InvalidArgumentException('INVENTORY_COUNT_SYNC_IDENTITY_INVALID');
        }

        $stmt = $conn->prepare('SELECT * FROM inventory_counts WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $countId);
        $stmt->execute();
        $count = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$count) {
            throw new RuntimeException('INVENTORY_COUNT_SYNC_COUNT_MISSING');
        }
        $count = $this->selectFields($count, self::COUNT_FIELDS);

        $stmt = $conn->prepare('SELECT * FROM inventory_count_lines WHERE count_id = ? ORDER BY id ASC');
        $stmt->bind_param('i', $countId);
        $stmt->execute();
        $result = $stmt->get_result();
        $lines = [];
        while ($line = $result->fetch_assoc()) {
            $lines[] = $this->selectFields($line, self::LINE_FIELDS);
        }
        $stmt->close();

        $payload = [
            'schema_version' => 1,
            'snapshot_type' => 'inventory_count_bundle',
            'domain' => 'inventory_count',
            'branch_uuid' => $branchUuid,
            'sync_revision' => (int) ($count['sync_revision'] ?? 0),
            'inventory_count' => $count,
            'inventory_count_lines' => $lines,
            'totals' => ['line_count' => count($lines)],
        ];
        $payload['payload_hash'] = hash('sha256', self::encodeJson($payload));
        $this->assertValid($payload, $branchUuid);

        return $payload;
    }

    public function assertValid(array $payload, string $branchUuid, array $event = []): void
    {
        $branchUuid = strtolower(trim($branchUuid));
        $allowedTop = [
            'schema_version', 'snapshot_type', 'domain', 'branch_uuid', 'sync_revision',
            'inventory_count', 'inventory_count_lines', 'totals', 'payload_hash',
        ];
        if (array_diff(array_keys($payload), $allowedTop) !== []
            || array_diff($allowedTop, array_keys($payload)) !== []
            || (int) ($payload['schema_version'] ?? 0) !== 1
            || (string) ($payload['snapshot_type'] ?? '') !== 'inventory_count_bundle'
            || (string) ($payload['domain'] ?? '') !== 'inventory_count'
            || !SyncBranchIdentity::isUuid($branchUuid)
            || strtolower(trim((string) ($payload['branch_uuid'] ?? ''))) !== $branchUuid
            || !is_array($payload['inventory_count'] ?? null)
            || !is_array($payload['inventory_count_lines'] ?? null)
            || !is_array($payload['totals'] ?? null)
        ) {
            throw new RuntimeException('INVENTORY_COUNT_SYNC_PAYLOAD_INVALID');
        }

        $hashPayload = $payload;
        $expectedHash = trim((string) ($hashPayload['payload_hash'] ?? ''));
        unset($hashPayload['payload_hash']);
        $actualHash = hash('sha256', self::encodeJson($hashPayload));
        if ($expectedHash === '' || !hash_equals($expectedHash, $actualHash)) {
            throw new RuntimeException('INVENTORY_COUNT_SYNC_HASH_INVALID');
        }

        $count = $payload['inventory_count'];
        $this->assertExactFields($count, self::COUNT_FIELDS, 'INVENTORY_COUNT_SYNC_COUNT_INVALID');
        $countId = (int) ($count['id'] ?? 0);
        $revision = (int) ($payload['sync_revision'] ?? 0);
        $countUuid = strtolower(trim((string) ($count['count_uuid'] ?? '')));
        if ($countId < 1
            || $revision < 1
            || (int) ($count['sync_revision'] ?? 0) !== $revision
            || !SyncBranchIdentity::isUuid($countUuid)
            || strtolower(trim((string) ($count['branch_uuid'] ?? ''))) !== $branchUuid
            || (int) ($count['pos_tenant'] ?? -1) < 0
            || (int) ($count['pos_branch'] ?? -1) < 0
            || (int) ($count['store_id'] ?? 0) < 1
            || !in_array((string) ($count['status'] ?? ''), self::STATUSES, true)
            || !in_array((string) ($count['count_type'] ?? ''), self::COUNT_TYPES, true)
            || !$this->isBooleanInt($count['hide_expected_qty'] ?? null)
            || !$this->isBooleanInt($count['include_zero_stock'] ?? null)
        ) {
            throw new RuntimeException('INVENTORY_COUNT_SYNC_COUNT_INVALID');
        }
        foreach (['assigned_user_id', 'created_by', 'submitted_by', 'approved_by', 'closed_by'] as $field) {
            if (!$this->isNullablePositiveInt($count[$field] ?? null)) {
                throw new RuntimeException('INVENTORY_COUNT_SYNC_COUNT_INVALID');
            }
        }
        foreach (['created_at', 'submitted_at', 'approved_at', 'closed_at', 'updated_at'] as $field) {
            if (!$this->isNullableDateTime($count[$field] ?? null, $field === 'created_at' || $field === 'updated_at')) {
                throw new RuntimeException('INVENTORY_COUNT_SYNC_COUNT_INVALID');
            }
        }
        $this->assertText($count['notes'] ?? null, 65535, 'INVENTORY_COUNT_SYNC_COUNT_INVALID');

        $lineIds = [];
        $itemIds = [];
        foreach ($payload['inventory_count_lines'] as $line) {
            if (!is_array($line)) {
                throw new RuntimeException('INVENTORY_COUNT_SYNC_LINE_INVALID');
            }
            $this->assertExactFields($line, self::LINE_FIELDS, 'INVENTORY_COUNT_SYNC_LINE_INVALID');
            $lineId = (int) ($line['id'] ?? 0);
            $itemId = (int) ($line['item_id'] ?? 0);
            if ($lineId < 1
                || isset($lineIds[$lineId])
                || (int) ($line['count_id'] ?? 0) !== $countId
                || $itemId < 1
                || isset($itemIds[$itemId])
                || !$this->isNullablePositiveInt($line['unit_id'] ?? null)
                || !$this->isPositiveDecimal($line['unit_conversion_to_base'] ?? null)
                || !$this->isDecimal($line['snapshot_qty'] ?? null)
                || !$this->isNullableDecimal($line['counted_qty'] ?? null)
                || !$this->isDecimal($line['variance_qty'] ?? null)
                || !$this->isDecimal($line['variance_percent'] ?? null)
                || !$this->isDecimal($line['variance_cost'] ?? null)
                || !$this->isNullablePositiveInt($line['snapshot_last_movement_id'] ?? null)
                || !$this->isBooleanInt($line['stale_count_conflict'] ?? null)
                || !$this->isNullablePositiveInt($line['reason_code_id'] ?? null)
                || !$this->isNullablePositiveInt($line['counted_by'] ?? null)
                || !$this->isNullableDateTime($line['counted_at'] ?? null)
                || !$this->isNullableDateTime($line['created_at'] ?? null, true)
                || !$this->isNullableDateTime($line['updated_at'] ?? null, true)
            ) {
                throw new RuntimeException('INVENTORY_COUNT_SYNC_LINE_INVALID');
            }
            $this->assertText($line['notes'] ?? null, 65535, 'INVENTORY_COUNT_SYNC_LINE_INVALID');
            $lineIds[$lineId] = true;
            $itemIds[$itemId] = true;
        }

        if (array_keys($payload['totals']) !== ['line_count']
            || (int) ($payload['totals']['line_count'] ?? -1) !== count($lineIds)
        ) {
            throw new RuntimeException('INVENTORY_COUNT_SYNC_TOTALS_INVALID');
        }

        if ($event !== []) {
            if ((string) ($event['aggregate_type'] ?? '') !== 'inventory_count'
                || strtolower(trim((string) ($event['aggregate_uuid'] ?? ''))) !== $countUuid
                || (int) ($event['aggregate_local_id'] ?? 0) !== $countId
                || (int) ($event['event_version'] ?? 0) !== $revision
            ) {
                throw new RuntimeException('INVENTORY_COUNT_SYNC_EVENT_IDENTITY_INVALID');
            }
        }
    }

    public static function encodeJson(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (!is_string($json)) {
            throw new RuntimeException('INVENTORY_COUNT_SYNC_JSON_INVALID');
        }

        return $json;
    }

    private function selectFields(array $row, array $fields): array
    {
        $selected = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $row)) {
                throw new RuntimeException('INVENTORY_COUNT_SYNC_SCHEMA_REQUIRED');
            }
            $selected[$field] = $row[$field];
        }

        return $selected;
    }

    private function assertExactFields(array $row, array $fields, string $code): void
    {
        if (array_diff(array_keys($row), $fields) !== [] || array_diff($fields, array_keys($row)) !== []) {
            throw new RuntimeException($code);
        }
    }

    private function isBooleanInt($value): bool
    {
        return $value === 0 || $value === 1 || $value === '0' || $value === '1';
    }

    private function isNullablePositiveInt($value): bool
    {
        return $value === null || $value === '' || (filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value > 0);
    }

    private function isDecimal($value): bool
    {
        return is_int($value) || is_float($value) || (is_string($value) && preg_match('/^-?\d+(?:\.\d+)?$/', $value) === 1);
    }

    private function isNullableDecimal($value): bool
    {
        return $value === null || $value === '' || $this->isDecimal($value);
    }

    private function isPositiveDecimal($value): bool
    {
        return $this->isDecimal($value) && bccomp((string) $value, '0', 8) === 1;
    }

    private function isNullableDateTime($value, bool $required = false): bool
    {
        if ($value === null || $value === '') {
            return !$required;
        }

        return is_string($value)
            && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/', $value) === 1;
    }

    private function assertText($value, int $maxBytes, string $code): void
    {
        if ($value !== null && (!is_string($value) || strlen($value) > $maxBytes)) {
            throw new RuntimeException($code);
        }
    }
}
