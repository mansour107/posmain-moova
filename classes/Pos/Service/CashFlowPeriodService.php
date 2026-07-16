<?php

require_once __DIR__ . '/DrawerSessionService.php';
require_once __DIR__ . '/PaymentMethodService.php';
require_once __DIR__ . '/ShiftDrawerReconciliationService.php';
require_once __DIR__ . '/BusinessDayService.php';
require_once __DIR__ . '/../../Financial/Money.php';

class CashFlowPeriodService
{
    private const MOVEMENT_TYPES = [
        'sale_cash',
        'refund_cash',
        'paid_in',
        'paid_out',
        'safe_drop',
        'opening',
        'closing_adjustment',
        'no_sale',
    ];

    private DrawerSessionService $drawerSessions;
    private PaymentMethodService $paymentMethods;
    private BusinessDayService $businessDays;
    /** @var array<string, string> */
    private array $userNameCache = [];
    /** @var array<string, array<string, int|string|null>> */
    private array $accountabilityCache = [];
    /** @var array<string, bool> */
    private array $tableExistsCache = [];
    /** @var array<string, bool> */
    private array $columnExistsCache = [];

    public function __construct(
        ?DrawerSessionService $drawerSessions = null,
        ?PaymentMethodService $paymentMethods = null,
        ?BusinessDayService $businessDays = null
    ) {
        $this->drawerSessions = $drawerSessions ?: new DrawerSessionService();
        $this->paymentMethods = $paymentMethods ?: new PaymentMethodService();
        $this->businessDays = $businessDays ?: new BusinessDayService();
    }

    public function drawerSubsystemAvailable(mysqli $conn): bool
    {
        return $this->tableExists($conn, 'drawer_sessions') && $this->tableExists($conn, 'drawer_movements');
    }

    /** @return array<string, int|string|null> */
    public function accountabilityForSession(mysqli $conn, int $sessionId): array
    {
        if ($sessionId < 1 || !$this->tableExists($conn, 'drawer_sessions')) {
            return [];
        }

        $session = $this->drawerSessions->sessionById($conn, $sessionId);
        $accountability = $this->sessionAccountability($conn, $session);
        $ownerId = (int) ($session['user_id'] ?? 0);

        return [
            'shift_owner_user_id' => $ownerId ?: null,
            'shift_owner_name' => $this->userName($conn, $ownerId),
        ] + $accountability;
    }

    public function summary(mysqli $conn, array $filters): array
    {
        if (!$this->drawerSubsystemAvailable($conn)) {
            return $this->legacySummary($conn, $filters);
        }

        $normalized = $this->normalizeFilters($filters);
        $totals = $this->zeroMovementTotals();
        $unassignedTotal = 0.0;
        $sessionCount = 0;
        $expectedRollup = 0.0;
        $countedRollup = 0.0;
        $closeVarianceRollup = 0.0;
        $pendingCountSessionCount = 0;
        $pendingCountExpectedCash = 0.0;

        foreach ($this->sessions($conn, $filters) as $session) {
            $sessionCount++;
            foreach ($session['movement_totals'] as $type => $amount) {
                if (array_key_exists($type, $totals)) {
                    $totals[$type] += (float) $amount;
                }
            }
            $expectedRollup += (float) ($session['expected_cash'] ?? 0);
            if (!empty($session['count_pending'])) {
                $pendingCountSessionCount++;
                $pendingCountExpectedCash += (float) ($session['expected_cash'] ?? 0);
            }
            if ($session['counted_cash'] !== null) {
                $countedRollup += (float) $session['counted_cash'];
            }
            if ($session['close_variance'] !== null) {
                $closeVarianceRollup += (float) $session['close_variance'];
            }
            if (array_key_exists('closing_adjustment', $totals) && $session['close_variance'] !== null) {
                $totals['closing_adjustment'] += (float) ($session['close_variance'] ?? 0);
            }
        }

        if ($sessionCount === 0) {
            foreach ($this->movementTotalsForPeriod($conn, $normalized) as $type => $amount) {
                if (array_key_exists($type, $totals)) {
                    $totals[$type] = (float) $amount;
                }
            }
        }

        $unassignedRows = $this->movements($conn, array_merge($filters, [
            'include_unassigned' => true,
            'only_unassigned' => true,
            'limit' => 10000,
            'offset' => 0,
        ]));
        foreach ($unassignedRows['rows'] as $row) {
            $type = (string) ($row['movement_type'] ?? '');
            $amount = (float) ($row['amount'] ?? 0);
            if ($type === 'sale_cash') {
                $unassignedTotal += $amount;
            } elseif ($type === 'refund_cash') {
                $unassignedTotal -= $amount;
            }
        }

        return [
            'source' => 'drawer',
            'date_from' => $normalized['date_from'],
            'date_to' => $normalized['date_to'],
            'tenant' => $normalized['tenant'],
            'branch' => $normalized['branch'],
            'session_count' => $sessionCount,
            'movement_totals' => $this->formatTotals($totals),
            'unassigned_total' => $this->formatDecimal($unassignedTotal),
            'unassigned_count' => (int) ($unassignedRows['total'] ?? 0),
            'unassigned_note' => (int) ($unassignedRows['total'] ?? 0) > 0
                ? 'Unassigned cash is physically in the drawer but excluded from per-session expected cash; it inflates close variance until linked.'
                : '',
            'expected_cash_rollup' => $this->formatDecimal($expectedRollup),
            'counted_cash_rollup' => $this->formatDecimal($countedRollup),
            'close_variance_rollup' => $this->formatDecimal($closeVarianceRollup),
            'difference_rollup' => $this->formatDecimal($closeVarianceRollup),
            'count_pending_session_count' => $pendingCountSessionCount,
            'count_pending_expected_cash' => $this->formatDecimal($pendingCountExpectedCash),
        ];
    }

