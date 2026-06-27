<?php

class InventoryAccountingReconciliationService
{
    public function review(mysqli $conn, array $filters = []): array
    {
        foreach (['inventory_movements', 'journal_heads', 'journal_entries'] as $table) {
            if (!$this->tableExists($conn, $table)) {
                return [
                    'ok' => false,
                    'status' => 'missing_table',
                    'missing_table' => $table,
                    'rows' => [],
                ];
            }
        }

        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        $conditions = [
            "im.movement_type IN ('purchase','purchase_return','sale_direct','waste','adjustment','refund_reversal')",
            'COALESCE(im.total_cost, 0) > 0',
            "(im.source_type IS NULL OR im.source_type <> 'fat_details' OR im.idempotency_key IS NULL OR im.idempotency_key NOT LIKE 'migration:fat_details:%')",
        ];
        $params = [];
        foreach (['pos_tenant', 'pos_branch', 'store_id'] as $column) {
            if (!isset($filters[$column]) || (int) $filters[$column] < 0) {
                continue;
            }
            $conditions[] = 'im.' . $this->quoteIdentifier($column) . ' = ?';
            $params[] = (int) $filters[$column];
        }

        $rows = $this->fetchAll(
            $conn,
            "
SELECT *
FROM (
SELECT
  CASE
    WHEN im.accounting_journal_id IS NULL OR im.accounting_journal_id = 0
      THEN CONCAT('missing:', im.movement_type, ':', im.source_type, ':', COALESCE(im.source_id, im.id))
    ELSE CONCAT('journal:', im.accounting_journal_id)
  END AS review_key,
  im.accounting_journal_id,
  MIN(im.pos_tenant) AS pos_tenant,
  MIN(im.pos_branch) AS pos_branch,
  MIN(im.store_id) AS store_id,
  COUNT(DISTINCT im.store_id) AS store_count,
  MIN(im.movement_type) AS sample_movement_type,
  MIN(im.source_type) AS sample_source_type,
  COUNT(*) AS movement_count,
  COALESCE(SUM(im.total_cost), 0) AS movement_total,
  COALESCE(journal_totals.debit_total, 0) AS journal_debit_total,
  COALESCE(journal_totals.credit_total, 0) AS journal_credit_total,
  jh.details AS journal_details,
  CASE
    WHEN im.accounting_journal_id IS NULL OR im.accounting_journal_id = 0 THEN 'missing_journal'
    WHEN jh.id IS NULL THEN 'journal_head_missing'
    WHEN journal_totals.journal_id IS NULL THEN 'journal_entries_missing'
    WHEN ABS(COALESCE(journal_totals.debit_total, 0) - COALESCE(SUM(im.total_cost), 0)) > 0.0001 THEN 'mismatch'
    WHEN ABS(COALESCE(journal_totals.credit_total, 0) - COALESCE(SUM(im.total_cost), 0)) > 0.0001 THEN 'mismatch'
    ELSE 'balanced'
  END AS reconciliation_status
FROM inventory_movements im
LEFT JOIN journal_heads jh ON jh.id = im.accounting_journal_id
LEFT JOIN (
  SELECT
    journal_id,
    COALESCE(SUM(debit), 0) AS debit_total,
    COALESCE(SUM(credit), 0) AS credit_total
  FROM journal_entries
  GROUP BY journal_id
) journal_totals ON journal_totals.journal_id = im.accounting_journal_id
WHERE " . implode(' AND ', $conditions) . "
GROUP BY
  review_key,
  im.accounting_journal_id,
  jh.id,
  jh.details,
  journal_totals.journal_id,
  journal_totals.debit_total,
  journal_totals.credit_total
) reconciled
ORDER BY
  CASE reconciliation_status
    WHEN 'missing_journal' THEN 0
    WHEN 'journal_head_missing' THEN 1
    WHEN 'journal_entries_missing' THEN 2
    WHEN 'mismatch' THEN 3
    ELSE 4
  END,
  review_key
LIMIT {$limit}",
            $params
        );

        $problemCount = count(array_filter($rows, static function (array $row): bool {
            return (string) ($row['reconciliation_status'] ?? '') !== 'balanced';
        }));

        return [
            'ok' => $problemCount === 0,
            'status' => $problemCount === 0 ? 'ready' : 'problems_found',
            'rows' => $rows,
            'problem_count' => $problemCount,
        ];
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $stmt = $conn->prepare("
SELECT COUNT(*) AS table_count
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ?");
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['table_count'] ?? 0) > 0;
    }

    private function fetchAll(mysqli $conn, string $sql, array $params = []): array
    {
        $stmt = $conn->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    private function bindParams(mysqli_stmt $stmt, array $params): void
    {
        if (!$params) {
            return;
        }

        $types = '';
        foreach ($params as $value) {
            $types .= is_int($value) ? 'i' : 's';
        }
        $refs = [];
        foreach ($params as $index => $value) {
            $refs[$index] = $value;
        }
        $bind = [$types];
        foreach ($refs as $index => $_) {
            $bind[] = &$refs[$index];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException('Unsafe SQL identifier: ' . $identifier);
        }

        return '`' . $identifier . '`';
    }
}
