<?php

require_once __DIR__ . '/RecipeRepositoryBase.php';
require_once dirname(__DIR__) . '/RecipeDecimal.php';

class InventoryMovementRepository extends RecipeRepositoryBase
{
    private const MOVEMENT_TYPES = [
        'purchase',
        'purchase_return',
        'sale_direct',
        'recipe_consumption',
        'production_input',
        'production_output',
        'waste',
        'adjustment',
        'transfer_in',
        'transfer_out',
        'reservation',
        'reservation_release',
        'refund_reversal',
        'sync_replay',
        'opening_balance',
    ];

    private const SOURCE_TYPES = [
        'order',
        'order_line',
        'invoice',
        'fat_details',
        'recipe',
        'recipe_order_line_usage',
        'production_batch',
        'purchase_invoice',
        'purchase_order',
        'purchase_receipt',
        'inventory_count',
        'inventory_transfer',
        'adjustment',
        'reservation',
        'sync_event',
        'manual',
    ];

    public function createMovement(mysqli $conn, array $data): int
    {
        $defaults = [
            'movement_group_uuid' => null,
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'branch_uuid' => null,
            'store_id' => 0,
            'source_id' => null,
            'source_uuid' => null,
            'order_id' => null,
            'fat_detail_id' => null,
            'order_line_uuid' => null,
            'recipe_order_line_usage_id' => null,
            'recipe_id' => null,
            'recipe_cost_snapshot_id' => null,
            'production_batch_id' => null,
            'qty_in' => '0.000000',
            'qty_out' => '0.000000',
            'unit_id' => null,
            'unit_conversion_to_base' => '1.00000000',
            'unit_cost' => '0.000000',
            'total_cost' => '0.000000',
            'accounting_journal_id' => null,
            'reversed_movement_id' => null,
            'created_by' => null,
        ];

        $row = array_merge($defaults, $data);
        $row['movement_type'] = trim((string) ($row['movement_type'] ?? ''));
        $row['source_type'] = trim((string) ($row['source_type'] ?? ''));
        $this->assertValidMovement($row);

        $row['qty_in'] = RecipeDecimal::normalize($row['qty_in']);
        $row['qty_out'] = RecipeDecimal::normalize($row['qty_out']);
        $row['unit_conversion_to_base'] = RecipeDecimal::normalize($row['unit_conversion_to_base'], 8);
        $row['unit_cost'] = RecipeDecimal::normalize($row['unit_cost']);
        $row['total_cost'] = RecipeDecimal::normalize($row['total_cost']);

        return $this->insertRow($conn, 'inventory_movements', $row);
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
FROM inventory_movements
WHERE pos_tenant = ?
  AND pos_branch = ?
  AND store_id = ?
  AND idempotency_key = ?
LIMIT 1",
            [$posTenant, $posBranch, $storeId, $idempotencyKey]
        );
    }

    public function findByRecipeUsageAndType(mysqli $conn, int $usageId, string $movementType): array
    {
        return $this->fetchAll(
            $conn,
            "
SELECT *
FROM inventory_movements
WHERE recipe_order_line_usage_id = ?
  AND movement_type = ?
ORDER BY id",
            [$usageId, $movementType]
        );
    }

    public function findByIds(mysqli $conn, array $movementIds): array
    {
        $movementIds = array_values(array_unique(array_map('intval', $movementIds)));
        $movementIds = array_values(array_filter($movementIds, static fn($id) => $id > 0));
        if (!$movementIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($movementIds), '?'));

        return $this->fetchAll(
            $conn,
            "
SELECT *
FROM inventory_movements
WHERE id IN ({$placeholders})
ORDER BY id",
            $movementIds
        );
    }

    public function attachJournal(mysqli $conn, array $movementIds, int $journalHeadId): int
    {
        $movementIds = array_values(array_unique(array_map('intval', $movementIds)));
        $movementIds = array_values(array_filter($movementIds, static fn($id) => $id > 0));
        if (!$movementIds || $journalHeadId < 1) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($movementIds), '?'));
        $params = array_merge([$journalHeadId], $movementIds);

        return $this->executeStatement(
            $conn,
            "
UPDATE inventory_movements
SET accounting_journal_id = ?
WHERE id IN ({$placeholders})
  AND (accounting_journal_id IS NULL OR accounting_journal_id = 0)",
            $params
        );
    }

    private function assertValidMovement(array $row): void
    {
        if (!in_array((string) ($row['movement_type'] ?? ''), self::MOVEMENT_TYPES, true)) {
            throw new InvalidArgumentException('Inventory movement movement_type is invalid.');
        }

        if (!in_array((string) ($row['source_type'] ?? ''), self::SOURCE_TYPES, true)) {
            throw new InvalidArgumentException('Inventory movement source_type is invalid.');
        }

        if (trim((string) ($row['idempotency_key'] ?? '')) === '') {
            throw new InvalidArgumentException('Inventory movement idempotency key is required.');
        }

        foreach (['qty_in', 'qty_out', 'unit_cost', 'total_cost'] as $field) {
            $this->assertDecimal($row[$field] ?? null, $field);
            if (RecipeDecimal::compare($row[$field], '0') < 0) {
                throw new InvalidArgumentException('Inventory movement ' . $field . ' cannot be negative.');
            }
        }

        $this->assertDecimal($row['unit_conversion_to_base'] ?? null, 'unit_conversion_to_base');
        if (RecipeDecimal::compare($row['unit_conversion_to_base'], '0', 8) <= 0) {
            throw new InvalidArgumentException('Inventory movement unit conversion must be positive.');
        }

        if (RecipeDecimal::isPositive($row['qty_in']) && RecipeDecimal::isPositive($row['qty_out'])) {
            throw new InvalidArgumentException('Inventory movement cannot have both qty_in and qty_out positive.');
        }
    }

    private function assertDecimal($value, string $field): void
    {
        $text = trim((string) $value);
        if ($text === '' || !preg_match('/^-?\d+(\.\d+)?$/', $text)) {
            throw new InvalidArgumentException('Inventory movement ' . $field . ' must be a decimal value.');
        }
    }
}