    public function sessions(mysqli $conn, array $filters): array
    {
        if (!$this->drawerSubsystemAvailable($conn)) {
            return [];
        }

        $normalized = $this->normalizeFilters($filters);
        $joinUsers = $this->tableExists($conn, 'users');
        $joinSettings = $this->tableExists($conn, 'pos_branch_settings');
        $hasPersistedBusinessDay = $this->columnExists($conn, 'drawer_sessions', 'business_day');
        $settingsJoin = $joinSettings ? ' ' . $this->businessDays->branchSettingsJoin('ds') : '';
        $computedBusinessDayExpr = $joinSettings
            ? $this->businessDays->sessionBusinessDayExpression('ds')
            : 'DATE(ds.opened_at)';
        $businessDayExpr = $hasPersistedBusinessDay
            ? "COALESCE(ds.business_day, {$computedBusinessDayExpr})"
            : $computedBusinessDayExpr;
        $params = [$normalized['date_from'], $normalized['date_to']];
        $sql = $joinUsers
            ? "
            SELECT ds.*, u.uname, u.display_name
            FROM drawer_sessions ds{$settingsJoin}
            LEFT JOIN users u ON u.id = ds.user_id
            WHERE {$businessDayExpr} >= ?
              AND {$businessDayExpr} <= ?
        "
            : "
            SELECT ds.*
            FROM drawer_sessions ds{$settingsJoin}
            WHERE {$businessDayExpr} >= ?
              AND {$businessDayExpr} <= ?
        ";

        if ($normalized['tenant'] > 0) {
            $sql .= ' AND ds.tenant = ?';
            $params[] = $normalized['tenant'];
        }
        if ($normalized['branch'] > 0) {
            $sql .= ' AND ds.branch = ?';
            $params[] = $normalized['branch'];
        }
        if ($normalized['cashier_id'] > 0) {
            $sql .= ' AND ds.user_id = ?';
            $params[] = $normalized['cashier_id'];
        }
        if ($normalized['status'] !== '') {
            $sql .= ' AND ds.status = ?';
            $params[] = $normalized['status'];
        }
        if ($normalized['drawer_session_id'] > 0) {
            $sql .= ' AND ds.id = ?';
            $params[] = $normalized['drawer_session_id'];
        }

        $sql .= ' ORDER BY ds.opened_at DESC, ds.id DESC';

        $sessions = [];
        foreach ($this->queryAll($conn, $sql, $params) as $row) {
            $session = $this->drawerSessions->sessionById($conn, (int) $row['id']);
            $cutoffHour = $this->businessDays->cutoffHourForBranch(
                $conn,
                (int) $session['tenant'],
                (int) $session['branch']
            );
            $sessionBusinessDay = trim((string) ($session['business_day'] ?? ''));
            if ($sessionBusinessDay === '') {
                $sessionBusinessDay = $this->businessDays->businessDayForTimestamp(
                    (string) $session['opened_at'],
                    $cutoffHour
                );
            }
            $recon = (new ShiftDrawerReconciliationService())->buildForUser($conn, [
                'user_id' => (int) $session['user_id'],
                'tenant' => (int) $session['tenant'],
                'branch' => (int) $session['branch'],
                'date' => $sessionBusinessDay,
                'drawer_session_id' => (int) $session['id'],
            ]);
            $breakdown = $this->drawerSessions->sessionCashBreakdown($conn, (int) $session['id']);
            $accountability = $this->sessionAccountability($conn, $session);

            $sessions[] = [
                'id' => (int) $session['id'],
                'user_id' => (int) $session['user_id'],
                'user_name' => (string) (($row['display_name'] ?? '') ?: ($row['uname'] ?? '')),
                'tenant' => (int) $session['tenant'],
                'branch' => (int) $session['branch'],
                'business_day' => $sessionBusinessDay,
                'business_day_cutoff_hour' => $cutoffHour,
                'opened_at' => (string) $session['opened_at'],
                'closed_at' => $session['closed_at'],
                'status' => (string) $session['status'],
                'variance_status' => (string) ($session['variance_status'] ?? 'none'),
                'variance_type' => (string) ($session['variance_type'] ?? 'none'),
                'opening_cash' => (float) ($recon['drawer']['opening_cash'] ?? 0),
                'expected_cash' => (float) ($breakdown['pre_close_expected_cash'] ?? 0),
                'close_variance' => $breakdown['close_variance'] !== null
                    ? (float) $breakdown['close_variance']
                    : null,
                'counted_cash' => $breakdown['counted_cash'] !== null
                    ? (float) $breakdown['counted_cash']
                    : null,
                'difference' => $breakdown['close_variance'] !== null
                    ? (float) $breakdown['close_variance']
                    : null,
                'count_pending' => !empty($breakdown['count_pending']),
                'movement_totals' => array_map('floatval', $recon['drawer']['movement_totals'] ?? []),
                'movement_count' => (int) ($recon['drawer']['movement_count'] ?? 0),
                'shift_owner_user_id' => (int) $session['user_id'],
                'shift_owner_name' => (string) (($row['display_name'] ?? '') ?: ($row['uname'] ?? '')),
                'opened_by_user_id' => $accountability['opened_by_user_id'],
                'opened_by_name' => $accountability['opened_by_name'],
                'closed_by_user_id' => $accountability['closed_by_user_id'],
                'closed_by_name' => $accountability['closed_by_name'],
                'counted_by_user_id' => $accountability['counted_by_user_id'],
                'counted_by_name' => $accountability['counted_by_name'],
                'counted_at' => $accountability['counted_at'],
                'takeover_authorized_by_user_id' => $accountability['takeover_authorized_by_user_id'],
                'takeover_authorized_by_name' => $accountability['takeover_authorized_by_name'],
                'preceding_session_id' => $accountability['preceding_session_id'],
                'preceding_shift_owner_user_id' => $accountability['preceding_shift_owner_user_id'],
                'preceding_shift_owner_name' => $accountability['preceding_shift_owner_name'],
                'succeeding_session_id' => $accountability['succeeding_session_id'],
                'succeeding_shift_owner_user_id' => $accountability['succeeding_shift_owner_user_id'],
                'succeeding_shift_owner_name' => $accountability['succeeding_shift_owner_name'],
            ];
        }

        return $sessions;
    }

