<?php

require_once __DIR__ . '/BranchIdentity.php';

class PurchaseReceiptSyncPayloadService
{
    private const RECEIPT_FIELDS = [
        'id', 'purchase_receipt_uuid', 'purchase_order_id', 'pos_tenant', 'pos_branch',
        'branch_uuid', 'supplier_account_id', 'destination_store_id', 'legacy_ot_head_id',
        'supplier_invoice_no', 'status', 'received_at', 'posted_at', 'created_by',
        'posted_by', 'created_at', 'updated_at', 'notes',
    ];
    private const LINE_FIELDS = [
        'id', 'purchase_receipt_id', 'purchase_order_line_id', 'item_id', 'unit_id',
        'received_qty', 'returned_qty', 'unit_cost', 'total_cost',
        'inventory_movement_id', 'reason_code_id', 'notes', 'created_at', 'updated_at',
    ];
    private const STATUSES = ['posted', 'returned'];

    public function build(mysqli $conn, string $branchUuid, int $receiptId): array
    {
        $branchUuid = strtolower(trim($branchUuid));
        if (!SyncBranchIdentity::isUuid($branchUuid) || $receiptId < 1) {
            throw new InvalidArgumentException('PURCHASE_RECEIPT_SYNC_IDENTITY_INVALID');
        }

        $stmt = $conn->prepare('SELECT * FROM inventory_purchase_receipts WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $receiptId);
        $stmt->execute();
        $receipt = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$receipt) {
            throw new RuntimeException('PURCHASE_RECEIPT_SYNC_RECEIPT_MISSING');
        }
        $receipt = $this->selectFields($receipt, self::RECEIPT_FIELDS);

        $stmt = $conn->prepare('SELECT * FROM inventory_purchase_receipt_lines WHERE purchase_receipt_id = ? ORDER BY id ASC');
        $stmt->bind_param('i', $receiptId);
        $stmt->execute();
        $result = $stmt->get_result();
        $lines = [];
        while ($line = $result->fetch_assoc()) {
            $lines[] = $this->selectFields($line, self::LINE_FIELDS);
        }
        $stmt->close();

        $payload = [
            'schema_version' => 1,
            'snapshot_type' => 'purchase_receipt_bundle',
            'domain' => 'purchase_receipt',
            'branch_uuid' => $branchUuid,
            'sync_revision' => 1,
            'purchase_receipt' => $receipt,
            'purchase_receipt_lines' => $lines,
            'totals' => ['line_count' => count($lines)],
        ];
        $payload['payload_hash'] = hash('sha256', self::encodeJson($payload));
        $this->assertValid($payload, $branchUuid);
        $this->assertMovementOwnership($conn, $receiptId, $receipt, $lines);

        return $payload;
    }

