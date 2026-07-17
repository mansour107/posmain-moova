<?php

require_once __DIR__ . '/BranchIdentity.php';

class ProductionBatchSyncPayloadService
{
    private const BATCH_FIELDS = [
        'id', 'batch_uuid', 'pos_tenant', 'pos_branch', 'branch_uuid', 'store_id',
        'recipe_id', 'output_item_id', 'planned_output_qty', 'actual_output_qty',
        'status', 'started_at', 'committed_at', 'created_by', 'committed_by',
        'variance_reason', 'notes', 'created_at', 'updated_at', 'sync_revision',
    ];
    private const LINE_FIELDS = [
        'id', 'batch_id', 'line_type', 'item_id', 'planned_qty', 'actual_qty',
        'unit_id', 'unit_cost', 'total_cost', 'inventory_movement_id', 'created_at',
    ];
    private const STATUSES = ['draft', 'committed', 'cancelled'];
    private const LINE_TYPES = ['input', 'output', 'variance'];

    public function build(mysqli $conn, string $branchUuid, int $batchId): array
    {
        $branchUuid = strtolower(trim($branchUuid));
        if (!SyncBranchIdentity::isUuid($branchUuid) || $batchId < 1) {
            throw new InvalidArgumentException('PRODUCTION_BATCH_SYNC_IDENTITY_INVALID');
        }

        $stmt = $conn->prepare('SELECT * FROM production_batches WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $batchId);
        $stmt->execute();
        $batch = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$batch) {
            throw new RuntimeException('PRODUCTION_BATCH_SYNC_BATCH_MISSING');
        }
        $batch = $this->selectFields($batch, self::BATCH_FIELDS);

        $stmt = $conn->prepare('SELECT * FROM production_batch_lines WHERE batch_id = ? ORDER BY id ASC');
        $stmt->bind_param('i', $batchId);
        $stmt->execute();
        $result = $stmt->get_result();
        $lines = [];
        while ($line = $result->fetch_assoc()) {
            $lines[] = $this->selectFields($line, self::LINE_FIELDS);
        }
        $stmt->close();

        $payload = [
            'schema_version' => 1,
            'snapshot_type' => 'production_batch_bundle',
            'domain' => 'production_batch',
            'branch_uuid' => $branchUuid,
            'sync_revision' => (int) ($batch['sync_revision'] ?? 0),
            'production_batch' => $batch,
            'production_batch_lines' => $lines,
            'totals' => ['line_count' => count($lines)],
        ];
        $payload['payload_hash'] = hash('sha256', self::encodeJson($payload));
        $this->assertValid($payload, $branchUuid);
        $this->assertMovementOwnership($conn, $batchId, $lines);

        return $payload;
    }

