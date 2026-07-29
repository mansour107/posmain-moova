<?php

require_once __DIR__ . '/DrawerSessionService.php';
require_once __DIR__ . '/PaymentMethodService.php';
require_once __DIR__ . '/BusinessDayService.php';
require_once dirname(__DIR__) . '/Value/CashAmount.php';

class ShiftDrawerReconciliationService
{
    private const MOVEMENT_SIGNS = [
        'sale_cash' => 1,
        'refund_cash' => -1,
        'paid_in' => 1,
        'paid_out' => -1,
        'safe_drop' => -1,
        'opening' => 1,
        'closing_adjustment' => 1,
        'no_sale' => 0,
    ];

    private const PAYMENT_TYPES = ['cash', 'card', 'wallet', 'bank', 'gift_card', 'other'];

    private $drawerSessionService;
    private $paymentMethodService;

    public function __construct(?DrawerSessionService $drawerSessionService = null, ?PaymentMethodService $paymentMethodService = null)
    {
        $this->drawerSessionService = $drawerSessionService ?: new DrawerSessionService();
        $this->paymentMethodService = $paymentMethodService ?: new PaymentMethodService();
    }

    public function buildForUser(mysqli $conn, array $scope): array
    {
        $userId = $this->positiveInt($scope['user_id'] ?? 0, 'USER_ID_REQUIRED');
        $tenant = $this->nonNegativeInt($scope['tenant'] ?? $scope['pos_tenant'] ?? 0);
        $branch = $this->nonNegativeInt($scope['branch'] ?? $scope['pos_branch'] ?? 0);
        $session = $this->resolveDrawerSession($conn, $scope, $userId, $tenant, $branch);
        $openedAt = $session['opened_at'] ?? null;
        $businessDays = new BusinessDayService();
        $date = trim((string) ($scope['date'] ?? ''));
        if ($date === '' && $session) {
            $date = trim((string) ($session['business_day'] ?? ''));
            if ($date === '' && !empty($session['opened_at'])) {
                $cutoffHour = $businessDays->cutoffHourForBranch(
                    $conn,
                    (int) ($session['tenant'] ?? $tenant),
                    (int) ($session['branch'] ?? $branch)
                );
                $date = $businessDays->businessDayForTimestamp((string) $session['opened_at'], $cutoffHour);
            }
        }
        if ($date === '') {
            $date = $businessDays->currentBusinessDayForBranch($conn, $tenant, $branch);
        } else {
            $date = $this->date($date);
        }

        $payments = $this->paymentSummary(
            $conn,
            $userId,
            $date,
            $openedAt,
            $tenant,
            $branch,
            (int) ($session['id'] ?? 0)
        );
        $drawer = $this->drawerSummary($conn, $session);
        $cashPayments = CashAmount::normalize($payments['cash_net'] ?? $payments['cash'] ?? '0.00', true);
        $drawerCash = CashAmount::subtract(
            $drawer['movement_totals']['sale_cash'] ?? '0.00',
            $drawer['movement_totals']['refund_cash'] ?? '0.00'
        );

        return [
            'user_id' => $userId,
            'tenant' => $tenant,
            'branch' => $branch,
            'date' => $date,
            'drawer_session' => $session,
            'drawer' => $drawer,
            'payments' => $payments,
            'reconciliation' => [
                'cash_payments' => $this->decimal($cashPayments),
                'drawer_sale_cash' => $this->decimal($drawer['movement_totals']['sale_cash'] ?? '0.00'),
                'drawer_refund_cash' => $this->decimal($drawer['movement_totals']['refund_cash'] ?? '0.00'),
                'drawer_cash_net' => $this->decimal($drawerCash),
                'cash_difference' => $this->decimal(CashAmount::subtract($drawerCash, $cashPayments)),
                'pre_close_expected_cash' => $drawer['pre_close_expected_cash'],
                'close_variance' => $drawer['close_variance'],
                'expected_cash' => $drawer['post_close_expected_cash'],
                'counted_cash' => $drawer['counted_cash'],
            ],
        ];
    }

