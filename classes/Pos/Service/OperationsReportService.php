<?php

require_once __DIR__ . '/BusinessDayService.php';
require_once __DIR__ . '/CashFlowPeriodService.php';
require_once __DIR__ . '/../../Financial/RefundReversalReadService.php';

/**
 * Canonical read model for the first-version POS operating reports.
 *
 * Sales/orders/items are read from the posted order tables. Tender totals and
 * drawer evidence remain owned by CashFlowPeriodService. Refund revenue is
 * read from posted credit notes, while tender refunds are read from
 * payment_refunds by CashFlowPeriodService; this keeps revenue and custody
 * concepts explicit instead of blending them into one unreliable total.
 */
class OperationsReportService
{
    private BusinessDayService $businessDays;
    private CashFlowPeriodService $cashFlow;
    private RefundReversalReadService $refunds;
    /** @var array<string, bool> */
    private array $tableCache = [];
    /** @var array<string, bool> */
    private array $columnCache = [];

    public function __construct(
        ?BusinessDayService $businessDays = null,
        ?CashFlowPeriodService $cashFlow = null,
        ?RefundReversalReadService $refunds = null
    ) {
        $this->businessDays = $businessDays ?: new BusinessDayService();
        $this->cashFlow = $cashFlow ?: new CashFlowPeriodService();
        $this->refunds = $refunds ?: new RefundReversalReadService();
    }

    /** @return array<string, mixed> */
    public function salesSummary(mysqli $conn, array $filters): array
    {
        $scope = $this->normalizeFilters($filters);
        $empty = [
            'available' => false,
            'gross_sales' => 0.0,
            'discounts' => 0.0,
            'service_plus' => 0.0,
            'tax' => 0.0,
            'sales_after_discount' => 0.0,
            'refunds' => 0.0,
            'net_sales' => 0.0,
            'order_count' => 0,
            'refunded_order_count' => 0,
            'refund_count' => 0,
            'discounted_order_count' => 0,
            'average_order_value' => 0.0,
        ];
        if (!$this->tableExists($conn, 'ot_head')) {
            return $empty + $scope;
        }

        [$where, $params] = $this->orderScope($conn, $scope, 'oh', true);
        $grossExpr = $this->columnExists($conn, 'ot_head', 'fat_total')
            ? 'COALESCE(oh.fat_total, oh.fat_net, 0)'
            : 'COALESCE(oh.fat_net, 0)';
        $discountExpr = $this->columnExists($conn, 'ot_head', 'fat_disc')
            ? 'COALESCE(oh.fat_disc, 0)'
            : '0';
        $servicePlusExpr = $this->columnExists($conn, 'ot_head', 'fat_plus')
            ? 'COALESCE(oh.fat_plus, 0)'
            : '0';
        $taxExpr = $this->columnExists($conn, 'ot_head', 'fat_tax')
            ? 'COALESCE(oh.fat_tax, 0)'
            : '0';
        $netExpr = $this->columnExists($conn, 'ot_head', 'fat_net')
            ? 'COALESCE(oh.fat_net, 0)'
            : $grossExpr . ' - ' . $discountExpr;
        $refundedExpr = $this->columnExists($conn, 'ot_head', 'payment_status')
            ? "SUM(CASE WHEN oh.payment_status = 'refunded' THEN 1 ELSE 0 END)"
            : '0';

        $row = $this->queryOne(
            $conn,
            "SELECT COUNT(*) AS order_count,
                    COALESCE(SUM({$grossExpr}), 0) AS gross_sales,
                    COALESCE(SUM({$discountExpr}), 0) AS discounts,
                    COALESCE(SUM({$servicePlusExpr}), 0) AS service_plus,
                    COALESCE(SUM({$taxExpr}), 0) AS tax,
                    COALESCE(SUM({$netExpr}), 0) AS sales_after_discount,
                    COALESCE({$refundedExpr}, 0) AS refunded_order_count,
                    COALESCE(SUM(CASE WHEN {$discountExpr} > 0 THEN 1 ELSE 0 END), 0) AS discounted_order_count
               FROM ot_head oh
              WHERE " . implode(' AND ', $where),
            $params
        ) ?: [];

        $refunds = $this->refundSummary($conn, $scope);
        $orderCount = (int) ($row['order_count'] ?? 0);
        $salesAfterDiscount = (float) ($row['sales_after_discount'] ?? 0);
        $refundTotal = (float) ($refunds['total'] ?? 0);
        $netSales = $salesAfterDiscount - $refundTotal;

        return [
            'available' => true,
            'gross_sales' => (float) ($row['gross_sales'] ?? 0),
            'discounts' => (float) ($row['discounts'] ?? 0),
            'service_plus' => (float) ($row['service_plus'] ?? 0),
            'tax' => (float) ($row['tax'] ?? 0),
            'sales_after_discount' => $salesAfterDiscount,
            'refunds' => $refundTotal,
            'net_sales' => $netSales,
            'order_count' => $orderCount,
            'refunded_order_count' => (int) ($row['refunded_order_count'] ?? 0),
            'refund_count' => (int) ($refunds['count'] ?? 0),
            'discounted_order_count' => (int) ($row['discounted_order_count'] ?? 0),
            'average_order_value' => $orderCount > 0 ? $netSales / $orderCount : 0.0,
        ] + $scope;
    }