    public function assertValid(array $payload, string $branchUuid, array $event = []): void
    {
        $branchUuid = strtolower(trim($branchUuid));
        $allowedTop = [
            'schema_version', 'snapshot_type', 'domain', 'branch_uuid', 'sync_revision',
            'production_batch', 'production_batch_lines', 'totals', 'payload_hash',
        ];
        if (array_diff(array_keys($payload), $allowedTop) !== []
            || array_diff($allowedTop, array_keys($payload)) !== []
            || (int) ($payload['schema_version'] ?? 0) !== 1
            || (string) ($payload['snapshot_type'] ?? '') !== 'production_batch_bundle'
            || (string) ($payload['domain'] ?? '') !== 'production_batch'
            || !SyncBranchIdentity::isUuid($branchUuid)
            || strtolower(trim((string) ($payload['branch_uuid'] ?? ''))) !== $branchUuid
            || !is_array($payload['production_batch'] ?? null)
            || !is_array($payload['production_batch_lines'] ?? null)
            || !is_array($payload['totals'] ?? null)
        ) {
            throw new RuntimeException('PRODUCTION_BATCH_SYNC_PAYLOAD_INVALID');
        }

        $hashPayload = $payload;
        $expectedHash = trim((string) ($hashPayload['payload_hash'] ?? ''));
        unset($hashPayload['payload_hash']);
        $actualHash = hash('sha256', self::encodeJson($hashPayload));
        if ($expectedHash === '' || !hash_equals($expectedHash, $actualHash)) {
            throw new RuntimeException('PRODUCTION_BATCH_SYNC_HASH_INVALID');
        }

        $batch = $payload['production_batch'];
        $this->assertExactFields($batch, self::BATCH_FIELDS, 'PRODUCTION_BATCH_SYNC_BATCH_INVALID');
        $batchId = (int) ($batch['id'] ?? 0);
        $revision = (int) ($payload['sync_revision'] ?? 0);
        $batchUuid = strtolower(trim((string) ($batch['batch_uuid'] ?? '')));
        $status = (string) ($batch['status'] ?? '');
        if ($batchId < 1
            || $revision < 1
            || (int) ($batch['sync_revision'] ?? 0) !== $revision
            || !SyncBranchIdentity::isUuid($batchUuid)
            || strtolower(trim((string) ($batch['branch_uuid'] ?? ''))) !== $branchUuid
            || (int) ($batch['pos_tenant'] ?? -1) < 0
            || (int) ($batch['pos_branch'] ?? -1) < 0
            || (int) ($batch['store_id'] ?? -1) < 0
            || (int) ($batch['recipe_id'] ?? 0) < 1
            || (int) ($batch['output_item_id'] ?? 0) < 1
            || !$this->isPositiveDecimal($batch['planned_output_qty'] ?? null)
            || !$this->isNullablePositiveDecimal($batch['actual_output_qty'] ?? null)
            || !in_array($status, self::STATUSES, true)
        ) {
            throw new RuntimeException('PRODUCTION_BATCH_SYNC_BATCH_INVALID');
        }
        foreach (['created_by', 'committed_by'] as $field) {
            if (!$this->isNullablePositiveInt($batch[$field] ?? null)) {
                throw new RuntimeException('PRODUCTION_BATCH_SYNC_BATCH_INVALID');
            }
        }
        foreach (['started_at', 'committed_at', 'created_at', 'updated_at'] as $field) {
            if (!$this->isNullableDateTime($batch[$field] ?? null, $field === 'created_at' || $field === 'updated_at')) {
                throw new RuntimeException('PRODUCTION_BATCH_SYNC_BATCH_INVALID');
            }
        }
        if ($status === 'committed'
            && ($batch['actual_output_qty'] === null
                || $batch['actual_output_qty'] === ''
                || !$this->isNullableDateTime($batch['committed_at'] ?? null))
        ) {
            throw new RuntimeException('PRODUCTION_BATCH_SYNC_BATCH_INVALID');
        }
        if ($status !== 'committed'
            && ($batch['actual_output_qty'] !== null
                || $batch['committed_at'] !== null
                || $batch['committed_by'] !== null)
        ) {
            throw new RuntimeException('PRODUCTION_BATCH_SYNC_BATCH_INVALID');
        }
        $this->assertText($batch['variance_reason'] ?? null, 255, 'PRODUCTION_BATCH_SYNC_BATCH_INVALID');
        $this->assertText($batch['notes'] ?? null, 65535, 'PRODUCTION_BATCH_SYNC_BATCH_INVALID');

        $lineIds = [];
        $lineTypes = [];
        foreach ($payload['production_batch_lines'] as $line) {
            if (!is_array($line)) {
                throw new RuntimeException('PRODUCTION_BATCH_SYNC_LINE_INVALID');
            }
            $this->assertExactFields($line, self::LINE_FIELDS, 'PRODUCTION_BATCH_SYNC_LINE_INVALID');
            $lineId = (int) ($line['id'] ?? 0);
            $lineType = (string) ($line['line_type'] ?? '');
            if ($lineId < 1
                || isset($lineIds[$lineId])
                || (int) ($line['batch_id'] ?? 0) !== $batchId
                || !in_array($lineType, self::LINE_TYPES, true)
                || (int) ($line['item_id'] ?? 0) < 1
                || !$this->isNonNegativeDecimal($line['planned_qty'] ?? null)
                || !$this->isNonNegativeDecimal($line['actual_qty'] ?? null)
                || !$this->isNullablePositiveInt($line['unit_id'] ?? null)
                || !$this->isNonNegativeDecimal($line['unit_cost'] ?? null)
                || !$this->isNonNegativeDecimal($line['total_cost'] ?? null)
                || !$this->isNullablePositiveInt($line['inventory_movement_id'] ?? null)
                || !$this->isNullableDateTime($line['created_at'] ?? null, true)
            ) {
                throw new RuntimeException('PRODUCTION_BATCH_SYNC_LINE_INVALID');
            }
            if (in_array($lineType, ['input', 'output'], true)
                && (!$this->isPositiveDecimal($line['actual_qty'] ?? null)
                    || !$this->isNullablePositiveInt($line['inventory_movement_id'] ?? null))
            ) {
                throw new RuntimeException('PRODUCTION_BATCH_SYNC_LINE_INVALID');
            }
            if ($lineType === 'variance' && $line['inventory_movement_id'] !== null) {
                throw new RuntimeException('PRODUCTION_BATCH_SYNC_LINE_INVALID');
            }
            $lineIds[$lineId] = true;
            $lineTypes[$lineType] = ($lineTypes[$lineType] ?? 0) + 1;
        }

        if (array_keys($payload['totals']) !== ['line_count']
            || (int) ($payload['totals']['line_count'] ?? -1) !== count($lineIds)
        ) {
            throw new RuntimeException('PRODUCTION_BATCH_SYNC_TOTALS_INVALID');
        }
        if ($status === 'committed') {
            if (empty($lineTypes['input']) || empty($lineTypes['output'])) {
                throw new RuntimeException('PRODUCTION_BATCH_SYNC_LINES_INCOMPLETE');
            }
        } elseif ($lineIds !== []) {
            throw new RuntimeException('PRODUCTION_BATCH_SYNC_LINES_INVALID_FOR_STATUS');
        }

        if ($event !== []) {
            if ((string) ($event['aggregate_type'] ?? '') !== 'production_batch'
                || strtolower(trim((string) ($event['aggregate_uuid'] ?? ''))) !== $batchUuid
                || (int) ($event['aggregate_local_id'] ?? 0) !== $batchId
                || (int) ($event['event_version'] ?? 0) !== $revision
            ) {
                throw new RuntimeException('PRODUCTION_BATCH_SYNC_EVENT_IDENTITY_INVALID');
            }
        }
    }