    private function paymentSummary(
        mysqli $conn,
        int $userId,
        string $date,
        ?string $openedAt,
        int $tenant = 0,
        int $branch = 0,
        int $drawerSessionId = 0
    ): array {
        $summary = [
            'total' => '0.000',
            'cash' => '0.000',
            'non_cash' => '0.000',
            'by_type' => $this->zeroTypes(),
            'refund_total' => '0.000',
            'settled_refund_total' => '0.000',
            'pending_external_refund_total' => '0.000',
            'refunds_by_type' => $this->zeroTypes(),
            'settled_refunds_by_type' => $this->zeroTypes(),
            'net_total' => '0.000',
            'cash_net' => '0.000',
            'non_cash_net' => '0.000',
            'net_by_type' => $this->zeroTypes(),
            'methods' => [],
        ];
        if (!$this->tableExists($conn, 'order_payments')) {
            return $summary;
        }

        $methodTypes = $this->activePaymentMethodTypes($conn);
        $businessDays = new BusinessDayService();
        $cutoffHour = $businessDays->cutoffHourForBranch($conn, $tenant, $branch);
        $bounds = $businessDays->windowBounds($date, $cutoffHour);
        $paidAtExpr = "COALESCE(op.created_at, oh.payment_date, CONCAT(oh.pro_date, ' 12:00:00'))";
        $sql = "
            SELECT
                op.payment_method,
                COALESCE(op.amount, 0) AS amount,
                {$paidAtExpr} AS paid_at
            FROM order_payments op
            LEFT JOIN ot_head oh ON oh.id = op.order_id
            WHERE (op.created_by = ? OR oh.user = ?)
              AND (
                    (oh.pro_date IS NOT NULL AND oh.pro_date = ?)
                    OR (oh.pro_date IS NULL AND {$paidAtExpr} >= ? AND {$paidAtExpr} < ?)
              )
        ";
        $params = [$userId, $userId, $date, $bounds['start_at'], $bounds['end_at']];
        if ($openedAt !== null && trim($openedAt) !== '') {
            $sql .= " AND {$paidAtExpr} >= ?";
            $params[] = $openedAt;
        }
        if ($this->columnExists($conn, 'order_payments', 'is_voided')) {
            $sql .= ' AND COALESCE(op.is_voided, 0) = 0';
        }
        if ($this->tableExists($conn, 'ot_head')) {
            if ($tenant > 0 && $this->columnExists($conn, 'ot_head', 'tenant')) {
                $sql .= ' AND oh.tenant = ?';
                $params[] = $tenant;
            }
            if ($branch > 0 && $this->columnExists($conn, 'ot_head', 'branch')) {
                $sql .= ' AND oh.branch = ?';
                $params[] = $branch;
            }
        }
        $sql .= " ORDER BY paid_at, op.id";

        foreach ($this->queryAll($conn, $sql, $params) as $row) {
            $amount = CashAmount::normalize($row['amount'] ?? '0.00', true);
            if (CashAmount::compare($amount, '0.00') <= 0) {
                continue;
            }

            $method = trim((string) ($row['payment_method'] ?? ''));
            $type = $this->paymentTypeForMethod($method, $methodTypes);
            $summary['by_type'][$type] = $this->decimal(CashAmount::add($summary['by_type'][$type], $amount));
            $summary['total'] = $this->decimal(CashAmount::add($summary['total'], $amount));
            if (!isset($summary['methods'][$method])) {
                $summary['methods'][$method] = [
                    'payment_method' => $method,
                    'type' => $type,
                    'total' => '0.000',
                    'refunded' => '0.000',
                    'settled_refunded' => '0.000',
                    'net' => '0.000',
                    'count' => 0,
                ];
            }
            $summary['methods'][$method]['total'] = $this->decimal(
                CashAmount::add($summary['methods'][$method]['total'], $amount)
            );
            $summary['methods'][$method]['count']++;
        }

        $summary['cash'] = $summary['by_type']['cash'];
        $summary['non_cash'] = $this->decimal(CashAmount::subtract($summary['total'], $summary['cash']));

        if ($this->tableExists($conn, 'payment_refunds')) {
            $businessDays = new BusinessDayService();
            $cutoffHour = $businessDays->cutoffHourForBranch($conn, $tenant, $branch);
            $bounds = $businessDays->windowBounds($date, $cutoffHour);
            $joinMethods = $this->tableExists($conn, 'payment_methods');
            $joinCreditNotes = $this->tableExists($conn, 'credit_notes');
            $joinOrders = $this->tableExists($conn, 'ot_head');
            $refundSql = 'SELECT pr.amount, pr.payment_method_id'
                . ($this->columnExists($conn, 'payment_refunds', 'status') ? ', pr.status' : ", 'posted' AS status")
                . ($joinMethods ? ', pm.code AS method_code, pm.type AS method_type' : ", '' AS method_code, '' AS method_type")
                . ' FROM payment_refunds pr'
                . ($joinMethods ? ' LEFT JOIN payment_methods pm ON pm.id = pr.payment_method_id' : '')
                . ($joinCreditNotes ? ' LEFT JOIN credit_notes cn ON cn.id = pr.credit_note_id' : '')
                . ($joinOrders ? ' LEFT JOIN ot_head roh ON roh.id = pr.original_order_id' : '')
                . ' WHERE pr.created_at >= ? AND pr.created_at < ?';
            $refundParams = [$bounds['start_at'], $bounds['end_at']];

            if ($openedAt !== null && trim($openedAt) !== '') {
                $refundSql .= ' AND pr.created_at >= ?';
                $refundParams[] = $openedAt;
            }
            $scopedByDrawerSession = $joinCreditNotes && $drawerSessionId > 0
                && $this->columnExists($conn, 'credit_notes', 'drawer_session_id');
            if ($scopedByDrawerSession) {
                $refundSql .= ' AND cn.drawer_session_id = ?';
                $refundParams[] = $drawerSessionId;
            } elseif ($this->columnExists($conn, 'payment_refunds', 'created_by')) {
                // With no durable drawer-session link, retain the legacy
                // operator/date scope. When a drawer link exists it is the
                // financial boundary: an authorized manager may perform the
                // refund while custody still belongs to the cashier's drawer.
                $refundSql .= ' AND pr.created_by = ?';
                $refundParams[] = $userId;
            }
            if ($tenant > 0) {
                if ($joinCreditNotes && $this->columnExists($conn, 'credit_notes', 'tenant')) {
                    $refundSql .= ' AND cn.tenant = ?';
                    $refundParams[] = $tenant;
                } elseif ($joinOrders && $this->columnExists($conn, 'ot_head', 'tenant')) {
                    $refundSql .= ' AND roh.tenant = ?';
                    $refundParams[] = $tenant;
                }
            }
            if ($branch > 0) {
                if ($joinCreditNotes && $this->columnExists($conn, 'credit_notes', 'branch')) {
                    $refundSql .= ' AND cn.branch = ?';
                    $refundParams[] = $branch;
                } elseif ($joinOrders && $this->columnExists($conn, 'ot_head', 'branch')) {
                    $refundSql .= ' AND roh.branch = ?';
                    $refundParams[] = $branch;
                }
            }
            if ($joinCreditNotes && $this->columnExists($conn, 'credit_notes', 'status')) {
                $refundSql .= " AND cn.status = 'posted'";
            }
            $refundSql .= ' ORDER BY pr.created_at, pr.id';

            foreach ($this->queryAll($conn, $refundSql, $refundParams) as $row) {
                $amount = CashAmount::normalize($row['amount'] ?? '0.00', true);
                if (CashAmount::compare($amount, '0.00') <= 0) {
                    continue;
                }
                $method = trim((string) ($row['method_code'] ?? ''));
                $type = trim((string) ($row['method_type'] ?? ''));
                if (!in_array($type, self::PAYMENT_TYPES, true)) {
                    $type = $this->paymentTypeForMethod($method, $methodTypes);
                }
                $status = (string) ($row['status'] ?? 'posted');
                $settled = $status === 'settled' || ($status === 'posted' && $type === 'cash');

                $summary['refund_total'] = $this->decimal(
                    CashAmount::add($summary['refund_total'], $amount)
                );
                $summary['refunds_by_type'][$type] = $this->decimal(
                    CashAmount::add($summary['refunds_by_type'][$type], $amount)
                );
                if ($status === 'pending_external') {
                    $summary['pending_external_refund_total'] = $this->decimal(
                        CashAmount::add($summary['pending_external_refund_total'], $amount)
                    );
                }
                if ($settled) {
                    $summary['settled_refund_total'] = $this->decimal(
                        CashAmount::add($summary['settled_refund_total'], $amount)
                    );
                    $summary['settled_refunds_by_type'][$type] = $this->decimal(
                        CashAmount::add($summary['settled_refunds_by_type'][$type], $amount)
                    );
                }

                $methodKey = $method !== '' ? $method : 'method_' . (int) ($row['payment_method_id'] ?? 0);
                if (!isset($summary['methods'][$methodKey])) {
                    $summary['methods'][$methodKey] = [
                        'payment_method' => $methodKey,
                        'type' => $type,
                        'total' => '0.000',
                        'refunded' => '0.000',
                        'settled_refunded' => '0.000',
                        'net' => '0.000',
                        'count' => 0,
                    ];
                }
                $summary['methods'][$methodKey]['refunded'] = $this->decimal(
                    CashAmount::add($summary['methods'][$methodKey]['refunded'], $amount)
                );
                if ($settled) {
                    $summary['methods'][$methodKey]['settled_refunded'] = $this->decimal(
                        CashAmount::add($summary['methods'][$methodKey]['settled_refunded'], $amount)
                    );
                }
            }
        }

        foreach (self::PAYMENT_TYPES as $type) {
            $summary['net_by_type'][$type] = $this->decimal(CashAmount::subtract(
                $summary['by_type'][$type],
                $summary['settled_refunds_by_type'][$type]
            ));
        }
        $summary['net_total'] = $this->decimal(CashAmount::subtract(
            $summary['total'],
            $summary['settled_refund_total']
        ));
        $summary['cash_net'] = $summary['net_by_type']['cash'];
        $summary['non_cash_net'] = $this->decimal(CashAmount::subtract(
            $summary['net_total'],
            $summary['cash_net']
        ));
        foreach ($summary['methods'] as &$methodRow) {
            $methodRow['net'] = $this->decimal(CashAmount::subtract(
                $methodRow['total'],
                $methodRow['settled_refunded']
            ));
        }
        unset($methodRow);
        $summary['methods'] = array_values($summary['methods']);

        return $summary;
    }

