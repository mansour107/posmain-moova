<?php

require_once __DIR__ . '/RecipeRepositoryBase.php';
require_once dirname(__DIR__) . '/RecipeDecimal.php';

class ProductionBatchRepository extends RecipeRepositoryBase
{
    private const BATCH_STATUSES = ['draft', 'committed', 'cancelled'];
    private const LINE_TYPES = ['input', 'output', 'variance'];

    public function createBatch(mysqli $conn, array $data): int
    {
        $defaults = [
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'branch_uuid' => null,
            'store_id' => 0,
            'actual_output_qty' => null,
            'status' => 'draft',
            'started_at' => null,
            'committed_at' => null,
            'created_by' => null,
            'committed_by' => null,
            'variance_reason' => null,
            'notes' => null,
        ];

        $row = array_merge($defaults, $data);
        $row['status'] = trim((string) ($row['status'] ?? ''));
        $this->assertValidBatch($row);
        $row['planned_output_qty'] = RecipeDecimal::normalize($row['planned_output_qty']);
        if ($row['actual_output_qty'] !== null) {
            $row['actual_output_qty'] = RecipeDecimal::normalize($row['actual_output_qty']);
        }

        return $this->insertRow($conn, 'production_batches', $row);
    }

    public function createBatchLine(mysqli $conn, array $data): int
    {
        $defaults = [
            'planned_qty' => '0.000000',
            'actual_qty' => '0.000000',
            'unit_id' => null,
            'unit_cost' => '0.000000',
            'total_cost' => '0.000000',
            'inventory_movement_id' => null,
        ];

        $row = array_merge($defaults, $data);
        $row['line_type'] = trim((string) ($row['line_type'] ?? ''));
        $this->assertValidBatchLine($row);
        foreach (['planned_qty', 'actual_qty', 'unit_cost', 'total_cost'] as $field) {
            $row[$field] = RecipeDecimal::normalize($row[$field]);
        }

        return $this->insertRow($conn, 'production_batch_lines', $row);
    }

    public function findBatchById(mysqli $conn, int $batchId): ?array
    {
        return $this->fetchOne($conn, 'SELECT * FROM production_batches WHERE id = ? LIMIT 1', [$batchId]);
    }

    public function findBatchByIdForUpdate(mysqli $conn, int $batchId): ?array
    {
        return $this->fetchOne($conn, 'SELECT * FROM production_batches WHERE id = ? LIMIT 1 FOR UPDATE', [$batchId]);
    }

    public function updateCommitted(
        mysqli $conn,
        int $batchId,
        string $actualOutputQty,
        ?int $committedBy,
        ?string $varianceReason = null
    ): int {
        if ($batchId < 1) {
            throw new InvalidArgumentException('Production batch id must be positive.');
        }
        $this->assertDecimal($actualOutputQty, 'actual_output_qty');
        if (RecipeDecimal::compare($actualOutputQty, '0') <= 0) {
            throw new InvalidArgumentException('Production batch actual_output_qty must be positive.');
        }
        if ($committedBy !== null && $committedBy < 1) {
            throw new InvalidArgumentException('Production batch committed_by must be positive when provided.');
        }
        $actualOutputQty = RecipeDecimal::normalize($actualOutputQty);

        return $this->executeStatement(
            $conn,
            "
UPDATE production_batches
SET actual_output_qty = ?,
    status = 'committed',
    committed_at = CURRENT_TIMESTAMP,
    committed_by = ?,
    variance_reason = COALESCE(?, variance_reason)
WHERE id = ?
  AND status = 'draft'",
            [$actualOutputQty, $committedBy, $varianceReason, $batchId]
        );
    }

    public function cancel(mysqli $conn, int $batchId, string $reason): int
    {
        if ($batchId < 1) {
            throw new InvalidArgumentException('Production batch id must be positive.');
        }

        return $this->executeStatement(
            $conn,
            "
UPDATE production_batches
SET status = 'cancelled',
    variance_reason = COALESCE(NULLIF(?, ''), variance_reason)
WHERE id = ?
  AND status = 'draft'",
            [$reason, $batchId]
        );
    }

