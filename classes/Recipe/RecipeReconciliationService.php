<?php

class RecipeReconciliationService
{
    public function compareItem(mysqli $conn, int $posTenant, int $posBranch, int $storeId, int $itemId, array $filters = []): array
    {
        $item = $this->itemMeta($conn, $itemId);
        $legacyQty = $this->legacyItemQty($conn, $itemId);
        $fatBalance = $this->fatDetailsBalance($conn, $posTenant, $posBranch, $storeId, $itemId, $filters);
        $ledgerBalance = $this->ledgerBalance($conn, $posTenant, $posBranch, $storeId, $itemId, $filters);
        $balanceRow = $this->balanceRow($conn, $posTenant, $posBranch, $storeId, $itemId);
        $balanceQty = $this->decimalNormalize($balanceRow['qty_on_hand'] ?? '0');
        $legacyVsFat = $this->decimalSubtract($legacyQty, $fatBalance);
        $ledgerVsBalance = $this->decimalSubtract($ledgerBalance, $balanceQty);
        $legacyVsLedger = $this->decimalSubtract($legacyQty, $ledgerBalance);
        $hasDifference = $this->decimalCompare($legacyVsFat, '0') !== 0
            || $this->decimalCompare($ledgerVsBalance, '0') !== 0
            || $this->decimalCompare($legacyVsLedger, '0') !== 0;

        return [
            'pos_tenant' => $posTenant,
            'pos_branch' => $posBranch,
            'store_id' => $storeId,
            'item_id' => $itemId,
            'item_code' => (string) ($item['code'] ?? ''),
            'item_name' => (string) ($item['iname'] ?? ''),
            'legacy_qty' => $legacyQty,
            'fat_details_qty' => $fatBalance,
            'ledger_qty' => $ledgerBalance,
            'balance_qty' => $balanceQty,
            'legacy_vs_fat_difference' => $legacyVsFat,
            'ledger_vs_balance_difference' => $ledgerVsBalance,
            'legacy_vs_ledger_difference' => $legacyVsLedger,
            'has_difference' => $hasDifference,
            'recommended_action' => $this->recommendedAction($legacyVsFat, $ledgerVsBalance, $legacyVsLedger),
            'last_movement_id' => isset($balanceRow['last_movement_id']) ? (int) $balanceRow['last_movement_id'] : null,
        ];
    }