    private function drawerSummary(mysqli $conn, ?array $session): array
    {
        $summary = [
            'opening_cash' => '0.000',
            'pre_close_expected_cash' => '0.000',
            'close_variance' => null,
            'post_close_expected_cash' => '0.000',
            'counted_cash' => null,
            'expected_cash' => '0.000',
            'movement_totals' => $this->zeroMovementTypes(),
            'movement_count' => 0,
        ];
        if (!$session || !$this->tableExists($conn, 'drawer_movements')) {
            return $summary;
        }

        $summary['opening_cash'] = $this->decimal($session['opening_cash'] ?? 0);
        foreach ($this->drawerSessionService->movementsForSession($conn, (int) $session['id']) as $movement) {
            $type = (string) $movement['movement_type'];
            if (!array_key_exists($type, self::MOVEMENT_SIGNS)) {
                continue;
            }

            if ($type === 'closing_adjustment') {
                continue;
            }

            $sign = self::MOVEMENT_SIGNS[$type];
            if ($sign === 0) {
                $summary['movement_count']++;
                continue;
            }

            $amount = CashAmount::normalize($movement['amount'] ?? '0.00', true);
            $summary['movement_totals'][$type] = $this->decimal(
                CashAmount::add($summary['movement_totals'][$type], $amount)
            );
            $summary['movement_count']++;
        }

        $breakdown = $this->drawerSessionService->sessionCashBreakdown($conn, (int) $session['id']);
        $summary['pre_close_expected_cash'] = $breakdown['pre_close_expected_cash'];
        $summary['close_variance'] = $breakdown['close_variance'];
        $summary['post_close_expected_cash'] = $breakdown['post_close_expected_cash'];
        $summary['counted_cash'] = $breakdown['counted_cash'];
        $summary['expected_cash'] = $breakdown['post_close_expected_cash'];

        return $summary;
    }