    public function assertValid(array $payload, string $branchUuid, array $event = []): void
    {
        $branchUuid = strtolower(trim($branchUuid));
        $topFields = [
            'schema_version', 'snapshot_type', 'domain', 'branch_uuid', 'sync_revision',
            'purchase_receipt', 'purchase_receipt_lines', 'totals', 'payload_hash',
        ];
        if (!$this->hasExactFields($payload, $topFields)
            || (int) ($payload['schema_version'] ?? 0) !== 1
            || (string) ($payload['snapshot_type'] ?? '') !== 'purchase_receipt_bundle'
            || (string) ($payload['domain'] ?? '') !== 'purchase_receipt'
            || (int) ($payload['sync_revision'] ?? 0) !== 1
            || !SyncBranchIdentity::isUuid($branchUuid)
            || strtolower(trim((string) ($payload['branch_uuid'] ?? ''))) !== $branchUuid
            || !is_array($payload['purchase_receipt'] ?? null)
            || !is_array($payload['purchase_receipt_lines'] ?? null)
            || !is_array($payload['totals'] ?? null)
        ) {
            throw new RuntimeException('PURCHASE_RECEIPT_SYNC_PAYLOAD_INVALID');
        }

        $hashPayload = $payload;
        $expectedHash = trim((string) $hashPayload['payload_hash']);
        unset($hashPayload['payload_hash']);
        $actualHash = hash('sha256', self::encodeJson($hashPayload));
        if ($expectedHash === '' || !hash_equals($expectedHash, $actualHash)) {
            throw new RuntimeException('PURCHASE_RECEIPT_SYNC_HASH_INVALID');
        }

        $receipt = $payload['purchase_receipt'];
        $this->assertExactFields($receipt, self::RECEIPT_FIELDS, 'PURCHASE_RECEIPT_SYNC_RECEIPT_INVALID');
        $receiptId = (int) ($receipt['id'] ?? 0);
        $receiptUuid = strtolower(trim((string) ($receipt['purchase_receipt_uuid'] ?? '')));
        $status = (string) ($receipt['status'] ?? '');
        if ($receiptId < 1
            || !SyncBranchIdentity::isUuid($receiptUuid)
            || strtolower(trim((string) ($receipt['branch_uuid'] ?? ''))) !== $branchUuid
            || (int) ($receipt['pos_tenant'] ?? -1) < 0
            || (int) ($receipt['pos_branch'] ?? -1) < 0
            || (int) ($receipt['destination_store_id'] ?? 0) < 1
            || !in_array($status, self::STATUSES, true)
        ) {
            throw new RuntimeException('PURCHASE_RECEIPT_SYNC_RECEIPT_INVALID');
        }
        foreach (['purchase_order_id', 'supplier_account_id', 'legacy_ot_head_id', 'created_by', 'posted_by'] as $field) {
            if (!$this->isNullablePositiveInt($receipt[$field] ?? null)) {
                throw new RuntimeException('PURCHASE_RECEIPT_SYNC_RECEIPT_INVALID');
            }
        }
        foreach (['received_at', 'posted_at', 'created_at', 'updated_at'] as $field) {
            if (!$this->isDateTime($receipt[$field] ?? null)) {
                throw new RuntimeException('PURCHASE_RECEIPT_SYNC_RECEIPT_INVALID');
            }
        }
        $this->assertText($receipt['supplier_invoice_no'] ?? null, 128, 'PURCHASE_RECEIPT_SYNC_RECEIPT_INVALID');
        $this->assertText($receipt['notes'] ?? null, 65535, 'PURCHASE_RECEIPT_SYNC_RECEIPT_INVALID');

        $lineIds = [];
        foreach ($payload['purchase_receipt_lines'] as $line) {
            if (!is_array($line)) {
                throw new RuntimeException('PURCHASE_RECEIPT_SYNC_LINE_INVALID');
            }
            $this->assertExactFields($line, self::LINE_FIELDS, 'PURCHASE_RECEIPT_SYNC_LINE_INVALID');
            $lineId = (int) ($line['id'] ?? 0);
            $receivedQty = $line['received_qty'] ?? null;
            $returnedQty = $line['returned_qty'] ?? null;
            if ($lineId < 1
                || isset($lineIds[$lineId])
                || (int) ($line['purchase_receipt_id'] ?? 0) !== $receiptId
                || !$this->isNullablePositiveInt($line['purchase_order_line_id'] ?? null)
                || (int) ($line['item_id'] ?? 0) < 1
                || !$this->isNullablePositiveInt($line['unit_id'] ?? null)
                || !$this->isNonNegativeDecimal($receivedQty)
                || !$this->isNonNegativeDecimal($returnedQty)
                || !$this->isNonNegativeDecimal($line['unit_cost'] ?? null)
                || !$this->isNonNegativeDecimal($line['total_cost'] ?? null)
                || !$this->isNullablePositiveInt($line['inventory_movement_id'] ?? null)
                || !$this->isNullablePositiveInt($line['reason_code_id'] ?? null)
                || !$this->isDateTime($line['created_at'] ?? null)
                || !$this->isDateTime($line['updated_at'] ?? null)
            ) {
                throw new RuntimeException('PURCHASE_RECEIPT_SYNC_LINE_INVALID');
            }
            if (($status === 'posted' && (!$this->isPositiveDecimal($receivedQty) || bccomp((string) $returnedQty, '0', 8) !== 0))
                || ($status === 'returned' && (!$this->isPositiveDecimal($returnedQty) || bccomp((string) $receivedQty, '0', 8) !== 0))
                || !$this->isNullablePositiveInt($line['inventory_movement_id'] ?? null)
            ) {
                throw new RuntimeException('PURCHASE_RECEIPT_SYNC_LINE_INVALID');
            }
            $this->assertText($line['notes'] ?? null, 65535, 'PURCHASE_RECEIPT_SYNC_LINE_INVALID');
            $lineIds[$lineId] = true;
        }
        if ($lineIds === []
            || array_keys($payload['totals']) !== ['line_count']
            || (int) ($payload['totals']['line_count'] ?? -1) !== count($lineIds)
        ) {
            throw new RuntimeException('PURCHASE_RECEIPT_SYNC_TOTALS_INVALID');
        }

        if ($event !== []
            && ((string) ($event['aggregate_type'] ?? '') !== 'purchase_receipt'
                || strtolower(trim((string) ($event['aggregate_uuid'] ?? ''))) !== $receiptUuid
                || (int) ($event['aggregate_local_id'] ?? 0) !== $receiptId
                || (int) ($event['event_version'] ?? 0) !== 1)
        ) {
            throw new RuntimeException('PURCHASE_RECEIPT_SYNC_EVENT_IDENTITY_INVALID');
        }
    }