    public function movements(mysqli $conn, array $filters): array
    {
        if (!$this->drawerSubsystemAvailable($conn)) {
            return ['rows' => [], 'total' => 0, 'limit' => 0, 'offset' => 0];
        }

        $normalized = $this->normalizeFilters($filters);
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $onlyUnassigned = !empty($filters['only_unassigned']);
        $includeUnassigned = $onlyUnassigned || !empty($filters['include_unassigned']);

        $where = [];
        $params = [];
        if ($normalized['drawer_session_id'] > 0) {
            $where[] = 'dm.drawer_session_id = ?';
            $params[] = $normalized['drawer_session_id'];
        } else {
            $scope = $this->buildMovementScopeClause($conn, $normalized, $includeUnassigned, $onlyUnassigned, $filters);
            $where = array_merge($where, $scope['where']);
            $params = array_merge($params, $scope['params']);
        }

        if ($normalized['tenant'] > 0 && $this->columnExists($conn, 'drawer_movements', 'tenant')) {
            $where[] = 'dm.tenant = ?';
            $params[] = $normalized['tenant'];
        }
        if ($normalized['branch'] > 0 && $this->columnExists($conn, 'drawer_movements', 'branch')) {
            $where[] = 'dm.branch = ?';
            $params[] = $normalized['branch'];
        }
        if ($normalized['cashier_id'] > 0) {
            $where[] = 'dm.created_by = ?';
            $params[] = $normalized['cashier_id'];
        }
        if (!empty($filters['movement_type'])) {
            $where[] = 'dm.movement_type = ?';
            $params[] = (string) $filters['movement_type'];
        }

        if (!$where) {
            $where[] = '1 = 0';
        }

        $whereSql = implode(' AND ', $where);
        $joinSettings = $this->tableExists($conn, 'pos_branch_settings');
        $settingsJoin = $joinSettings ? ' ' . $this->businessDays->branchSettingsJoinForMovement('dm') : '';
        $countSql = "SELECT COUNT(*) AS c FROM drawer_movements dm{$settingsJoin} WHERE {$whereSql}";
        $countRow = $this->queryOne($conn, $countSql, $params);
        $total = (int) ($countRow['c'] ?? 0);

        $joinUsers = $this->tableExists($conn, 'users');
        $joinVouchers = $this->tableExists($conn, 'ot_head')
            && $this->columnExists($conn, 'drawer_movements', 'ref_ot_head_id');
        $joinApprovals = $this->columnExists($conn, 'drawer_movements', 'manager_approval_id')
            && $this->tableExists($conn, 'manager_approvals');
        $approvalHasApprovedBy = $joinApprovals && $this->columnExists($conn, 'manager_approvals', 'approved_by');
        $approvalHasPerformedBy = $joinApprovals && $this->columnExists($conn, 'manager_approvals', 'performed_by');
        $approvalHasPermission = $joinApprovals && $this->columnExists($conn, 'manager_approvals', 'permission_key');
        $approvalHasAction = $joinApprovals && $this->columnExists($conn, 'manager_approvals', 'action_type');
        $approvalHasStatus = $joinApprovals && $this->columnExists($conn, 'manager_approvals', 'status');
        $voucherProIdSelect = $joinVouchers && $this->columnExists($conn, 'ot_head', 'pro_id')
            ? ', oh.pro_id AS voucher_pro_id'
            : '';
        $voucherInfoSelect = $joinVouchers && $this->columnExists($conn, 'ot_head', 'info')
            ? ', oh.info AS voucher_info'
            : '';
        $approvalSelect = $joinApprovals ? ', ma.id AS approval_record_id' : '';
        $approvalSelect .= $approvalHasApprovedBy ? ', ma.approved_by AS approval_approved_by' : '';
        $approvalSelect .= $approvalHasPerformedBy ? ', ma.performed_by AS approval_performed_by' : '';
        $approvalSelect .= $approvalHasPermission ? ', ma.permission_key AS approval_permission_key' : '';
        $approvalSelect .= $approvalHasAction ? ', ma.action_type AS approval_action_type' : '';
        $approvalSelect .= $approvalHasStatus ? ', ma.status AS approval_status' : '';
        $approvalSelect .= $joinUsers && $approvalHasApprovedBy
            ? ', approver.uname AS approver_uname, approver.display_name AS approver_display_name'
            : '';
        $sql = "
            SELECT dm.*"
            . ($joinUsers ? ', u.uname, u.display_name' : '')
            . $voucherProIdSelect
            . $voucherInfoSelect
            . $approvalSelect
            . "
            FROM drawer_movements dm
            {$settingsJoin}
            " . ($joinUsers ? 'LEFT JOIN users u ON u.id = dm.created_by' : '') . "
            " . ($joinVouchers ? 'LEFT JOIN ot_head oh ON oh.id = dm.ref_ot_head_id' : '') . "
            " . ($joinApprovals ? 'LEFT JOIN manager_approvals ma ON ma.id = dm.manager_approval_id' : '') . "
            " . ($joinUsers && $approvalHasApprovedBy ? 'LEFT JOIN users approver ON approver.id = ma.approved_by' : '') . "
            WHERE {$whereSql}
            ORDER BY dm.created_at DESC, dm.id DESC
            LIMIT {$limit} OFFSET {$offset}
        ";

        $rows = [];
        foreach ($this->queryAll($conn, $sql, $params) as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'drawer_session_id' => $row['drawer_session_id'] !== null ? (int) $row['drawer_session_id'] : null,
                'movement_type' => (string) $row['movement_type'],
                'amount' => (float) $row['amount'],
                'order_id' => $row['order_id'] !== null ? (int) $row['order_id'] : null,
                'payment_id' => $row['payment_id'] !== null ? (int) $row['payment_id'] : null,
                'ref_ot_head_id' => isset($row['ref_ot_head_id'])
                    ? (int) $row['ref_ot_head_id']
                    : null,
                'voucher_pro_id' => isset($row['voucher_pro_id']) ? (int) $row['voucher_pro_id'] : null,
                'voucher_info' => isset($row['voucher_info']) ? (string) $row['voucher_info'] : null,
                'reason' => $row['reason'] !== null ? (string) $row['reason'] : null,
                'created_by' => (int) $row['created_by'],
                'created_by_name' => (string) (($row['display_name'] ?? '') ?: ($row['uname'] ?? '')),
                'manager_approval_id' => isset($row['manager_approval_id']) && $row['manager_approval_id'] !== null
                    ? (int) $row['manager_approval_id']
                    : null,
                'manager_approval_status' => isset($row['approval_status']) ? (string) $row['approval_status'] : null,
                'manager_approval_permission' => isset($row['approval_permission_key']) ? (string) $row['approval_permission_key'] : null,
                'manager_approval_action' => isset($row['approval_action_type']) ? (string) $row['approval_action_type'] : null,
                'manager_approved_by_user_id' => isset($row['approval_approved_by']) && $row['approval_approved_by'] !== null
                    ? (int) $row['approval_approved_by']
                    : null,
                'manager_approved_by_name' => (string) (($row['approver_display_name'] ?? '') ?: ($row['approver_uname'] ?? '')),
                'manager_approval_performed_by_user_id' => isset($row['approval_performed_by']) && $row['approval_performed_by'] !== null
                    ? (int) $row['approval_performed_by']
                    : null,
                'created_at' => (string) $row['created_at'],
                'is_unassigned' => $row['drawer_session_id'] === null,
                'tenant' => isset($row['tenant']) ? (int) $row['tenant'] : 0,
                'branch' => isset($row['branch']) ? (int) $row['branch'] : 0,
            ];
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    public function paymentBreakdown(mysqli $conn, array $filters): array
    {
        if (!$this->tableExists($conn, 'order_payments')) {
            return ['source' => 'none', 'by_type' => [], 'total' => '0.000', 'cash_net' => '0.000'];
        }

        $normalized = $this->normalizeFilters($filters);
        $methodTypes = $this->activePaymentMethodTypes($conn);
        $byType = array_fill_keys(['cash', 'card', 'wallet', 'bank', 'gift_card', 'other'], 0.0);

        $joinOrders = $this->tableExists($conn, 'ot_head');
        $paidAtExpr = 'op.created_at';
        if ($joinOrders) {
            $parts = ['op.created_at'];
            if ($this->columnExists($conn, 'ot_head', 'payment_date')) {
                $parts[] = 'oh.payment_date';
            }
            if ($this->columnExists($conn, 'ot_head', 'pro_date')) {
                $parts[] = "CONCAT(oh.pro_date, ' 12:00:00')";
            }
            $paidAtExpr = 'COALESCE(' . implode(', ', $parts) . ')';
        }

        $cutoffHour = $this->businessDays->cutoffHourForBranch(
            $conn,
            $normalized['tenant'],
            $normalized['branch']
        );
        $fromBounds = $this->businessDays->windowBounds($normalized['date_from'], $cutoffHour);
        $toBounds = $this->businessDays->windowBounds($normalized['date_to'], $cutoffHour);

        // Prefer stamped order business day (pro_date); fall back to payment timestamp window.
        $where = [];
        $params = [];
        if ($joinOrders && $this->columnExists($conn, 'ot_head', 'pro_date')) {
            $where[] = "(
                (oh.pro_date IS NOT NULL AND oh.pro_date >= ? AND oh.pro_date <= ?)
                OR (oh.pro_date IS NULL AND {$paidAtExpr} >= ? AND {$paidAtExpr} < ?)
            )";
            $params[] = $normalized['date_from'];
            $params[] = $normalized['date_to'];
            $params[] = $fromBounds['start_at'];
            $params[] = $toBounds['end_at'];
        } else {
            $where[] = "{$paidAtExpr} >= ? AND {$paidAtExpr} < ?";
            $params[] = $fromBounds['start_at'];
            $params[] = $toBounds['end_at'];
        }

        $sql = "
            SELECT op.payment_method, COALESCE(op.amount, 0) AS amount
            FROM order_payments op
            " . ($joinOrders ? 'LEFT JOIN ot_head oh ON oh.id = op.order_id' : '') . "
            WHERE " . implode(' AND ', $where) . "
        ";

        if ($normalized['cashier_id'] > 0) {
            if ($joinOrders && $this->columnExists($conn, 'ot_head', 'user')) {
                $sql .= ' AND (op.created_by = ? OR oh.user = ?)';
                $params[] = $normalized['cashier_id'];
                $params[] = (string) $normalized['cashier_id'];
            } else {
                $sql .= ' AND op.created_by = ?';
                $params[] = $normalized['cashier_id'];
            }
        }

        $total = 0.0;
        foreach ($this->queryAll($conn, $sql, $params) as $row) {
            $money = Money::fromLegacy($row['amount'] ?? 0);
            if (!$money->isPositive() && !$money->isNegative()) {
                continue;
            }
            $amount = (float) $money->toString();
            $method = trim((string) ($row['payment_method'] ?? ''));
            $type = $this->paymentTypeForMethod($method, $methodTypes);
            $byType[$type] = ($byType[$type] ?? 0) + $amount;
            $total += $amount;
        }

        $drawerCashNet = $this->drawerCashNetForPeriod($conn, $normalized);

        return [
            'source' => $this->drawerSubsystemAvailable($conn) && $this->drawerCoversPeriod($conn, $filters) ? 'drawer' : 'legacy',
            'by_type' => $this->formatTotals($byType),
            'total' => $this->formatDecimal($total),
            'cash_net' => $this->formatDecimal($byType['cash'] ?? 0),
            'drawer_cash_net' => $this->formatDecimal($drawerCashNet),
            'cash_reconciliation_diff' => $this->formatDecimal($drawerCashNet - (float) ($byType['cash'] ?? 0)),
        ];
    }

