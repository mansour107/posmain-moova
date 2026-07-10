<?php

require_once __DIR__ . '/DrawerSessionService.php';
require_once __DIR__ . '/PaymentMethodService.php';
require_once __DIR__ . '/BusinessDayService.php';

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

        $payments = $this->paymentSummary($conn, $userId, $date, $openedAt, $tenant, $branch);
        $drawer = $this->drawerSummary($conn, $session);
        $cashPayments = (float) $payments['by_type']['cash'];
        $drawerSaleCash = (float) $drawer['movement_totals']['sale_cash'];

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
                'drawer_sale_cash' => $this->decimal($drawerSaleCash),
                'cash_difference' => $this->decimal($drawerSaleCash - $cashPayments),
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
        int $branch = 0
    ): array {
        $summary = [
            'total' => '0.000',
            'cash' => '0.000',
            'non_cash' => '0.000',
            'by_type' => $this->zeroTypes(),
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
        $sql .= " ORDER BY paid_at, op.id";

        foreach ($this->queryAll($conn, $sql, $params) as $row) {
            $amount = (float) ($row['amount'] ?? 0);
            if (abs($amount) < 0.0001) {
                continue;
            }

            $method = trim((string) ($row['payment_method'] ?? ''));
            $type = $this->paymentTypeForMethod($method, $methodTypes);
            $summary['by_type'][$type] = $this->decimal((float) $summary['by_type'][$type] + $amount);
            $summary['total'] = $this->decimal((float) $summary['total'] + $amount);
            if (!isset($summary['methods'][$method])) {
                $summary['methods'][$method] = [
                    'payment_method' => $method,
                    'type' => $type,
                    'total' => '0.000',
                    'count' => 0,
                ];
            }
            $summary['methods'][$method]['total'] = $this->decimal((float) $summary['methods'][$method]['total'] + $amount);
            $summary['methods'][$method]['count']++;
        }

        $summary['cash'] = $summary['by_type']['cash'];
        $summary['non_cash'] = $this->decimal((float) $summary['total'] - (float) $summary['cash']);
        $summary['methods'] = array_values($summary['methods']);

        return $summary;
    }

    private function drawerSummary(mysqli $conn, ?array $session): array
    {
        $summary = [
            'opening_cash' => '0.000',
            'pre_close_expected_cash' => '0.000',
            'close_variance' => '0.000',
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

            $amount = (float) $movement['amount'];
            $summary['movement_totals'][$type] = $this->decimal((float) $summary['movement_totals'][$type] + $amount);
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

        return strtolower(trim($method)) === 'cash' ? 'cash' : 'other';
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
        return number_format((float) $value, 3, '.', '');
    }

    private function tableExists(mysqli $conn, string $tableName): bool
    {
        $tableName = $conn->real_escape_string($tableName);
        $result = $conn->query("SHOW TABLES LIKE '{$tableName}'");

        return $result && $result->num_rows > 0;
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