    private function resolveDrawerSession(mysqli $conn, array $scope, int $userId, int $tenant, int $branch): ?array
    {
        if (!$this->tableExists($conn, 'drawer_sessions')) {
            return null;
        }

        $sessionId = (int) ($scope['drawer_session_id'] ?? 0);
        if ($sessionId > 0) {
            return $this->drawerSessionService->sessionById($conn, $sessionId);
        }

        return $this->drawerSessionService->findOpenSession($conn, $userId, $tenant, $branch);
    }

    private function activePaymentMethodTypes(mysqli $conn): array
    {
        if (!$this->tableExists($conn, 'payment_methods')) {
            return [];
        }

        $types = [];
        foreach ($this->paymentMethodService->listActive($conn) as $method) {
            $types[(string) $method['code']] = (string) $method['type'];
        }

        return $types;
    }

    private function paymentTypeForMethod(string $method, array $methodTypes): string
    {
        $code = $this->normalizePaymentCodeOrNull($method);
        if ($code !== null && isset($methodTypes[$code])) {
            return $methodTypes[$code];
        }

        $normalized = strtolower(trim($method));
        if ($normalized === 'cash' || $normalized === 'نقدي') {
            return 'cash';
        }
        if ($normalized === 'bank' || $normalized === 'بنك') {
            return 'bank';
        }
        if ($normalized === 'card' || $normalized === 'card_terminal' || $normalized === 'بطاقة') {
            return 'card';
        }
        if ($normalized === 'wallet' || $normalized === 'محفظة') {
            return 'wallet';
        }

        return 'other';
    }