    private function drawerCashNetForPeriod(mysqli $conn, array $normalized): float
    {
        if (!$this->drawerSubsystemAvailable($conn)) {
            return 0.0;
        }

        $filters = [
            'date_from' => $normalized['date_from'],
            'date_to' => $normalized['date_to'],
            'tenant' => $normalized['tenant'],
            'branch' => $normalized['branch'],
            'cashier_id' => $normalized['cashier_id'],
            'include_unassigned' => true,
        ];
        $sale = 0.0;
        $refund = 0.0;
        foreach ($this->sessions($conn, $filters) as $session) {
            $sale += (float) ($session['movement_totals']['sale_cash'] ?? 0);
            $refund += (float) ($session['movement_totals']['refund_cash'] ?? 0);
        }

        $unassigned = $this->movements($conn, array_merge($filters, [
            'only_unassigned' => true,
            'limit' => 10000,
            'offset' => 0,
        ]));
        foreach ($unassigned['rows'] as $row) {
            if (($row['movement_type'] ?? '') === 'sale_cash') {
                $sale += (float) ($row['amount'] ?? 0);
            } elseif (($row['movement_type'] ?? '') === 'refund_cash') {
                $refund += (float) ($row['amount'] ?? 0);
            }
        }

        return round($sale - $refund, 3);
    }