    public function assertMovementOwnership(mysqli $conn, int $batchId, array $lines): void
    {
        foreach ($lines as $line) {
            $movementId = (int) ($line['inventory_movement_id'] ?? 0);
            if ($movementId < 1) {
                continue;
            }
            $stmt = $conn->prepare('SELECT production_batch_id, source_type, source_id FROM inventory_movements WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $movementId);
            $stmt->execute();
            $movement = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$movement
                || (int) ($movement['production_batch_id'] ?? 0) !== $batchId
                || (string) ($movement['source_type'] ?? '') !== 'production_batch'
                || (int) ($movement['source_id'] ?? 0) !== $batchId
            ) {
                throw new RuntimeException('PRODUCTION_BATCH_SYNC_MOVEMENT_SCOPE_INVALID');
            }
        }
    }

    public static function encodeJson(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (!is_string($json)) {
            throw new RuntimeException('PRODUCTION_BATCH_SYNC_JSON_INVALID');
        }

        return $json;
    }

    private function selectFields(array $row, array $fields): array
    {
        $selected = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $row)) {
                throw new RuntimeException('PRODUCTION_BATCH_SYNC_SCHEMA_REQUIRED');
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

    private function isNullablePositiveInt($value): bool
    {
        return $value === null || $value === '' || (filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value > 0);
    }

    private function isDecimal($value): bool
    {
        return is_int($value) || is_float($value) || (is_string($value) && preg_match('/^-?\d+(?:\.\d+)?$/', $value) === 1);
    }

    private function isNonNegativeDecimal($value): bool
    {
        return $this->isDecimal($value) && bccomp((string) $value, '0', 8) >= 0;
    }

    private function isPositiveDecimal($value): bool
    {
        return $this->isDecimal($value) && bccomp((string) $value, '0', 8) === 1;
    }

    private function isNullablePositiveDecimal($value): bool
    {
        return $value === null || $value === '' || $this->isPositiveDecimal($value);
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