    public function report(mysqli $conn, array $filters = []): array
    {
        $posTenant = (int) ($filters['pos_tenant'] ?? 0);
        $posBranch = (int) ($filters['pos_branch'] ?? 0);
        $storeId = (int) ($filters['store_id'] ?? 0);
        $itemIds = $filters['item_ids'] ?? [];
        if (!$itemIds) {
            $itemIds = $this->candidateItemIds($conn, $posTenant, $posBranch, $storeId);
        }
        $limit = isset($filters['limit']) ? max(1, min(5000, (int) $filters['limit'])) : 1000;

        $rows = [];
        foreach ($itemIds as $itemId) {
            $row = $this->compareItem($conn, $posTenant, $posBranch, $storeId, (int) $itemId, $filters);
            if (!empty($filters['differences_only']) && !$row['has_difference']) {
                continue;
            }

            $rows[] = $row;
            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    private function candidateItemIds(mysqli $conn, int $posTenant, int $posBranch, int $storeId): array
    {
        $ids = [];
        foreach ([
            ['myitems', 'SELECT id AS item_id FROM myitems'],
            ['fat_details', 'SELECT DISTINCT item_id FROM fat_details WHERE item_id IS NOT NULL'],
            ['inventory_movements', 'SELECT DISTINCT item_id FROM inventory_movements WHERE pos_tenant = ? AND pos_branch = ? AND store_id = ?'],
            ['inventory_item_balances', 'SELECT DISTINCT item_id FROM inventory_item_balances WHERE pos_tenant = ? AND pos_branch = ? AND store_id = ?'],
        ] as $source) {
            [$table, $sql] = $source;
            if (!$this->tableExists($conn, $table)) {
                continue;
            }
            $params = strpos($sql, '?') !== false ? [$posTenant, $posBranch, $storeId] : [];
            foreach ($this->fetchAll($conn, $sql, $params) as $row) {
                $id = (int) ($row['item_id'] ?? 0);
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        }

        return array_keys($ids);
    }

    private function itemMeta(mysqli $conn, int $itemId): array
    {
        if (!$this->tableExists($conn, 'myitems')) {
            return [];
        }

        $columns = ['id'];
        foreach (['code', 'iname'] as $column) {
            if ($this->columnExists($conn, 'myitems', $column)) {
                $columns[] = $column;
            }
        }

        return $this->fetchOne(
            $conn,
            'SELECT ' . implode(', ', array_map([$this, 'quoteIdentifier'], $columns)) . ' FROM myitems WHERE id = ? LIMIT 1',
            [$itemId]
        ) ?: [];
    }

    private function legacyItemQty(mysqli $conn, int $itemId): string
    {
        if (!$this->tableExists($conn, 'myitems') || !$this->columnExists($conn, 'myitems', 'itmqty')) {
            return $this->decimalZero();
        }
        $row = $this->fetchOne($conn, 'SELECT itmqty FROM myitems WHERE id = ? LIMIT 1', [$itemId]);

        return $this->decimalNormalize($row['itmqty'] ?? '0');
    }

    private function fatDetailsBalance(mysqli $conn, int $posTenant, int $posBranch, int $storeId, int $itemId, array $filters): string
    {
        if (!$this->tableExists($conn, 'fat_details')) {
            return $this->decimalZero();
        }

        $conditions = ['item_id = ?'];
        $params = [$itemId];
        if ($this->columnExists($conn, 'fat_details', 'tenant')) {
            $conditions[] = 'tenant = ?';
            $params[] = $posTenant;
        }
        if ($this->columnExists($conn, 'fat_details', 'branch')) {
            $conditions[] = 'branch = ?';
            $params[] = $posBranch;
        }
        if ($this->columnExists($conn, 'fat_details', 'det_store')) {
            $conditions[] = 'det_store = ?';
            $params[] = $storeId;
        }
        if ($this->columnExists($conn, 'fat_details', 'isdeleted')) {
            $conditions[] = 'COALESCE(isdeleted, 0) = 0';
        }
        if ($this->columnExists($conn, 'fat_details', 'crtime')) {
            $dateFilter = $this->dateFilter($filters);
            if ($dateFilter['from'] !== null) {
                $conditions[] = 'crtime >= ?';
                $params[] = $dateFilter['from'] . ' 00:00:00';
            }
            if ($dateFilter['to'] !== null) {
                $conditions[] = 'crtime <= ?';
                $params[] = $dateFilter['to'] . ' 23:59:59';
            }
        }
        $row = $this->fetchOne(
            $conn,
            'SELECT COALESCE(SUM(qty_in - qty_out), 0) AS qty FROM fat_details WHERE ' . implode(' AND ', $conditions),
            $params
        );

        return $this->decimalNormalize($row['qty'] ?? '0');
    }

    private function ledgerBalance(mysqli $conn, int $posTenant, int $posBranch, int $storeId, int $itemId, array $filters): string
    {
        if (!$this->tableExists($conn, 'inventory_movements')) {
            return $this->decimalZero();
        }
        $conditions = [
            'pos_tenant = ?',
            'pos_branch = ?',
            'store_id = ?',
            'item_id = ?',
        ];
        $params = [$posTenant, $posBranch, $storeId, $itemId];
        $dateFilter = $this->dateFilter($filters);
        if ($dateFilter['from'] !== null) {
            $conditions[] = 'created_at >= ?';
            $params[] = $dateFilter['from'] . ' 00:00:00';
        }
        if ($dateFilter['to'] !== null) {
            $conditions[] = 'created_at <= ?';
            $params[] = $dateFilter['to'] . ' 23:59:59';
        }
        if (!empty($filters['movement_type'])) {
            $conditions[] = 'movement_type = ?';
            $params[] = (string) $filters['movement_type'];
        }
        if (!empty($filters['source_type'])) {
            $conditions[] = 'source_type = ?';
            $params[] = (string) $filters['source_type'];
        }
        $row = $this->fetchOne(
            $conn,
            'SELECT COALESCE(SUM(qty_in - qty_out), 0) AS qty FROM inventory_movements WHERE ' . implode(' AND ', $conditions),
            $params
        );

        return $this->decimalNormalize($row['qty'] ?? '0');
    }

    private function balanceRow(mysqli $conn, int $posTenant, int $posBranch, int $storeId, int $itemId): array
    {
        if (!$this->tableExists($conn, 'inventory_item_balances')) {
            return [];
        }
        return $this->fetchOne(
            $conn,
            "
SELECT *
FROM inventory_item_balances
WHERE pos_tenant = ?
  AND pos_branch = ?
  AND store_id = ?
  AND item_id = ?
LIMIT 1",
            [$posTenant, $posBranch, $storeId, $itemId]
        ) ?: [];
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $row = $this->fetchOne(
            $conn,
            "
SELECT COUNT(*) AS table_count
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ?",
            [$table]
        );

        return (int) ($row['table_count'] ?? 0) > 0;
    }

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $row = $this->fetchOne(
            $conn,
            "
SELECT COUNT(*) AS column_count
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ?
  AND COLUMN_NAME = ?",
            [$table, $column]
        );

        return (int) ($row['column_count'] ?? 0) > 0;
    }

    private function dateFilter(array $filters): array
    {
        return [
            'from' => $this->normalizeDate($filters['date_from'] ?? null),
            'to' => $this->normalizeDate($filters['date_to'] ?? null),
        ];
    }

    private function normalizeDate($value): ?string
    {
        $text = trim((string) $value);
        if ($text === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) !== 1) {
            return null;
        }

        return $text;
    }

    private function recommendedAction(string $legacyVsFat, string $ledgerVsBalance, string $legacyVsLedger): string
    {
        if ($this->decimalCompare($ledgerVsBalance, '0') !== 0) {
            return 'Review inventory_item_balances against inventory_movements before enabling recipe stock.';
        }
        if ($this->decimalCompare($legacyVsLedger, '0') !== 0) {
            return 'Reconcile legacy stock with recipe ledger before expanding pilot items.';
        }
        if ($this->decimalCompare($legacyVsFat, '0') !== 0) {
            return 'Review legacy fat_details trigger balance for this item.';
        }

        return 'No action required.';
    }

    private function decimalZero(int $scale = 6): string
    {
        return $this->decimalNormalize('0', $scale);
    }

    private function decimalNormalize($value, int $scale = 6): string
    {
        $text = trim((string) $value);
        if ($text === '' || !preg_match('/^-?\d+(\.\d+)?$/', $text)) {
            $text = '0';
        }

        $negative = $text[0] === '-';
        if ($negative) {
            $text = substr($text, 1);
        }

        $parts = explode('.', $text, 2);
        $whole = ltrim($parts[0], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = $parts[1] ?? '';
        $fraction = substr(str_pad($fraction, $scale + 1, '0'), 0, $scale + 1);

        if (strlen($fraction) > $scale && (int) $fraction[$scale] >= 5) {
            $rounded = $this->addIntegerStrings($whole . substr($fraction, 0, $scale), '1');
            if ($scale > 0) {
                $rounded = str_pad($rounded, $scale + 1, '0', STR_PAD_LEFT);
                $whole = ltrim(substr($rounded, 0, -$scale), '0');
                $whole = $whole === '' ? '0' : $whole;
                $fraction = substr($rounded, -$scale);
            } else {
                $whole = ltrim($rounded, '0') ?: '0';
                $fraction = '';
            }
        } else {
            $fraction = substr($fraction, 0, $scale);
        }

        $isZero = $whole === '0' && trim($fraction, '0') === '';

        return ($negative && !$isZero ? '-' : '')
            . $whole
            . ($scale > 0 ? '.' . str_pad($fraction, $scale, '0') : '');
    }

    private function decimalSubtract($left, $right, int $scale = 6): string
    {
        [$leftNegative, $leftInt] = $this->decimalSignedInteger($left, $scale);
        [$rightNegative, $rightInt] = $this->decimalSignedInteger($right, $scale);
        $rightNegative = !$rightNegative;

        if ($leftInt === '0') {
            $leftNegative = false;
        }
        if ($rightInt === '0') {
            $rightNegative = false;
        }

        if ($leftNegative === $rightNegative) {
            return $this->decimalFromScaledInteger(
                $this->addIntegerStrings($leftInt, $rightInt),
                $scale,
                $leftNegative
            );
        }

        $comparison = $this->compareIntegerStrings($leftInt, $rightInt);
        if ($comparison === 0) {
            return $this->decimalZero($scale);
        }
        if ($comparison > 0) {
            return $this->decimalFromScaledInteger(
                $this->subtractIntegerStrings($leftInt, $rightInt),
                $scale,
                $leftNegative
            );
        }

        return $this->decimalFromScaledInteger(
            $this->subtractIntegerStrings($rightInt, $leftInt),
            $scale,
            $rightNegative
        );
    }

    private function decimalCompare($left, $right, int $scale = 6): int
    {
        [$leftNegative, $leftInt] = $this->decimalSignedInteger($left, $scale);
        [$rightNegative, $rightInt] = $this->decimalSignedInteger($right, $scale);

        if ($leftInt === '0') {
            $leftNegative = false;
        }
        if ($rightInt === '0') {
            $rightNegative = false;
        }
        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }

        $comparison = $this->compareIntegerStrings($leftInt, $rightInt);

        return $leftNegative ? -$comparison : $comparison;
    }

    private function decimalSignedInteger($decimal, int $scale): array
    {
        $normalized = $this->decimalNormalize($decimal, $scale);
        $negative = strpos($normalized, '-') === 0;
        $digits = str_replace(['-', '.'], '', $normalized);

        return [$negative, ltrim($digits, '0') ?: '0'];
    }

    private function decimalFromScaledInteger(string $scaled, int $scale = 6, bool $negative = false): string
    {
        $scaled = ltrim($scaled, '0') ?: '0';
        if ($scale > 0 && strlen($scaled) <= $scale) {
            $decimal = '0.' . str_pad($scaled, $scale, '0', STR_PAD_LEFT);
        } elseif ($scale > 0) {
            $decimal = substr($scaled, 0, -$scale) . '.' . substr($scaled, -$scale);
        } else {
            $decimal = $scaled;
        }

        return $this->decimalNormalize(($negative && $scaled !== '0' ? '-' : '') . $decimal, $scale);
    }

    private function compareIntegerStrings(string $left, string $right): int
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';
        if (strlen($left) !== strlen($right)) {
            return strlen($left) < strlen($right) ? -1 : 1;
        }

        return $left <=> $right;
    }