    private function legacySummary(mysqli $conn, array $filters): array
    {
        $normalized = $this->normalizeFilters($filters);
        $cashReceipts = 0.0;
        $expenses = 0.0;

        if ($this->tableExists($conn, 'ot_head')) {
            $sql = "
                SELECT COALESCE(SUM(pro_value), 0) AS total
                FROM ot_head
                WHERE pro_tybe = 1
                  AND isdeleted = 0
                  AND pro_date >= ?
                  AND pro_date <= ?
            ";
            $params = [$normalized['date_from'], $normalized['date_to']];
            if ($normalized['cashier_id'] > 0) {
                $sql .= ' AND user = ?';
                $params[] = (string) $normalized['cashier_id'];
            }
            $cashReceipts = (float) ($this->queryOne($conn, $sql, $params)['total'] ?? 0);

            $sql = "
                SELECT COALESCE(SUM(pro_value), 0) AS total
                FROM ot_head
                WHERE pro_tybe = 2
                  AND isdeleted = 0
                  AND pro_date >= ?
                  AND pro_date <= ?
            ";
            $params = [$normalized['date_from'], $normalized['date_to']];
            if ($normalized['cashier_id'] > 0) {
                $sql .= ' AND user = ?';
                $params[] = (string) $normalized['cashier_id'];
            }
            $expenses = (float) ($this->queryOne($conn, $sql, $params)['total'] ?? 0);
        }

        $payments = $this->paymentBreakdown($conn, $filters);

        return [
            'source' => 'legacy',
            'date_from' => $normalized['date_from'],
            'date_to' => $normalized['date_to'],
            'tenant' => $normalized['tenant'],
            'branch' => $normalized['branch'],
            'session_count' => 0,
            'movement_totals' => $this->formatTotals([
                'sale_cash' => $cashReceipts,
                'paid_out' => $expenses,
            ]),
            'unassigned_total' => '0.000',
            'unassigned_count' => 0,
            'expected_cash_rollup' => '0.000',
            'counted_cash_rollup' => '0.000',
            'count_pending_session_count' => 0,
            'count_pending_expected_cash' => '0.000',
            'difference_rollup' => '0.000',
            'payment_breakdown' => $payments,
        ];
    }