    private function normalizePaymentCodeOrNull(string $method): ?string
    {
        try {
            return $this->paymentMethodService->normalizeCode($method);
        } catch (Throwable $exception) {
            return null;
        }
    }

    private function zeroTypes(): array
    {
        return array_fill_keys(self::PAYMENT_TYPES, '0.000');
    }

    private function zeroMovementTypes(): array
    {
        return array_fill_keys(array_keys(self::MOVEMENT_SIGNS), '0.000');
    }

    private function positiveInt($value, string $code): int
    {
        $value = (int) $value;
        if ($value < 1) {
            throw new InvalidArgumentException($code);
        }

        return $value;
    }

    private function nonNegativeInt($value): int
    {
        return max(0, (int) $value);
    }

    private function date($value): string
    {
        $timestamp = strtotime((string) $value);
        if ($timestamp === false) {
            throw new InvalidArgumentException('SHIFT_DATE_INVALID');
        }

        return date('Y-m-d', $timestamp);
    }

    private function decimal($value): string
    {
        $normalized = CashAmount::normalize($value, true);

        return $normalized . '0';
    }

    private function tableExists(mysqli $conn, string $tableName): bool
    {
        $tableName = $conn->real_escape_string($tableName);
        $result = $conn->query("SHOW TABLES LIKE '{$tableName}'");

        return $result && $result->num_rows > 0;
    }

    private function columnExists(mysqli $conn, string $tableName, string $columnName): bool
    {
        $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName) ?: $tableName;
        $columnName = preg_replace('/[^a-zA-Z0-9_]/', '', $columnName) ?: $columnName;
        $result = $conn->query("SHOW COLUMNS FROM `{$tableName}` LIKE '" . $conn->real_escape_string($columnName) . "'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    private function queryAll(mysqli $conn, string $sql, array $params): array
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