    /** @return list<array<string, mixed>> */
    public function orders(mysqli $conn, array $filters, int $limit = 200): array
    {
        if (!$this->tableExists($conn, 'ot_head')) {
            return [];
        }
        $scope = $this->normalizeFilters($filters);
        [$where, $params] = $this->orderScope($conn, $scope, 'oh', false);
        $this->appendOrderFocus($conn, $scope, $where, $params, 'oh');
        $limit = max(1, min(500, $limit));
        $hasUsers = $this->tableExists($conn, 'users');
        $userDisplay = $hasUsers && $this->columnExists($conn, 'users', 'display_name')
            ? "COALESCE(NULLIF(u.display_name, ''), u.uname, '')"
            : ($hasUsers ? "COALESCE(u.uname, '')" : "''");
        $joins = $hasUsers ? ' LEFT JOIN users u ON u.id = oh.user' : '';
        $selects = [
            'oh.id',
            $this->selectColumn($conn, 'ot_head', 'pro_id', 'oh', 'public_order_number'),
            $this->selectColumn($conn, 'ot_head', 'receipt_number', 'oh'),
            $this->selectColumn($conn, 'ot_head', 'pro_date', 'oh'),
            $this->selectColumn($conn, 'ot_head', 'crtime', 'oh'),
            $this->selectColumn($conn, 'ot_head', 'payment_date', 'oh'),
            $this->selectColumn($conn, 'ot_head', 'fat_total', 'oh'),
            $this->selectColumn($conn, 'ot_head', 'fat_disc', 'oh'),
            $this->selectColumn($conn, 'ot_head', 'fat_net', 'oh'),
            $this->selectColumn($conn, 'ot_head', 'payment_status', 'oh'),
            $this->selectColumn($conn, 'ot_head', 'order_status', 'oh'),
            $this->selectColumn($conn, 'ot_head', 'order_type', 'oh'),
            $this->selectColumn($conn, 'ot_head', 'isdeleted', 'oh'),
            'oh.user AS cashier_id',
            "{$userDisplay} AS cashier_name",
        ];
        $sortParts = [];
        foreach (['payment_date', 'completed_at', 'crtime'] as $column) {
            if ($this->columnExists($conn, 'ot_head', $column)) {
                $sortParts[] = 'oh.' . $column;
            }
        }
        $sortParts[] = "CONCAT(oh.pro_date, ' 12:00:00')";
        $sql = 'SELECT ' . implode(', ', $selects)
            . ' FROM ot_head oh' . $joins
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY COALESCE(' . implode(', ', $sortParts) . ") DESC, oh.id DESC LIMIT {$limit}";
        $rows = $this->queryAll($conn, $sql, $params);
        if ($rows === []) {
            return [];
        }

        $payments = $this->paymentsForOrders($conn, array_map(static fn (array $row): int => (int) $row['id'], $rows));
        foreach ($rows as &$row) {
            $orderPayments = $payments[(int) $row['id']] ?? [];
            $row['id'] = (int) $row['id'];
            $row['public_order_number'] = trim((string) ($row['receipt_number'] ?: $row['public_order_number'] ?: $row['id']));
            $row['cashier_id'] = (int) ($row['cashier_id'] ?? 0);
            $row['cashier_name'] = trim((string) ($row['cashier_name'] ?? '')) ?: 'User #' . $row['cashier_id'];
            $row['fat_total'] = (float) ($row['fat_total'] ?? $row['fat_net'] ?? 0);
            $row['fat_disc'] = (float) ($row['fat_disc'] ?? 0);
            $row['fat_net'] = (float) ($row['fat_net'] ?? 0);
            $row['isdeleted'] = (int) ($row['isdeleted'] ?? 0);
            $row['payments'] = $orderPayments;
            $row['payment_methods'] = $orderPayments === []
                ? [trim((string) ($row['payment_status'] ?? '')) === 'unpaid' ? 'Unpaid' : 'Not recorded']
                : array_values(array_unique(array_column($orderPayments, 'label')));
            $reversal = $this->refunds->stateForOrder($conn, $row['id']);
            $row['reversal_status'] = $reversal['reversal_status'];
            $row['cumulative_refunded_amount'] = (float) $reversal['cumulative_refunded_amount'];
            $row['remaining_refundable_amount'] = (float) $reversal['remaining_refundable_amount'];
            $row['refund_count'] = $reversal['refund_count'];
        }
        unset($row);

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function refunds(mysqli $conn, array $filters): array
    {
        $scope = $this->normalizeFilters($filters);
        $rows = $this->refunds->periodSummary($conn, $scope, true)['rows'];
        foreach ($rows as &$row) {
            $state = $this->refunds->stateForOrder($conn, (int) $row['original_order_id']);
            $row['reversal_status'] = $state['reversal_status'];
            $row['cumulative_refunded_amount'] = $state['cumulative_refunded_amount'];
            $row['remaining_refundable_amount'] = $state['remaining_refundable_amount'];
            $row['refund_count'] = $state['refund_count'];
            $row['operator_name'] = 'User #' . (int) ($row['created_by'] ?? 0);
            if ($this->tableExists($conn, 'users') && (int) ($row['created_by'] ?? 0) > 0) {
                $userDisplay = $this->columnExists($conn, 'users', 'display_name')
                    ? "COALESCE(NULLIF(display_name, ''), uname, '')"
                    : "COALESCE(uname, '')";
                $user = $this->queryOne(
                    $conn,
                    "SELECT {$userDisplay} AS display_name FROM users WHERE id = ? LIMIT 1",
                    [(int) $row['created_by']]
                );
                if (trim((string) ($user['display_name'] ?? '')) !== '') {
                    $row['operator_name'] = (string) $user['display_name'];
                }
            }
            $row['settlement_status'] = 'posted';
            $row['pending_external_amount'] = '0.00';
            if ($this->tableExists($conn, 'payment_refunds')) {
                $settlement = $this->queryOne(
                    $conn,
                    "SELECT COALESCE(SUM(CASE WHEN status = 'pending_external' THEN amount ELSE 0 END), 0) AS pending,
                            SUM(CASE WHEN status = 'pending_external' THEN 1 ELSE 0 END) AS pending_count
                       FROM payment_refunds WHERE credit_note_id = ?",
                    [(int) $row['credit_note_id']]
                ) ?: [];
                $row['pending_external_amount'] = number_format((float) ($settlement['pending'] ?? 0), 2, '.', '');
                $row['settlement_status'] = (int) ($settlement['pending_count'] ?? 0) > 0
                    ? 'pending_external'
                    : 'settled';
            }
        }
        unset($row);
        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public function itemSales(mysqli $conn, array $filters): array
    {
        if (!$this->tableExists($conn, 'ot_head') || !$this->tableExists($conn, 'fat_details')) {
            return [];
        }
        $scope = $this->normalizeFilters($filters);
        [$where, $params] = $this->orderScope($conn, $scope, 'oh', true);
        $where[] = 'COALESCE(fd.isdeleted, 0) = 0';
        $hasItems = $this->tableExists($conn, 'myitems');
        $joins = ' JOIN fat_details fd ON fd.fatid = oh.id';
        if ($hasItems) {
            $joins .= ' LEFT JOIN myitems mi ON mi.id = fd.item_id';
        }
        $nameExpr = $hasItems ? "COALESCE(NULLIF(mi.iname, ''), CONCAT('Item #', fd.item_id))" : "CONCAT('Item #', fd.item_id)";
        $soldRows = $this->queryAll(
            $conn,
            "SELECT fd.item_id, {$nameExpr} AS item_name,
                    COALESCE(SUM(COALESCE(fd.qty_out, 0) - COALESCE(fd.qty_in, 0)), 0) AS sold_qty,
                    COALESCE(SUM(COALESCE(fd.det_value, 0)), 0) AS sold_value,
                    COUNT(DISTINCT oh.id) AS order_count
               FROM ot_head oh{$joins}
              WHERE " . implode(' AND ', $where) . '
              GROUP BY fd.item_id, item_name',
            $params
        );

        $items = [];
        foreach ($soldRows as $row) {
            $itemId = (int) ($row['item_id'] ?? 0);
            $items[$itemId] = [
                'item_id' => $itemId,
                'item_name' => (string) ($row['item_name'] ?? ('Item #' . $itemId)),
                'sold_qty' => (float) ($row['sold_qty'] ?? 0),
                'returned_qty' => 0.0,
                'net_qty' => (float) ($row['sold_qty'] ?? 0),
                'sold_value' => (float) ($row['sold_value'] ?? 0),
                'refund_value' => 0.0,
                'net_value' => (float) ($row['sold_value'] ?? 0),
                'order_count' => (int) ($row['order_count'] ?? 0),
            ];
        }

        foreach ($this->refundedItems($conn, $scope) as $row) {
            $itemId = (int) ($row['item_id'] ?? 0);
            if (!isset($items[$itemId])) {
                $items[$itemId] = [
                    'item_id' => $itemId,
                    'item_name' => (string) ($row['item_name'] ?? ('Item #' . $itemId)),
                    'sold_qty' => 0.0,
                    'returned_qty' => 0.0,
                    'net_qty' => 0.0,
                    'sold_value' => 0.0,
                    'refund_value' => 0.0,
                    'net_value' => 0.0,
                    'order_count' => 0,
                ];
            }
            $items[$itemId]['returned_qty'] += (float) ($row['returned_qty'] ?? 0);
            $items[$itemId]['refund_value'] += (float) ($row['refund_value'] ?? 0);
            $items[$itemId]['net_qty'] = $items[$itemId]['sold_qty'] - $items[$itemId]['returned_qty'];
            $items[$itemId]['net_value'] = $items[$itemId]['sold_value'] - $items[$itemId]['refund_value'];
        }

        $items = array_values($items);
        usort($items, static function (array $a, array $b): int {
            $qty = (float) $b['net_qty'] <=> (float) $a['net_qty'];
            return $qty !== 0 ? $qty : strcasecmp((string) $a['item_name'], (string) $b['item_name']);
        });

        return $items;
    }

    /** @return array<string, mixed> */
    public function paymentSummary(mysqli $conn, array $filters): array
    {
        return $this->cashFlow->paymentBreakdown($conn, $this->normalizeFilters($filters));
    }

    /** @return list<array<string, mixed>> */
    public function attention(
        mysqli $conn,
        array $filters,
        ?array $sales = null,
        ?array $payments = null,
        array $visibility = []
    ): array
    {
        $scope = $this->normalizeFilters($filters);
        $sales ??= $this->salesSummary($conn, $scope);
        $payments ??= $this->paymentSummary($conn, $scope);
        $includeCashControls = !empty($visibility['cash_controls']);
        $includeShiftControls = !empty($visibility['shift_controls']);
        $cash = $includeCashControls
            ? $this->cashFlow->summary($conn, $scope + ['include_unassigned' => true])
            : [];
        $pendingRefunds = $this->pendingExternalRefunds($conn, $scope);
        $openShifts = $includeShiftControls ? $this->openShiftCount($conn, $scope) : 0;
        $unresolvedShifts = $includeShiftControls ? $this->unresolvedShiftCount($conn, $scope) : 0;
        $cashDiff = (float) ($payments['cash_reconciliation_diff'] ?? 0);

        $rows = [];
        if ((float) ($sales['refunds'] ?? 0) > 0) {
            $rows[] = $this->attentionRow('refunds', 'review', 'Refunds processed', (int) ($sales['refund_count'] ?? 0), (float) $sales['refunds'], 'payments');
        }
        $reversals = $this->countOrdersByFocus($conn, $scope, 'order_cancelled');
        if ($reversals > 0) {
            $rows[] = $this->attentionRow('reversals', 'review', 'Voids and cancellations', $reversals, null, 'orders');
        }
        if ((int) ($sales['discounted_order_count'] ?? 0) > 0) {
            $rows[] = $this->attentionRow('discounts', 'info', 'Discounted orders', (int) $sales['discounted_order_count'], (float) ($sales['discounts'] ?? 0), 'orders');
        }
        if ($unresolvedShifts > 0) {
            $rows[] = $this->attentionRow('drawer_variance', 'critical', 'Unresolved drawer differences', $unresolvedShifts, null, 'shifts');
        }
        if ($openShifts > 0) {
            $rows[] = $this->attentionRow('open_shifts', 'review', 'Open shifts', $openShifts, null, 'shifts');
        }
        if ((int) ($cash['unassigned_count'] ?? 0) > 0) {
            $rows[] = $this->attentionRow('unassigned_cash', 'critical', 'Unassigned cash movements', (int) $cash['unassigned_count'], (float) ($cash['unassigned_total'] ?? 0), 'movements');
        }
        if ($pendingRefunds['count'] > 0) {
            $rows[] = $this->attentionRow('pending_refunds', 'critical', 'Pending external refunds', $pendingRefunds['count'], $pendingRefunds['total'], 'payments');
        }
        if ($includeCashControls && !empty($payments['cash_reconciliation_available']) && abs($cashDiff) >= 0.01) {
            $rows[] = $this->attentionRow('cash_mismatch', 'critical', 'Cash ledger and tender mismatch', 1, $cashDiff, 'payments');
        }

        return $rows;
    }

    /** @return array{total:float,count:int} */
    private function refundSummary(mysqli $conn, array $scope): array
    {
        $summary = $this->refunds->periodSummary($conn, $scope);
        return ['total' => (float) $summary['total_amount'], 'count' => $summary['count']];
    }

    /** @return list<array<string, mixed>> */
    private function refundedItems(mysqli $conn, array $scope): array
    {
        if (!$this->tableExists($conn, 'credit_notes')
            || !$this->tableExists($conn, 'credit_note_lines')
            || !$this->tableExists($conn, 'fat_details')) {
            return [];
        }
        $where = ["cn.status = 'posted'"];
        $params = [];
        if ($this->columnExists($conn, 'credit_notes', 'business_day')) {
            $where[] = 'COALESCE(cn.business_day, DATE(cn.created_at)) BETWEEN ? AND ?';
            $params[] = $scope['date_from'];
            $params[] = $scope['date_to'];
        } else {
            $bounds = $this->periodBounds($conn, $scope);
            $where[] = 'cn.created_at >= ?';
            $where[] = 'cn.created_at < ?';
            $params[] = $bounds['start_at'];
            $params[] = $bounds['end_at'];
        }
        $joinOrder = $this->tableExists($conn, 'ot_head');
        $this->appendRefundOwnershipScope($conn, $scope, $where, $params, $joinOrder);
        $hasItems = $this->tableExists($conn, 'myitems');
        $nameExpr = $hasItems ? "COALESCE(NULLIF(mi.iname, ''), CONCAT('Item #', fd.item_id))" : "CONCAT('Item #', fd.item_id)";

        return $this->queryAll(
            $conn,
            "SELECT fd.item_id, {$nameExpr} AS item_name,
                    COALESCE(SUM(cnl.quantity), 0) AS returned_qty,
                    COALESCE(SUM(cnl.line_amount), 0) AS refund_value
               FROM credit_notes cn
               JOIN credit_note_lines cnl ON cnl.credit_note_id = cn.id
               JOIN fat_details fd ON fd.id = cnl.original_detail_id"
                . ($joinOrder ? ' LEFT JOIN ot_head oh ON oh.id = cn.original_order_id' : '')
                . ($hasItems ? ' LEFT JOIN myitems mi ON mi.id = fd.item_id' : '')
                . ' WHERE ' . implode(' AND ', $where)
                . ' GROUP BY fd.item_id, item_name',
            $params
        );
    }

    /** @return array<int, list<array{code:string,label:string,type:string,amount:float}>> */
    private function paymentsForOrders(mysqli $conn, array $orderIds): array
    {
        if ($orderIds === [] || !$this->tableExists($conn, 'order_payments')) {
            return [];
        }
        $ids = implode(',', array_map('intval', array_values(array_unique($orderIds))));
        $hasMethods = $this->tableExists($conn, 'payment_methods');
        $voidFilter = $this->columnExists($conn, 'order_payments', 'is_voided') ? ' AND COALESCE(op.is_voided, 0) = 0' : '';
        $sql = "SELECT op.order_id, op.payment_method, op.amount"
            . ($hasMethods ? ", pm.type, COALESCE(NULLIF(pm.name_en, ''), NULLIF(pm.name_ar, ''), op.payment_method) AS method_label" : ", NULL AS type, op.payment_method AS method_label")
            . " FROM order_payments op"
            . ($hasMethods ? ' LEFT JOIN payment_methods pm ON pm.code = op.payment_method' : '')
            . " WHERE op.order_id IN ({$ids}){$voidFilter} ORDER BY op.id";
        $grouped = [];
        foreach ($this->queryAll($conn, $sql, []) as $row) {
            $grouped[(int) $row['order_id']][] = [
                'code' => (string) ($row['payment_method'] ?? ''),
                'label' => (string) (($row['method_label'] ?? '') ?: ($row['payment_method'] ?? 'Unknown')),
                'type' => (string) (($row['type'] ?? '') ?: $this->fallbackPaymentType((string) ($row['payment_method'] ?? ''))),
                'amount' => (float) ($row['amount'] ?? 0),
            ];
        }

        return $grouped;
    }

    /** @return array{voided:int,cancelled:int,amount:float} */
    private function eventCounts(mysqli $conn, array $scope): array
    {
        if (!$this->tableExists($conn, 'order_events')) {
            return ['voided' => 0, 'cancelled' => 0, 'amount' => 0.0];
        }
        $bounds = $this->periodBounds($conn, $scope);
        $where = ["oe.event_type IN ('order.voided', 'order.cancelled')", 'oe.created_at >= ?', 'oe.created_at < ?'];
        $params = [$bounds['start_at'], $bounds['end_at']];
        if ($scope['tenant'] > 0 && $this->columnExists($conn, 'order_events', 'tenant')) {
            $where[] = 'oe.tenant = ?';
            $params[] = $scope['tenant'];
        }
        if ($scope['branch'] > 0 && $this->columnExists($conn, 'order_events', 'branch')) {
            $where[] = 'oe.branch = ?';
            $params[] = $scope['branch'];
        }
        if ($scope['cashier_id'] > 0 && $this->columnExists($conn, 'order_events', 'actor_user_id')) {
            $where[] = 'oe.actor_user_id = ?';
            $params[] = $scope['cashier_id'];
        }
        $row = $this->queryOne(
            $conn,
            "SELECT SUM(CASE WHEN oe.event_type = 'order.voided' THEN 1 ELSE 0 END) AS voided,
                    SUM(CASE WHEN oe.event_type = 'order.cancelled' THEN 1 ELSE 0 END) AS cancelled
               FROM order_events oe WHERE " . implode(' AND ', $where),
            $params
        ) ?: [];

        return ['voided' => (int) ($row['voided'] ?? 0), 'cancelled' => (int) ($row['cancelled'] ?? 0), 'amount' => 0.0];
    }

    /** @return array{count:int,total:float} */
    private function pendingExternalRefunds(mysqli $conn, array $scope): array
    {
        if (!$this->tableExists($conn, 'payment_refunds')) {
            return ['count' => 0, 'total' => 0.0];
        }
        $bounds = $this->periodBounds($conn, $scope);
        $where = ["pr.status = 'pending_external'", 'pr.created_at >= ?', 'pr.created_at < ?'];
        $params = [$bounds['start_at'], $bounds['end_at']];
        $joins = '';
        if ($this->tableExists($conn, 'credit_notes') && $this->tableExists($conn, 'ot_head')) {
            $joins = ' LEFT JOIN credit_notes cn ON cn.id = pr.credit_note_id'
                . ' LEFT JOIN ot_head oh ON oh.id = cn.original_order_id';
            $this->appendOrderOwnershipScope($conn, $scope, $where, $params, 'oh');
        }
        if ($scope['cashier_id'] > 0 && $this->columnExists($conn, 'payment_refunds', 'created_by')) {
            $where[] = 'pr.created_by = ?';
            $params[] = $scope['cashier_id'];
        }
        $row = $this->queryOne(
            $conn,
            'SELECT COUNT(*) AS c, COALESCE(SUM(pr.amount), 0) AS total FROM payment_refunds pr'
                . $joins . ' WHERE ' . implode(' AND ', $where),
            $params
        ) ?: [];

        return ['count' => (int) ($row['c'] ?? 0), 'total' => (float) ($row['total'] ?? 0)];
    }

    private function openShiftCount(mysqli $conn, array $scope): int
    {
        if (!$this->tableExists($conn, 'drawer_sessions')) {
            return 0;
        }
        $where = ["status = 'open'"];
        $params = [];
        $this->appendSimpleScope($conn, 'drawer_sessions', $scope, $where, $params, 'user_id');
        $this->appendShiftDateScope($conn, $scope, $where, $params);
        $row = $this->queryOne($conn, 'SELECT COUNT(*) AS c FROM drawer_sessions WHERE ' . implode(' AND ', $where), $params) ?: [];
        return (int) ($row['c'] ?? 0);
    }

    private function unresolvedShiftCount(mysqli $conn, array $scope): int
    {
        if (!$this->tableExists($conn, 'drawer_sessions')) {
            return 0;
        }
        $where = ["variance_status IN ('counted_pending_review','unresolved')"];
        $params = [];
        $this->appendSimpleScope($conn, 'drawer_sessions', $scope, $where, $params, 'user_id');
        $this->appendShiftDateScope($conn, $scope, $where, $params);
        $row = $this->queryOne($conn, 'SELECT COUNT(*) AS c FROM drawer_sessions WHERE ' . implode(' AND ', $where), $params) ?: [];
        return (int) ($row['c'] ?? 0);
    }

    private function appendSimpleScope(mysqli $conn, string $table, array $scope, array &$where, array &$params, string $userColumn): void
    {
        foreach (['tenant', 'branch'] as $column) {
            if ($scope[$column] > 0 && $this->columnExists($conn, $table, $column)) {
                $where[] = "{$column} = ?";
                $params[] = $scope[$column];
            }
        }
        if ($scope['cashier_id'] > 0 && $this->columnExists($conn, $table, $userColumn)) {
            $where[] = "{$userColumn} = ?";
            $params[] = $scope['cashier_id'];
        }
    }

    private function appendShiftDateScope(mysqli $conn, array $scope, array &$where, array &$params): void
    {
        if ($this->columnExists($conn, 'drawer_sessions', 'business_day')) {
            $where[] = 'business_day >= ?';
            $where[] = 'business_day <= ?';
            $params[] = $scope['date_from'];
            $params[] = $scope['date_to'];
            return;
        }

        $bounds = $this->periodBounds($conn, $scope);
        $where[] = 'opened_at >= ?';
        $where[] = 'opened_at < ?';
        $params[] = $bounds['start_at'];
        $params[] = $bounds['end_at'];
    }

    /** @return array{0:list<string>,1:list<mixed>} */
    private function orderScope(mysqli $conn, array $scope, string $alias, bool $completedOnly): array
    {
        $where = ["{$alias}.pro_tybe = 9", "{$alias}.pro_date >= ?", "{$alias}.pro_date <= ?"];
        $params = [$scope['date_from'], $scope['date_to']];
        $this->appendOrderOwnershipScope($conn, $scope, $where, $params, $alias);
        if ($completedOnly) {
            $where[] = $this->refunds->originalSaleEvidencePredicate($conn, $alias);
        }

        return [$where, $params];
    }

    private function appendOrderFocus(mysqli $conn, array $scope, array &$where, array &$params, string $alias): void
    {
        $focus = (string) ($scope['focus'] ?? '');
        if ($focus === 'order_cancelled') {
            $cancelled = [];
            if ($this->columnExists($conn, 'ot_head', 'isdeleted')) {
                $cancelled[] = "COALESCE({$alias}.isdeleted, 0) = 1";
            }
            foreach (['payment_status', 'order_status'] as $column) {
                if ($this->columnExists($conn, 'ot_head', $column)) {
                    $cancelled[] = "LOWER(COALESCE({$alias}.{$column}, '')) IN ('cancelled', 'canceled', 'voided')";
                }
            }
            $where[] = $cancelled === [] ? '1 = 0' : '(' . implode(' OR ', $cancelled) . ')';
            return;
        }

        if ($focus === 'order_discounted') {
            $where[] = $this->columnExists($conn, 'ot_head', 'fat_disc')
                ? "COALESCE({$alias}.fat_disc, 0) > 0"
                : '1 = 0';
            if ($this->columnExists($conn, 'ot_head', 'isdeleted')) {
                $where[] = "COALESCE({$alias}.isdeleted, 0) = 0";
            }
            if ($this->columnExists($conn, 'ot_head', 'payment_status')) {
                $where[] = "{$alias}.payment_status IN ('paid', 'refunded')";
            }
        }
    }

    private function countOrdersByFocus(mysqli $conn, array $scope, string $focus): int
    {
        if (!$this->tableExists($conn, 'ot_head')) {
            return 0;
        }
        $scope['focus'] = $focus;
        [$where, $params] = $this->orderScope($conn, $scope, 'oh', false);
        $this->appendOrderFocus($conn, $scope, $where, $params, 'oh');
        $row = $this->queryOne(
            $conn,
            'SELECT COUNT(*) AS c FROM ot_head oh WHERE ' . implode(' AND ', $where),
            $params
        ) ?: [];

        return (int) ($row['c'] ?? 0);
    }

    private function appendOrderOwnershipScope(mysqli $conn, array $scope, array &$where, array &$params, string $alias): void
    {
        foreach (['tenant', 'branch'] as $column) {
            if ($scope[$column] > 0 && $this->columnExists($conn, 'ot_head', $column)) {
                $where[] = "{$alias}.{$column} = ?";
                $params[] = $scope[$column];
            }
        }
        if ($scope['cashier_id'] > 0 && $this->columnExists($conn, 'ot_head', 'user')) {
            $where[] = "{$alias}.user = ?";
            $params[] = $scope['cashier_id'];
        }
    }

    private function appendRefundOwnershipScope(
        mysqli $conn,
        array $scope,
        array &$where,
        array &$params,
        bool $joinOrder
    ): void {
        foreach (['tenant', 'branch'] as $column) {
            if ($scope[$column] < 1) {
                continue;
            }
            if ($this->columnExists($conn, 'credit_notes', $column)) {
                $where[] = "cn.{$column} = ?";
                $params[] = $scope[$column];
            } elseif ($joinOrder && $this->columnExists($conn, 'ot_head', $column)) {
                $where[] = "oh.{$column} = ?";
                $params[] = $scope[$column];
            }
        }
        if ($scope['cashier_id'] > 0 && $this->columnExists($conn, 'credit_notes', 'created_by')) {
            $where[] = 'cn.created_by = ?';
            $params[] = $scope['cashier_id'];
        }
    }

    /** @return array{date_from:string,date_to:string,tenant:int,branch:int,cashier_id:int,focus:string} */
    private function normalizeFilters(array $filters): array
    {
        $from = $this->date((string) ($filters['date_from'] ?? date('Y-m-d')));
        $to = $this->date((string) ($filters['date_to'] ?? $from));
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        return [
            'date_from' => $from,
            'date_to' => $to,
            'tenant' => max(0, (int) ($filters['tenant'] ?? 0)),
            'branch' => max(0, (int) ($filters['branch'] ?? 0)),
            'cashier_id' => max(0, (int) ($filters['cashier_id'] ?? 0)),
            'focus' => in_array((string) ($filters['focus'] ?? ''), ['order_cancelled', 'order_discounted'], true)
                ? (string) $filters['focus']
                : '',
        ];
    }

    /** @return array{start_at:string,end_at:string} */
    private function periodBounds(mysqli $conn, array $scope): array
    {
        $cutoff = $this->businessDays->cutoffHourForBranch($conn, $scope['tenant'], $scope['branch']);
        $from = $this->businessDays->windowBounds($scope['date_from'], $cutoff);
        $to = $this->businessDays->windowBounds($scope['date_to'], $cutoff);
        return ['start_at' => $from['start_at'], 'end_at' => $to['end_at']];
    }

    /** @return array<string, mixed> */
    private function attentionRow(string $key, string $severity, string $label, int $count, ?float $amount, string $tab): array
    {
        return compact('key', 'severity', 'label', 'count', 'amount', 'tab');
    }

    private function selectColumn(mysqli $conn, string $table, string $column, string $alias, ?string $as = null): string
    {
        $as ??= $column;
        return $this->columnExists($conn, $table, $column)
            ? "{$alias}.{$column} AS {$as}"
            : "NULL AS {$as}";
    }

    private function fallbackPaymentType(string $method): string
    {
        $method = strtolower(trim($method));
        return match ($method) {
            'cash', 'نقدي' => 'cash',
            'card', 'card_terminal', 'بطاقة' => 'card',
            'wallet', 'محفظة' => 'wallet',
            'bank', 'بنك' => 'bank',
            default => 'other',
        };
    }

    private function date(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : date('Y-m-d');
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        if (isset($this->tableCache[$table])) {
            return $this->tableCache[$table];
        }
        $escaped = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");
        return $this->tableCache[$table] = $result instanceof mysqli_result && $result->num_rows > 0;
    }

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (isset($this->columnCache[$key])) {
            return $this->columnCache[$key];
        }
        $escapedTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $escapedColumn = $conn->real_escape_string($column);
        $result = $conn->query("SHOW COLUMNS FROM `{$escapedTable}` LIKE '{$escapedColumn}'");
        return $this->columnCache[$key] = $result instanceof mysqli_result && $result->num_rows > 0;
    }

    /** @return array<string, mixed>|null */
    private function queryOne(mysqli $conn, string $sql, array $params): ?array
    {
        $rows = $this->queryAll($conn, $sql, $params);
        return $rows[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    private function queryAll(mysqli $conn, string $sql, array $params): array
    {
        $stmt = $conn->prepare($sql);
        if ($params !== []) {
            $types = '';
            foreach ($params as $param) {
                $types .= is_int($param) ? 'i' : (is_float($param) ? 'd' : 's');
            }
            $stmt->bind_param($types, ...$params);
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