    private function movementTotalsForPeriod(mysqli $conn, array $normalized): array
    {
        $totals = $this->zeroMovementTotals();
        $filters = [
            'date_from' => $normalized['date_from'],
            'date_to' => $normalized['date_to'],
            'tenant' => $normalized['tenant'],
            'branch' => $normalized['branch'],
            'cashier_id' => $normalized['cashier_id'],
            'include_unassigned' => true,
        ];

        foreach ($this->sessions($conn, $filters) as $session) {
            foreach ($session['movement_totals'] as $type => $amount) {
                if (array_key_exists($type, $totals)) {
                    $totals[$type] += (float) $amount;
                }
            }
            $totals['closing_adjustment'] += (float) ($session['close_variance'] ?? 0);
        }

        foreach ($this->movements($conn, array_merge($filters, [
            'only_unassigned' => true,
            'limit' => 10000,
            'offset' => 0,
        ]))['rows'] as $row) {
            $type = (string) ($row['movement_type'] ?? '');
            if (array_key_exists($type, $totals)) {
                $totals[$type] += (float) ($row['amount'] ?? 0);
            }
        }

        return $totals;
    }

    private function drawerCoversPeriod(mysqli $conn, array $filters): bool
    {
        return count($this->sessions($conn, $filters)) > 0;
    }

    private function buildMovementScopeClause(
        mysqli $conn,
        array $normalized,
        bool $includeUnassigned,
        bool $onlyUnassigned,
        array $filters
    ): array {
        $sessionIds = array_map(
            static fn(array $session): int => (int) $session['id'],
            $this->sessions($conn, [
                'date_from' => $normalized['date_from'],
                'date_to' => $normalized['date_to'],
                'tenant' => $normalized['tenant'],
                'branch' => $normalized['branch'],
                'cashier_id' => $normalized['cashier_id'],
                'status' => $normalized['status'],
            ])
        );

        if ($onlyUnassigned) {
            return $this->unassignedBusinessDayClause($conn, $normalized);
        }

        $joinSettings = $this->tableExists($conn, 'pos_branch_settings');
        $businessDayExpr = $joinSettings
            ? $this->businessDays->movementBusinessDayExpression('dm')
            : 'DATE(dm.created_at)';
        $sessionClause = '';
        $params = [];

        if ($sessionIds) {
            $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
            $sessionClause = "dm.drawer_session_id IN ({$placeholders})";
            foreach ($sessionIds as $sessionId) {
                $params[] = $sessionId;
            }
        }

        if ($includeUnassigned) {
            $unassignedParts = [
                'dm.drawer_session_id IS NULL',
                "{$businessDayExpr} >= ?",
                "{$businessDayExpr} <= ?",
            ];
            $unassignedParams = [$normalized['date_from'], $normalized['date_to']];
            $unassignedClause = '(' . implode(' AND ', $unassignedParts) . ')';
            if ($sessionClause !== '') {
                return [
                    'where' => ["(({$sessionClause}) OR {$unassignedClause})"],
                    'params' => array_merge($params, $unassignedParams),
                ];
            }

            return [
                'where' => [$unassignedClause],
                'params' => $unassignedParams,
            ];
        }

        if ($sessionClause === '') {
            return ['where' => ['1 = 0'], 'params' => []];
        }

        return ['where' => [$sessionClause], 'params' => $params];
    }

    private function unassignedBusinessDayClause(mysqli $conn, array $normalized): array
    {
        $joinSettings = $this->tableExists($conn, 'pos_branch_settings');
        $businessDayExpr = $joinSettings
            ? $this->businessDays->movementBusinessDayExpression('dm')
            : 'DATE(dm.created_at)';

        return [
            'where' => [
                'dm.drawer_session_id IS NULL',
                "{$businessDayExpr} >= ?",
                "{$businessDayExpr} <= ?",
            ],
            'params' => [$normalized['date_from'], $normalized['date_to']],
        ];
    }

    private function normalizeFilters(array $filters): array
    {
        $dateFrom = trim((string) ($filters['date_from'] ?? date('Y-m-d')));
        $dateTo = trim((string) ($filters['date_to'] ?? $dateFrom));
        if (strtotime($dateFrom) === false) {
            throw new InvalidArgumentException('DATE_FROM_INVALID');
        }
        if (strtotime($dateTo) === false) {
            throw new InvalidArgumentException('DATE_TO_INVALID');
        }
        if ($dateFrom > $dateTo) {
            throw new InvalidArgumentException('DATE_RANGE_INVALID');
        }

        return [
            'date_from' => date('Y-m-d', strtotime($dateFrom)),
            'date_to' => date('Y-m-d', strtotime($dateTo)),
            'tenant' => max(0, (int) ($filters['tenant'] ?? $filters['pos_tenant'] ?? 0)),
            'branch' => max(0, (int) ($filters['branch'] ?? $filters['pos_branch'] ?? 0)),
            'cashier_id' => max(0, (int) ($filters['cashier_id'] ?? 0)),
            'drawer_session_id' => max(0, (int) ($filters['drawer_session_id'] ?? 0)),
            'status' => trim((string) ($filters['status'] ?? '')),
        ];
    }

