<?php

require_once __DIR__ . '/RecipeRepositoryBase.php';
require_once dirname(__DIR__) . '/RecipeDecimal.php';

class RecipeOrderLineUsageRepository extends RecipeRepositoryBase
{
    private const SOURCE_CHANNELS = ['pos', 'table', 'moova', 'cofe', 'api', 'sync'];
    private const STATUSES = ['previewed', 'reserved', 'consumed', 'released', 'voided', 'refunded', 'wasted'];

    public function createUsage(mysqli $conn, array $data): int
    {
        $defaults = [
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'branch_uuid' => null,
            'store_id' => 0,
            'fat_detail_id' => null,
            'order_line_uuid' => null,
            'source_channel' => 'pos',
            'source_order_uuid' => null,
            'source_line_uuid' => null,
            'source_event_uuid' => null,
            'variant_id' => null,
            'modifiers_hash' => null,
            'modifiers_json' => null,
            'preparation_hash' => null,
            'preparation_json' => null,
            'order_unit_id' => null,
            'recipe_id' => null,
            'recipe_version_number' => null,
            'recipe_cost_snapshot_id' => null,
            'explosion_json' => null,
            'cost_total' => '0.000000',
            'status' => 'previewed',
            'consumed_at' => null,
            'released_at' => null,
            'voided_at' => null,
            'refunded_at' => null,
        ];

        $row = array_merge($defaults, $data);
        $row['source_channel'] = trim((string) ($row['source_channel'] ?? ''));
        $row['status'] = trim((string) ($row['status'] ?? ''));
        $this->assertValidUsage($row);
        $row['order_qty'] = RecipeDecimal::normalize($row['order_qty']);
        $row['cost_total'] = RecipeDecimal::normalize($row['cost_total']);

        return $this->insertRow($conn, 'recipe_order_line_usage', $row);
    }

    public function findByIdempotencyKey(
        mysqli $conn,
        int $posTenant,
        int $posBranch,
        int $storeId,
        string $idempotencyKey
    ): ?array {
        return $this->fetchOne(
            $conn,
            "
SELECT *
FROM recipe_order_line_usage
WHERE pos_tenant = ?
  AND pos_branch = ?
  AND store_id = ?
  AND idempotency_key = ?
LIMIT 1",
            [$posTenant, $posBranch, $storeId, $idempotencyKey]
        );
    }

    public function updateUsage(mysqli $conn, int $usageId, array $data): int
    {
        if ($usageId < 1) {
            throw new InvalidArgumentException('Recipe usage id must be positive.');
        }

        $allowed = [
            'recipe_id',
            'recipe_version_number',
            'recipe_cost_snapshot_id',
            'explosion_json',
            'cost_total',
            'status',
            'consumed_at',
            'released_at',
            'voided_at',
            'refunded_at',
        ];
        $data = $this->normalizeUpdateData($data);

        $updates = [];
        $params = [];
        foreach ($allowed as $column) {
            if (!array_key_exists($column, $data)) {
                continue;
            }

            $updates[] = $this->quoteIdentifier($column) . ' = ?';
            $params[] = $data[$column];
        }

        if (!$updates) {
            return 0;
        }

        $params[] = $usageId;
        return $this->executeStatement(
            $conn,
            'UPDATE recipe_order_line_usage SET ' . implode(', ', $updates) . ' WHERE id = ?',
            $params
        );
    }

    public function markReservedAsPreviewed(mysqli $conn, int $usageId): int
    {
        if ($usageId < 1) {
            throw new InvalidArgumentException('Recipe usage id must be positive.');
        }

        return $this->executeStatement(
            $conn,
            "UPDATE recipe_order_line_usage SET status = 'previewed' WHERE id = ? AND status = 'reserved'",
            [$usageId]
        );
    }

    public function markPreviewedReleased(mysqli $conn, int $usageId, ?string $releasedAt = null): int
    {
        if ($usageId < 1) {
            throw new InvalidArgumentException('Recipe usage id must be positive.');
        }

        return $this->executeStatement(
            $conn,
            "UPDATE recipe_order_line_usage SET status = 'released', released_at = ? WHERE id = ? AND status = 'previewed'",
            [$releasedAt ?: date('Y-m-d H:i:s'), $usageId]
        );
    }

    public function findForOrderLine(mysqli $conn, int $orderId, ?int $fatDetailId, ?string $orderLineUuid): array
    {
        return $this->fetchAll(
            $conn,
            "
SELECT *
FROM recipe_order_line_usage
WHERE order_id = ?
  AND (? IS NULL OR fat_detail_id = ?)
  AND (? IS NULL OR order_line_uuid = ?)
ORDER BY id",
            [$orderId, $fatDetailId, $fatDetailId, $orderLineUuid, $orderLineUuid]
        );
    }