    public function assertMovementOwnership(mysqli $conn, int $receiptId, array $receipt, array $lines): void
    {
        $status = (string) $receipt['status'];
        $receiptUuid = strtolower((string) $receipt['purchase_receipt_uuid']);
        $movementType = $status === 'posted' ? 'purchase' : 'purchase_return';
        $sourcePrefix = $status === 'posted' ? 'purchase-receipt:' : 'purchase-return:';
        foreach ($lines as $line) {
            $lineId = (int) $line['id'];
            $movementId = (int) $line['inventory_movement_id'];
            $stmt = $conn->prepare('SELECT id, pos_tenant, pos_branch, branch_uuid, store_id, movement_type, source_type, source_id, source_uuid, metadata_json FROM inventory_movements WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $movementId);
            $stmt->execute();
            $movement = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $metadata = $movement ? json_decode((string) ($movement['metadata_json'] ?? ''), true) : null;
            if (!$movement
                || (string) ($movement['movement_type'] ?? '') !== $movementType
                || (string) ($movement['source_type'] ?? '') !== 'purchase_receipt'
                || (int) ($movement['source_id'] ?? 0) !== $lineId
                || (string) ($movement['source_uuid'] ?? '') !== $sourcePrefix . $receiptUuid . ':line:' . $lineId
                || (int) ($movement['pos_tenant'] ?? -1) !== (int) $receipt['pos_tenant']
                || (int) ($movement['pos_branch'] ?? -1) !== (int) $receipt['pos_branch']
                || strtolower(trim((string) ($movement['branch_uuid'] ?? ''))) !== strtolower((string) $receipt['branch_uuid'])
                || (int) ($movement['store_id'] ?? 0) !== (int) $receipt['destination_store_id']
                || !is_array($metadata)
                || (int) ($metadata['purchase_receipt_id'] ?? 0) !== $receiptId
            ) {
                throw new RuntimeException('PURCHASE_RECEIPT_SYNC_MOVEMENT_SCOPE_INVALID');
            }
        }
    }

    public static function encodeJson(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (!is_string($json)) {
            throw new RuntimeException('PURCHASE_RECEIPT_SYNC_JSON_INVALID');
        }
        return $json;
    }

    private function selectFields(array $row, array $fields): array
    {
        $selected = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $row)) {
                throw new RuntimeException('PURCHASE_RECEIPT_SYNC_SCHEMA_REQUIRED');
            }
            $selected[$field] = $row[$field];
        }
        return $selected;
    }

    private function hasExactFields(array $row, array $fields): bool
    {
        return array_diff(array_keys($row), $fields) === [] && array_diff($fields, array_keys($row)) === [];
    }

    private function assertExactFields(array $row, array $fields, string $code): void
    {
        if (!$this->hasExactFields($row, $fields)) {
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

    private function isDateTime($value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/', $value) === 1;
    }

    private function assertText($value, int $maxBytes, string $code): void
    {
        if ($value !== null && (!is_string($value) || strlen($value) > $maxBytes)) {
            throw new RuntimeException($code);
        }
    }
}