    private function zeroMovementTotals(): array
    {
        $totals = [];
        foreach (self::MOVEMENT_TYPES as $type) {
            $totals[$type] = 0.0;
        }

        return $totals;
    }

    private function formatTotals(array $totals): array
    {
        $formatted = [];
        foreach ($totals as $key => $value) {
            $formatted[$key] = $this->formatDecimal((float) $value);
        }

        return $formatted;
    }

    private function formatDecimal(float $value): string
    {
        return number_format($value, 3, '.', '');
    }

    private function activePaymentMethodTypes(mysqli $conn): array
    {
        if (!$this->tableExists($conn, 'payment_methods')) {
            return [];
        }

        $types = [];
        foreach ($this->paymentMethods->listActive($conn) as $method) {
            $types[(string) $method['code']] = (string) $method['type'];
        }

        return $types;
    }

    private function paymentTypeForMethod(string $method, array $methodTypes): string
    {
        try {
            $code = $this->paymentMethods->normalizeCode($method);
            if (isset($methodTypes[$code])) {
                return $methodTypes[$code];
            }
        } catch (Throwable $exception) {
            // legacy string methods
        }

        return strtolower(trim($method)) === 'cash' ? 'cash' : 'other';
    }

    /**
     * Build the custody trail for one drawer session without requiring every
     * optional handover column to exist on legacy installations.
     *
     * @return array<string, int|string|null>
     */
    private function sessionAccountability(mysqli $conn, array $session): array
    {
        $sessionId = (int) ($session['id'] ?? 0);
        $cacheKey = spl_object_id($conn) . ':' . $sessionId;
        if ($sessionId > 0 && isset($this->accountabilityCache[$cacheKey])) {
            return $this->accountabilityCache[$cacheKey];
        }
        $openedBy = (int) ($session['opened_by'] ?? $session['user_id'] ?? 0);
        $closedBy = isset($session['closed_by']) && $session['closed_by'] !== null
            ? (int) $session['closed_by']
            : null;
        $precedingSessionId = isset($session['preceding_session_id']) && $session['preceding_session_id'] !== null
            ? (int) $session['preceding_session_id']
            : null;
        $authorizedBy = isset($session['takeover_authorized_by']) && $session['takeover_authorized_by'] !== null
            ? (int) $session['takeover_authorized_by']
            : null;

        $counter = null;
        if ($sessionId > 0 && $this->tableExists($conn, 'drawer_count_attempts')) {
            $counter = $this->queryOne($conn, '
                SELECT created_by, created_at
                FROM drawer_count_attempts
                WHERE drawer_session_id = ? AND count_phase = ?
                ORDER BY attempt_number DESC, id DESC
                LIMIT 1
            ', [$sessionId, 'close']);
        }

        $preceding = null;
        $succeeding = null;
        if ($this->columnExists($conn, 'drawer_sessions', 'preceding_session_id')) {
            if ($precedingSessionId !== null) {
                $preceding = $this->queryOne(
                    $conn,
                    'SELECT id, user_id FROM drawer_sessions WHERE id = ? LIMIT 1',
                    [$precedingSessionId]
                );
            }
            if ($sessionId > 0) {
                $successorSelect = $this->columnExists($conn, 'drawer_sessions', 'takeover_authorized_by')
                    ? 'id, user_id, takeover_authorized_by'
                    : 'id, user_id';
                $succeeding = $this->queryOne(
                    $conn,
                    "SELECT {$successorSelect} FROM drawer_sessions WHERE preceding_session_id = ? ORDER BY id ASC LIMIT 1",
                    [$sessionId]
                );
            }
        }

        // The authorization is persisted on the newly opened takeover session.
        // Surface it on the closed predecessor too so the custody story is visible
        // from either session in the admin report.
        if ($authorizedBy === null && isset($succeeding['takeover_authorized_by'])) {
            $authorizedBy = $succeeding['takeover_authorized_by'] !== null
                ? (int) $succeeding['takeover_authorized_by']
                : null;
        }

        $countedBy = isset($counter['created_by']) ? (int) $counter['created_by'] : null;
        $precedingOwnerId = isset($preceding['user_id']) ? (int) $preceding['user_id'] : null;
        $succeedingOwnerId = isset($succeeding['user_id']) ? (int) $succeeding['user_id'] : null;

        $accountability = [
            'opened_by_user_id' => $openedBy > 0 ? $openedBy : null,
            'opened_by_name' => $this->userName($conn, $openedBy),
            'closed_by_user_id' => $closedBy,
            'closed_by_name' => $this->userName($conn, (int) $closedBy),
            'counted_by_user_id' => $countedBy,
            'counted_by_name' => $this->userName($conn, (int) $countedBy),
            'counted_at' => isset($counter['created_at']) ? (string) $counter['created_at'] : null,
            'takeover_authorized_by_user_id' => $authorizedBy,
            'takeover_authorized_by_name' => $this->userName($conn, (int) $authorizedBy),
            'preceding_session_id' => $precedingSessionId,
            'preceding_shift_owner_user_id' => $precedingOwnerId,
            'preceding_shift_owner_name' => $this->userName($conn, (int) $precedingOwnerId),
            'succeeding_session_id' => isset($succeeding['id']) ? (int) $succeeding['id'] : null,
            'succeeding_shift_owner_user_id' => $succeedingOwnerId,
            'succeeding_shift_owner_name' => $this->userName($conn, (int) $succeedingOwnerId),
        ];
        if ($sessionId > 0) {
            $this->accountabilityCache[$cacheKey] = $accountability;
        }

        return $accountability;
    }

    private function userName(mysqli $conn, int $userId): string
    {
        if ($userId < 1 || !$this->tableExists($conn, 'users')) {
            return '';
        }
        $cacheKey = spl_object_id($conn) . ':' . $userId;
        if (array_key_exists($cacheKey, $this->userNameCache)) {
            return $this->userNameCache[$cacheKey];
        }

        $row = $this->queryOne(
            $conn,
            'SELECT uname, display_name FROM users WHERE id = ? LIMIT 1',
            [$userId]
        );
        $name = (string) (($row['display_name'] ?? '') ?: ($row['uname'] ?? ''));
        $this->userNameCache[$cacheKey] = $name;

        return $name;
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $cacheKey = spl_object_id($conn) . ':' . $table;
        if (array_key_exists($cacheKey, $this->tableExistsCache)) {
            return $this->tableExistsCache[$cacheKey];
        }
        $escapedTable = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$escapedTable}'");

        return $this->tableExistsCache[$cacheKey] = $result instanceof mysqli_result && $result->num_rows > 0;
    }

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $cacheKey = spl_object_id($conn) . ':' . $table . ':' . $column;
        if (array_key_exists($cacheKey, $this->columnExistsCache)) {
            return $this->columnExistsCache[$cacheKey];
        }
        $escapedTable = $conn->real_escape_string($table);
        $escapedColumn = $conn->real_escape_string($column);
        $result = $conn->query("SHOW COLUMNS FROM `{$escapedTable}` LIKE '{$escapedColumn}'");