    public function findForExternalSourceLine(
        mysqli $conn,
        int $posTenant,
        int $posBranch,
        int $orderId,
        ?string $sourceOrderUuid,
        string $sourceLineUuid
    ): array {
        $sourceLineUuid = trim($sourceLineUuid);
        if ($orderId < 1 || $sourceLineUuid === '') {
            return [];
        }

        return $this->fetchAll(
            $conn,
            "
SELECT *
FROM recipe_order_line_usage
WHERE pos_tenant = ?
  AND pos_branch = ?
  AND order_id = ?
  AND source_line_uuid = ?
  AND (? IS NULL OR source_order_uuid = ?)
ORDER BY id
FOR UPDATE",
            [$posTenant, $posBranch, $orderId, $sourceLineUuid, $sourceOrderUuid, $sourceOrderUuid]
        );
    }

    public function findForOrder(mysqli $conn, int $orderId): array
    {
        return $this->fetchAll(
            $conn,
            "
SELECT *
FROM recipe_order_line_usage
WHERE order_id = ?
ORDER BY id",
            [$orderId]
        );
    }

    public function findPendingForOrder(mysqli $conn, int $orderId): array
    {
        return $this->fetchAll(
            $conn,
            "
SELECT *
FROM recipe_order_line_usage
WHERE order_id = ?
  AND status IN ('previewed', 'reserved')
ORDER BY id",
            [$orderId]
        );
    }

    private function assertValidUsage(array $row): void
    {
        if (trim((string) ($row['usage_uuid'] ?? '')) === '') {
            throw new InvalidArgumentException('Recipe usage UUID is required.');
        }
        if (trim((string) ($row['idempotency_key'] ?? '')) === '') {
            throw new InvalidArgumentException('Recipe usage idempotency key is required.');
        }
        if (!in_array((string) ($row['source_channel'] ?? ''), self::SOURCE_CHANNELS, true)) {
            throw new InvalidArgumentException('Recipe usage source_channel is invalid.');
        }
        $this->assertValidStatus((string) ($row['status'] ?? ''));

        foreach (['pos_tenant', 'pos_branch', 'store_id'] as $field) {
            if ((int) ($row[$field] ?? 0) < 0) {
                throw new InvalidArgumentException('Recipe usage ' . $field . ' cannot be negative.');
            }
        }

        foreach (['order_id', 'sellable_item_id'] as $field) {
            if ((int) ($row[$field] ?? 0) < 1) {
                throw new InvalidArgumentException('Recipe usage ' . $field . ' must be positive.');
            }
        }

        foreach (['fat_detail_id', 'variant_id', 'order_unit_id', 'recipe_id', 'recipe_version_number', 'recipe_cost_snapshot_id'] as $field) {
            $this->assertOptionalPositiveInt($row, $field);
        }

        $this->assertDecimal($row['order_qty'] ?? null, 'order_qty');
        if (RecipeDecimal::compare($row['order_qty'], '0') <= 0) {
            throw new InvalidArgumentException('Recipe usage order_qty must be positive.');
        }

        $this->assertDecimal($row['cost_total'] ?? null, 'cost_total');
        if (RecipeDecimal::compare($row['cost_total'], '0') < 0) {
            throw new InvalidArgumentException('Recipe usage cost_total cannot be negative.');
        }
    }

    private function normalizeUpdateData(array $data): array
    {
        if (array_key_exists('status', $data)) {
            $data['status'] = trim((string) $data['status']);
            $this->assertValidStatus($data['status']);
        }

        foreach (['recipe_id', 'recipe_version_number', 'recipe_cost_snapshot_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $this->assertOptionalPositiveInt($data, $field);
            }
        }

        if (array_key_exists('cost_total', $data)) {
            $this->assertDecimal($data['cost_total'], 'cost_total');
            if (RecipeDecimal::compare($data['cost_total'], '0') < 0) {
                throw new InvalidArgumentException('Recipe usage cost_total cannot be negative.');
            }
            $data['cost_total'] = RecipeDecimal::normalize($data['cost_total']);
        }

        return $data;
    }

    private function assertValidStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Recipe usage status is invalid.');
        }
    }

    private function assertOptionalPositiveInt(array $row, string $field): void
    {
        if (!array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '') {
            return;
        }
        if ((int) $row[$field] < 1) {
            throw new InvalidArgumentException('Recipe usage ' . $field . ' must be positive when provided.');
        }
    }

    private function assertDecimal($value, string $field): void
    {
        $text = trim((string) $value);
        if ($text === '' || !preg_match('/^-?\d+(\.\d+)?$/', $text)) {
            throw new InvalidArgumentException('Recipe usage ' . $field . ' must be a decimal value.');
        }
    }
}
