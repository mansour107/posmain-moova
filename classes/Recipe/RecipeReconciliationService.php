<?php

require_once __DIR__ . '/../../includes/pos_default_accounts.php';
require_once __DIR__ . '/../../includes/pos_operational_store.php';

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
        $legacySummaryComparable = $storeId <= 0 || !$this->shouldScopeFatDetailsByStore($conn, $storeId);
        $differenceReasons = $this->differenceReasons(
            $item,
            $storeId,
            $legacySummaryComparable,
            $legacyQty,
            $fatBalance,
            $ledgerBalance,
            $balanceQty,
            $legacyVsFat,
            $ledgerVsBalance,
            $legacyVsLedger,
            $balanceRow
        );
        $hasDifference = !empty($differenceReasons);

        return [
            'pos_tenant' => $posTenant,
            'pos_branch' => $posBranch,
            'store_id' => $storeId,
            'item_id' => $itemId,
            'item_barcode' => (string) ($item['barcode'] ?? $item['code'] ?? ''),
            'item_name' => (string) ($item['iname'] ?? ''),
            'item_type' => (string) ($item['item_type'] ?? ''),
            'track_stock' => array_key_exists('track_stock', $item) ? (int) $item['track_stock'] : null,
            'legacy_qty' => $legacyQty,
            'fat_details_qty' => $fatBalance,
            'ledger_qty' => $ledgerBalance,
            'balance_qty' => $balanceQty,
            'legacy_vs_fat_difference' => $legacyVsFat,
            'ledger_vs_balance_difference' => $ledgerVsBalance,
            'legacy_vs_ledger_difference' => $legacyVsLedger,
            'has_difference' => $hasDifference,
            'difference_reasons' => $differenceReasons,
            'difference_reason' => implode(',', $differenceReasons),
            'recommended_action' => $this->recommendedAction($legacyVsFat, $ledgerVsBalance, $legacyVsLedger, $differenceReasons),
            'last_movement_id' => isset($balanceRow['last_movement_id']) ? (int) $balanceRow['last_movement_id'] : null,
        ];
    }

    public function report(mysqli $conn, array $filters = []): array
    {
        $posTenant = (int) ($filters['pos_tenant'] ?? 0);
        $posBranch = (int) ($filters['pos_branch'] ?? 0);
        $storeId = $this->requestedStoreId($conn, $filters);
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
            ['inventory_movements', $storeId > 0
                ? 'SELECT DISTINCT item_id FROM inventory_movements WHERE pos_tenant = ? AND pos_branch = ? AND store_id = ?'
                : 'SELECT DISTINCT item_id FROM inventory_movements WHERE pos_tenant = ? AND pos_branch = ?'],
            ['inventory_item_balances', $storeId > 0
                ? 'SELECT DISTINCT item_id FROM inventory_item_balances WHERE pos_tenant = ? AND pos_branch = ? AND store_id = ?'
                : 'SELECT DISTINCT item_id FROM inventory_item_balances WHERE pos_tenant = ? AND pos_branch = ?'],
        ] as $source) {
            [$table, $sql] = $source;
            if (!$this->tableExists($conn, $table)) {
                continue;
            }
            $params = strpos($sql, '?') !== false ? ($storeId > 0 ? [$posTenant, $posBranch, $storeId] : [$posTenant, $posBranch]) : [];
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
        foreach (['barcode', 'code', 'iname', 'item_type', 'track_stock'] as $column) {
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
        if ($storeId > 0 && $this->columnExists($conn, 'fat_details', 'det_store') && $this->shouldScopeFatDetailsByStore($conn, $storeId)) {
            $conditions[] = 'det_store = ?';
            $params[] = $storeId;
        }
        if ($this->columnExists($conn, 'fat_details', 'isdeleted')) {
            $conditions[] = 'COALESCE(isdeleted, 0) = 0';
        }
        if ($this->canResolveLegacyOrderSettlement($conn)) {
            // Active unpaid POS lines represent reservations, not completed
            // stock depletion. Keeping them in the compatibility sum would
            // certify the exact historical draft-consumption bug that the
            // live lifecycle now prevents.
            $fatTypeColumn = $this->columnExists($conn, 'fat_details', 'pro_tybe') ? 'pro_tybe' : 'fat_tybe';
            $conditions[] = "NOT (
                COALESCE(fat_details.{$fatTypeColumn}, 0) = 9
                AND fat_details.qty_out > 0
                AND EXISTS (
                    SELECT 1
                    FROM ot_head legacy_order
                    WHERE legacy_order.id = fat_details.fatid
                      AND LOWER(COALESCE(legacy_order.payment_status, 'unpaid')) IN ('unpaid', 'partial')
                      AND LOWER(COALESCE(legacy_order.invoice_status, '')) = 'draft'
                      AND LOWER(COALESCE(legacy_order.order_status, '')) IN ('draft', 'active')
                      AND COALESCE(legacy_order.closed, 0) = 0
                      AND COALESCE(legacy_order.isdeleted, 0) = 0
                )
            )";
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

    private function canResolveLegacyOrderSettlement(mysqli $conn): bool
    {
        return $this->tableExists($conn, 'ot_head')
            && $this->columnExists($conn, 'ot_head', 'id')
            && $this->columnExists($conn, 'ot_head', 'payment_status')
            && $this->columnExists($conn, 'ot_head', 'invoice_status')
            && $this->columnExists($conn, 'ot_head', 'order_status')
            && $this->columnExists($conn, 'ot_head', 'closed')
            && $this->columnExists($conn, 'ot_head', 'isdeleted')
            && $this->columnExists($conn, 'fat_details', 'fatid')
            && (
                $this->columnExists($conn, 'fat_details', 'pro_tybe')
                || $this->columnExists($conn, 'fat_details', 'fat_tybe')
            );
    }

    private function ledgerBalance(mysqli $conn, int $posTenant, int $posBranch, int $storeId, int $itemId, array $filters): string
    {
        if (!$this->tableExists($conn, 'inventory_movements')) {
            return $this->decimalZero();
        }
        $conditions = [
            'pos_tenant = ?',
            'pos_branch = ?',
            'item_id = ?',
        ];
        $params = [$posTenant, $posBranch, $itemId];
        if ($storeId > 0) {
            $conditions[] = 'store_id = ?';
            $params[] = $storeId;
        }
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
        if ($storeId <= 0) {
            return $this->aggregateBalanceRow($conn, $posTenant, $posBranch, $itemId);
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

    private function aggregateBalanceRow(mysqli $conn, int $posTenant, int $posBranch, int $itemId): array
    {
        $row = $this->fetchOne(
            $conn,
            "
SELECT
  COALESCE(SUM(qty_on_hand), 0) AS qty_on_hand,
  COALESCE(SUM(qty_reserved), 0) AS qty_reserved,
  COALESCE(SUM(qty_available), 0) AS qty_available,
  MAX(last_movement_id) AS last_movement_id,
  COUNT(*) AS balance_scope_count
FROM inventory_item_balances
WHERE pos_tenant = ?
  AND pos_branch = ?
  AND item_id = ?",
            [$posTenant, $posBranch, $itemId]
        );

        return $row ?: [];
    }

    private function shouldScopeFatDetailsByStore(mysqli $conn, int $storeId): bool
    {
        if ($storeId < 1) {
            return false;
        }
        if (function_exists('posmain_single_store_mode_enabled') && posmain_single_store_mode_enabled()
            && function_exists('posmain_operational_store_id')) {
            $operational = posmain_operational_store_id($conn);

            return $operational < 1 || $storeId !== $operational;
        }

        return true;
    }

    private function requestedStoreId(mysqli $conn, array $filters): int
    {
        if (!array_key_exists('store_id', $filters) && !array_key_exists('store', $filters)) {
            return 0;
        }

        $storeId = max(0, (int) ($filters['store_id'] ?? $filters['store'] ?? 0));
        if (function_exists('posmain_apply_read_store_filter')) {
            return posmain_apply_read_store_filter($conn, $storeId);
        }

        return $storeId;
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

    private function differenceReasons(
        array $item,
        int $storeId,
        bool $legacySummaryComparable,
        string $legacyQty,
        string $fatBalance,
        string $ledgerBalance,
        string $balanceQty,
        string $legacyVsFat,
        string $ledgerVsBalance,
        string $legacyVsLedger,
        array $balanceRow
    ): array {
        $reasons = [];
        $itemType = strtolower(trim((string) ($item['item_type'] ?? '')));
        $trackStock = array_key_exists('track_stock', $item) ? (int) $item['track_stock'] : 1;
        $nonStockItem = $trackStock === 0 || $itemType === 'service';

        // fat_details is also the immutable commercial sale-line history. A
        // non-stock/service item may legitimately appear there; only current
        // stock ledger or balance state is invalid for that policy.
        if ($nonStockItem && ($this->isNonZero($ledgerBalance) || $this->isNonZero($balanceQty))) {
            $reasons[] = 'non_stock_item_has_stock_movement';
        }
        // In the operational/global scope, the compatibility mirror and the
        // immutable ledger are the comparable stock truths. fat_details also
        // contains commercial history and intentionally omits active draft
        // reservations, so a fat-only delta must not fail cutover when mirror,
        // ledger, and materialized balance agree.
        if (!$nonStockItem && $legacySummaryComparable && $this->decimalCompare($legacyVsLedger, '0') !== 0) {
            $reasons[] = 'legacy_summary_mismatch';
        }
        if ($this->decimalCompare($ledgerVsBalance, '0') !== 0) {
            $reasons[] = empty($balanceRow) ? 'missing_balance_row' : 'ledger_balance_mismatch';
        }
        $stockTruthDifference = $legacySummaryComparable
            ? $legacyVsLedger
            : $this->decimalSubtract($fatBalance, $ledgerBalance);
        if (!$nonStockItem && $this->decimalCompare($stockTruthDifference, '0') !== 0) {
            if ($this->isNonZero($fatBalance) && !$this->isNonZero($ledgerBalance)) {
                $reasons[] = 'missing_bridge_movement';
            } elseif (!$this->isNonZero($fatBalance) && $this->isNonZero($ledgerBalance)) {
                $reasons[] = 'deleted_fat_detail_or_ledger_only';
            } else {
                $reasons[] = 'movement_scope_or_quantity_mismatch';
            }
        }

        return array_values(array_unique($reasons));
    }

    private function recommendedAction(string $legacyVsFat, string $ledgerVsBalance, string $legacyVsLedger, array $reasons = []): string
    {
        if (in_array('non_stock_item_has_stock_movement', $reasons, true)) {
            return 'Review item stock policy; service or non-stock items should not carry stock movements.';
        }
        if (in_array('missing_bridge_movement', $reasons, true)) {
            return 'Find the legacy stock path that has not been bridged before enabling bridge/live mode.';
        }
        if (in_array('deleted_fat_detail_or_ledger_only', $reasons, true)) {
            return 'Review deleted legacy details and ledger-only movements before cutover.';
        }
        if ($this->decimalCompare($ledgerVsBalance, '0') !== 0) {
            return 'Review inventory_item_balances against inventory_movements before enabling recipe stock.';
        }
        if ($reasons === []) {
            return 'No action required.';
        }
        if ($this->decimalCompare($legacyVsLedger, '0') !== 0) {
            return 'Reconcile legacy stock with recipe ledger before expanding pilot items.';
        }
        if ($this->decimalCompare($legacyVsFat, '0') !== 0) {
            return 'Review legacy fat_details trigger balance for this item.';
        }

        return 'No action required.';
    }

    private function isNonZero(string $value): bool
    {
        return $this->decimalCompare($value, '0') !== 0;
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