        return $this->columnExistsCache[$cacheKey] = $result instanceof mysqli_result && $result->num_rows > 0;
    }

    private function queryOne(mysqli $conn, string $sql, array $params): ?array
    {
        $rows = $this->queryAll($conn, $sql, $params);

        return $rows ? $rows[0] : null;
    }

    private function queryAll(mysqli $conn, string $sql, array $params): array
    {
        $stmt = $conn->prepare($sql);
        if (!$params) {
            $stmt->execute();
        } else {
            $this->bindParams($stmt, $params);
            $stmt->execute();
        }
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    /**
     * Security audit rows correlated to override periods for a drawer session.
     *
     * @return list<array{event_type:string,user_id:?int,created_at:?string,summary:string,metadata:array}>
     */
    public function overrideAuditEvents(mysqli $conn, int $drawerSessionId): array
    {
        if ($drawerSessionId < 1 || !$this->tableExists($conn, 'security_audit_log')) {
            return [];
        }

        $eventTypes = [
            'drawer_override_started',
            'drawer_override_operation',
            'drawer_override_ended',
            'drawer_override_expired',
            'drawer_override_denied',
        ];
        $placeholders = implode(',', array_fill(0, count($eventTypes), '?'));
        // LIKE narrows in SQL so the LIMIT is a cap, not the selection; the exact
        // drawer_session_id check below removes prefix false-positives (460 vs 4601).
        $sql = "
            SELECT event_type, user_id, created_at, metadata_json
              FROM security_audit_log
             WHERE event_type IN ({$placeholders})
               AND metadata_json LIKE ?
             ORDER BY created_at DESC, id DESC
             LIMIT 500
        ";
        $stmt = $conn->prepare($sql);
        $params = $eventTypes;
        $params[] = '%"drawer_session_id":' . $drawerSessionId . '%';
        $types = str_repeat('s', count($params));
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $events = [];
        foreach ($rows ?: [] as $row) {
            $metadata = [];
            $raw = (string) ($row['metadata_json'] ?? '');
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $metadata = $decoded;
                }
            }
            if ((int) ($metadata['drawer_session_id'] ?? 0) !== $drawerSessionId) {
                continue;
            }
            $summaryParts = [];
            if (array_key_exists('success', $metadata)) {
                $summaryParts[] = !empty($metadata['success']) ? 'نجحت' : 'مرفوضة';
            }
            if (!empty($metadata['reason'])) {
                $summaryParts[] = (string) $metadata['reason'];
            }
            if (!empty($metadata['end_reason'])) {
                $summaryParts[] = 'سبب الإنهاء: ' . $metadata['end_reason'];
            }
            if (!empty($metadata['override_period_id'])) {
                $summaryParts[] = 'فترة #' . (int) $metadata['override_period_id'];
            }
            if (!empty($metadata['route'])) {
                $summaryParts[] = (string) $metadata['route'];
            }
            $events[] = [
                'event_type' => (string) ($row['event_type'] ?? ''),
                'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
                'created_at' => $row['created_at'] !== null ? (string) $row['created_at'] : null,
                'summary' => implode(' · ', $summaryParts),
                'metadata' => $metadata,
            ];
        }

        return $events;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function overridePeriods(mysqli $conn, array $filters = []): array
    {
        require_once __DIR__ . '/DrawerOverrideService.php';

        return (new DrawerOverrideService())->listPeriods($conn, $filters);
    }

    private function bindParams(mysqli_stmt $stmt, array $params): void
    {
        $types = '';
        $refs = [];
        foreach ($params as $key => $value) {
            if (is_int($value)) {
                $types .= 'i';
            } elseif (is_float($value)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
            $refs[$key] = $params[$key];
        }

        $bindValues = [$types];
        foreach ($refs as $key => $value) {
            $bindValues[] = &$refs[$key];
        }

        call_user_func_array([$stmt, 'bind_param'], $bindValues);
    }
}