    public function findLinesByBatchId(mysqli $conn, int $batchId): array
    {
        return $this->fetchAll(
            $conn,
            'SELECT * FROM production_batch_lines WHERE batch_id = ? ORDER BY id',
            [$batchId]
        );
    }

    public function advanceSyncRevision(mysqli $conn, int $batchId): int
    {
        if ($batchId < 1) {
            throw new InvalidArgumentException('Production batch id must be positive.');
        }

        $changed = $this->executeStatement(
            $conn,
            'UPDATE production_batches
             SET sync_revision = CASE WHEN sync_revision < 1 THEN 1 ELSE sync_revision + 1 END
             WHERE id = ?',
            [$batchId]
        );
        if ($changed !== 1) {
            throw new RuntimeException('Production batch sync revision could not be advanced.');
        }

        $batch = $this->findBatchById($conn, $batchId);
        $revision = (int) ($batch['sync_revision'] ?? 0);
        if ($revision < 1) {
            throw new RuntimeException('Production batch sync revision is invalid.');
        }

        return $revision;
    }

    private function assertValidBatch(array $row): void
    {
        if (trim((string) ($row['batch_uuid'] ?? '')) === '') {
            throw new InvalidArgumentException('Production batch UUID is required.');
        }
        if (!in_array((string) ($row['status'] ?? ''), self::BATCH_STATUSES, true)) {
            throw new InvalidArgumentException('Production batch status is invalid.');
        }

        foreach (['pos_tenant', 'pos_branch', 'store_id'] as $field) {
            if ((int) ($row[$field] ?? 0) < 0) {
                throw new InvalidArgumentException('Production batch ' . $field . ' cannot be negative.');
            }
        }

        foreach (['recipe_id', 'output_item_id'] as $field) {
            if ((int) ($row[$field] ?? 0) < 1) {
                throw new InvalidArgumentException('Production batch ' . $field . ' must be positive.');
            }
        }

        foreach (['created_by', 'committed_by'] as $field) {
            if ($row[$field] !== null && (int) $row[$field] < 1) {
                throw new InvalidArgumentException('Production batch ' . $field . ' must be positive when provided.');
            }
        }

        $this->assertDecimal($row['planned_output_qty'] ?? null, 'planned_output_qty');
        if (RecipeDecimal::compare($row['planned_output_qty'], '0') <= 0) {
            throw new InvalidArgumentException('Production batch planned_output_qty must be positive.');
        }

        if ($row['actual_output_qty'] !== null) {
            $this->assertDecimal($row['actual_output_qty'], 'actual_output_qty');
            if (RecipeDecimal::compare($row['actual_output_qty'], '0') <= 0) {
                throw new InvalidArgumentException('Production batch actual_output_qty must be positive when provided.');
            }
        }
    }

    private function assertValidBatchLine(array $row): void
    {
        if ((int) ($row['batch_id'] ?? 0) < 1) {
            throw new InvalidArgumentException('Production batch line batch_id must be positive.');
        }
        if (!in_array((string) ($row['line_type'] ?? ''), self::LINE_TYPES, true)) {
            throw new InvalidArgumentException('Production batch line_type is invalid.');
        }
        if ((int) ($row['item_id'] ?? 0) < 1) {
            throw new InvalidArgumentException('Production batch line item_id must be positive.');
        }

        foreach (['unit_id', 'inventory_movement_id'] as $field) {
            if ($row[$field] !== null && (int) $row[$field] < 1) {
                throw new InvalidArgumentException('Production batch line ' . $field . ' must be positive when provided.');
            }
        }

        foreach (['planned_qty', 'actual_qty', 'unit_cost', 'total_cost'] as $field) {
            $this->assertDecimal($row[$field] ?? null, $field);
            if (RecipeDecimal::compare($row[$field], '0') < 0) {
                throw new InvalidArgumentException('Production batch line ' . $field . ' cannot be negative.');
            }
        }
    }

    private function assertDecimal($value, string $field): void
    {
        $text = trim((string) $value);
        if ($text === '' || !preg_match('/^-?\d+(\.\d+)?$/', $text)) {
            throw new InvalidArgumentException('Production batch ' . $field . ' must be a decimal value.');
        }
    }
}