    private function subtractIntegerStrings(string $left, string $right): string
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';
        if ($this->compareIntegerStrings($left, $right) < 0) {
            throw new InvalidArgumentException('Cannot subtract a larger unsigned integer string.');
        }

        $borrow = 0;
        $result = '';
        $i = strlen($left) - 1;
        $j = strlen($right) - 1;
        while ($i >= 0) {
            $digit = (int) $left[$i] - $borrow;
            $subtrahend = $j >= 0 ? (int) $right[$j] : 0;
            if ($digit < $subtrahend) {
                $digit += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $result = (string) ($digit - $subtrahend) . $result;
            $i--;
            $j--;
        }

        return ltrim($result, '0') ?: '0';
    }

    private function addIntegerStrings(string $left, string $right): string
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';
        $carry = 0;
        $result = '';
        $i = strlen($left) - 1;
        $j = strlen($right) - 1;
        while ($i >= 0 || $j >= 0 || $carry > 0) {
            $sum = $carry;
            if ($i >= 0) {
                $sum += (int) $left[$i--];
            }
            if ($j >= 0) {
                $sum += (int) $right[$j--];
            }
            $result = (string) ($sum % 10) . $result;
            $carry = intdiv($sum, 10);
        }

        return ltrim($result, '0') ?: '0';
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException('Unsafe SQL identifier: ' . $identifier);
        }

        return '`' . $identifier . '`';
    }

    private function fetchOne(mysqli $conn, string $sql, array $params = []): ?array
    {
        $rows = $this->fetchAll($conn, $sql, $params);

        return $rows[0] ?? null;
    }

    private function fetchAll(mysqli $conn, string $sql, array $params = []): array
    {
        $stmt = $conn->prepare($sql);
        if ($params) {
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
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }
}
