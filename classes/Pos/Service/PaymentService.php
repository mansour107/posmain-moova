<?php

require_once __DIR__ . '/../../TableOrderService.php';
require_once __DIR__ . '/PaymentMethodService.php';
require_once __DIR__ . '/DrawerSessionService.php';
require_once __DIR__ . '/../../Financial/Money.php';
require_once __DIR__ . '/../../Financial/FinancialMoneyInput.php';

class PaymentService
{
    private $tableOrderService;
    private $paymentMethodService;
    private $drawerSessionService;

    public function __construct(?TableOrderService $tableOrderService = null, ?PaymentMethodService $paymentMethodService = null, ?DrawerSessionService $drawerSessionService = null)
    {
        $this->tableOrderService = $tableOrderService ?: new TableOrderService();
        $this->paymentMethodService = $paymentMethodService ?: new PaymentMethodService();
        $this->drawerSessionService = $drawerSessionService ?: new DrawerSessionService();
    }

    public function payTableOrder(mysqli $conn, array $request, array $context = []): array
    {
        $tableId = $this->requiredPositiveInt($request, 'table_id');
        $orderId = $this->resolveOrderId($conn, $tableId, $request);
        $amountPaid = $this->requiredPositiveAmount($request, ['paid', 'amount_paid', 'amount']);
        $paymentMethod = $this->requiredString($request, ['payment_method_id', 'payment_method', 'method'], 'PAYMENT_METHOD_REQUIRED');
        $tender = $this->paymentMethodService->resolveTender(
            $conn,
            $paymentMethod,
            $request['reference_no'] ?? $request['notes'] ?? $request['payment_notes'] ?? null
        );
        $paymentMethod = $tender['code'];
        $notes = (string) ($tender['reference_no'] ?? '');
        $userId = $this->contextUserId($request, $context);
        // A payment request may not rewrite pricing. The locked order remains
        // the only source of truth for discount and net at settlement time.
        $discount = null;
        $netOverride = null;
        $drawerContext = array_merge($request, $context, ['drawer_reason' => 'table_payment']);
        $drawerSession = $this->preflightCashDrawerForPayment($conn, $paymentMethod, $amountPaid, $userId, $drawerContext);

        $result = $this->tableOrderService->payTableOrder(
            $conn,
            $tableId,
            $orderId,
            $amountPaid,
            $paymentMethod,
            $notes,
            $userId,
            $discount,
            $netOverride,
            [
                'type' => (string) ($tender['type'] ?? ''),
                'require_cash_fact_columns' => true,
            ]
        );

        $movement = $this->recordCashDrawerMovementForPayment(
            $conn,
            $paymentMethod,
            (string) ($result['applied_amount'] ?? '0.00'),
            (int) ($result['order_id'] ?? $orderId),
            $userId,
            $drawerContext,
            $drawerSession,
            isset($result['payment_id']) ? (int) $result['payment_id'] : null
        );

        return [
            'success' => true,
            'code' => 'OK',
            'message' => 'PAYMENT_APPLIED',
            'data' => array_merge($result, [
                'drawer_movement_id' => $movement ? (int) ($movement['id'] ?? 0) : null,
            ]),
        ];
    }

    public function preflightCashDrawerForPayment(mysqli $conn, string $paymentMethod, $amount, int $userId, array $context = []): ?array
    {
        if (!FinancialMoneyInput::money($amount)->isPositive() || !$this->isCashPaymentMethod($conn, $paymentMethod)) {
            return null;
        }

        return $this->openDrawerSessionForCashPayment($conn, $userId, $context);
    }

    public function recordCashDrawerMovementForPayment(mysqli $conn, string $paymentMethod, $amount, int $orderId, int $userId, array $context = [], ?array $preflightSession = null, ?int $paymentId = null, ?int $refOtHeadId = null): ?array
    {
        if (!FinancialMoneyInput::money($amount)->isPositive() || !$this->isCashPaymentMethod($conn, $paymentMethod)) {
            return null;
        }

        return $this->recordCashMovementWithFallback($conn, 'sale_cash', $amount, $orderId, $userId, $context, $preflightSession, $paymentId, $refOtHeadId);
    }

    public function recordCashRefundMovementForPayment(
        mysqli $conn,
        $amount,
        int $orderId,
        int $userId,
        array $context = [],
        ?array $preflightSession = null,
        ?int $paymentId = null,
        ?int $refOtHeadId = null
    ): ?array {
        if (!FinancialMoneyInput::money($amount)->isPositive()) {
            return null;
        }

        return $this->recordCashMovementWithFallback($conn, 'refund_cash', $amount, $orderId, $userId, $context, $preflightSession, $paymentId, $refOtHeadId);
    }

    private function recordCashMovementWithFallback(
        mysqli $conn,
        string $movementType,
        $amount,
        int $orderId,
        int $userId,
        array $context,
        ?array $preflightSession,
        ?int $paymentId,
        ?int $refOtHeadId
    ): ?array {
        if (!$this->tableExists($conn, 'drawer_movements')) {
            throw new RuntimeException('DRAWER_MOVEMENT_SCHEMA_REQUIRED');
        }

        $reason = (string) ($context['drawer_reason'] ?? ($movementType === 'refund_cash' ? 'pos_cash_refund' : 'pos_cash_payment'));
        $movementPayload = [
            'movement_type' => $movementType,
            'amount' => $amount,
            'order_id' => $orderId,
            'payment_id' => $paymentId,
            'idempotency_key' => $this->drawerIdempotencyKey(
                $context,
                $movementType,
                $orderId,
                $paymentId,
                $refOtHeadId
            ),
            'reason' => $reason,
            'created_by' => $userId,
            'tenant' => $this->contextNonNegativeInt($context, ['tenant', 'pos_tenant']),
            'branch' => $this->contextNonNegativeInt($context, ['branch', 'pos_branch']),
        ];
        if ($refOtHeadId !== null && $refOtHeadId > 0) {
            $movementPayload['ref_ot_head_id'] = $refOtHeadId;
        }

        $session = $preflightSession;
        if ($session === null) {
            $session = $this->openDrawerSessionForCashPayment($conn, $userId, $context);
        }
        if (!$session) {
            throw new RuntimeException('DRAWER_SESSION_REQUIRED');
        }

        return $this->drawerSessionService->recordMovement($conn, (int) $session['id'], $movementPayload);
    }

    private function drawerIdempotencyKey(
        array $context,
        string $movementType,
        int $orderId,
        ?int $paymentId,
        ?int $refOtHeadId
    ): string {
        $requestKey = trim((string) (
            $context['drawer_idempotency_key']
            ?? $context['idempotency_key']
            ?? $context['request_id']
            ?? ''
        ));
        if ($requestKey === '') {
            throw new InvalidArgumentException('DRAWER_IDEMPOTENCY_REQUIRED');
        }

        $identity = implode(':', [
            'drawer',
            $movementType,
            $orderId,
            (int) ($paymentId ?? 0),
            (int) ($refOtHeadId ?? 0),
        ]);
        $key = $requestKey . ':' . $identity;
        if (strlen($key) <= 191) {
            return $key;
        }

        return substr($requestKey, 0, 96) . ':drawer-sha256:' . hash('sha256', $key);
    }

    public function netCashRecordedForOrder(mysqli $conn, int $orderId): string
    {
        return $this->drawerSessionService->netCashRecordedForOrder($conn, $orderId);
    }

    public function recordCollectedOrderPayments(
        mysqli $conn,
        int $orderId,
        $cashAmount,
        $bankAmount,
        int $userId,
        array $context = [],
        string $reason = 'pos_cash_payment'
    ): void {
        $cashAmount = FinancialMoneyInput::money($cashAmount);
        $bankAmount = FinancialMoneyInput::money($bankAmount);
        if ($cashAmount->isPositive()) {
            $drawerContext = array_merge($context, ['drawer_reason' => $reason]);
            if (session_status() === PHP_SESSION_ACTIVE) {
                if (!array_key_exists('drawer_session_id', $drawerContext)) {
                    $drawerContext['drawer_session_id'] = (int) ($_SESSION['pos_drawer_session_id'] ?? 0);
                }
                if (!array_key_exists('tenant', $drawerContext) && !array_key_exists('pos_tenant', $drawerContext)) {
                    $drawerContext['tenant'] = (int) ($_SESSION['pos_tenant'] ?? 0);
                }
                if (!array_key_exists('branch', $drawerContext) && !array_key_exists('pos_branch', $drawerContext)) {
                    $drawerContext['branch'] = (int) ($_SESSION['pos_branch'] ?? 0);
                }
            }
            $paymentId = $this->insertOrderPaymentRecordIfAvailable(
                $conn,
                $orderId,
                $cashAmount->toString(),
                'cash',
                $userId,
                [
                    'tendered_amount' => (string) ($context['cash_tendered'] ?? $context['tendered_amount'] ?? $cashAmount->toString()),
                    'change_due' => (string) ($context['change_due'] ?? $context['change'] ?? '0.00'),
                ]
            );
            $this->recordCashDrawerMovementForPayment(
                $conn,
                'cash',
                $cashAmount->toString(),
                $orderId,
                $userId,
                $drawerContext,
                null,
                $paymentId
            );
        }

        if ($bankAmount->isPositive()) {
            $this->insertOrderPaymentRecordIfAvailable($conn, $orderId, $bankAmount->toString(), 'bank', $userId);
        }
    }

    public function isCashPaymentMethod(mysqli $conn, $paymentMethod): bool
    {
        if (!$this->tableExists($conn, 'payment_methods')) {
            return $this->legacyCashCode($paymentMethod);
        }

        try {
            $method = $this->paymentMethodService->resolveActive($conn, $paymentMethod);

            return ($method['type'] ?? '') === 'cash';
        } catch (Throwable $exception) {
            // Catalog may exist before cash is seeded; still classify known cash codes
            // for drawer impact. Tender posting (resolveTender) remains fail-closed.
            return $this->legacyCashCode($paymentMethod);
        }
    }

    private function legacyCashCode($paymentMethod): bool
    {
        $code = strtolower(trim((string) $paymentMethod));

        return in_array($code, ['cash', 'كاش', 'نقدي', 'نقد'], true);
    }

    private function resolveOrderId(mysqli $conn, int $tableId, array $request): int
    {
        if (array_key_exists('order_id', $request) && (int) $request['order_id'] > 0) {
            return (int) $request['order_id'];
        }

        $order = $this->tableOrderService->findActiveOrderByTableId($conn, $tableId, true);
        if (!$order) {
            throw new InvalidArgumentException('ORDER_NOT_ACTIVE');
        }

        return (int) $order['id'];
    }

    private function requiredPositiveInt(array $request, string $key): int
    {
        if (!array_key_exists($key, $request)) {
            throw new InvalidArgumentException(strtoupper($key) . '_REQUIRED');
        }

        $value = (int) $request[$key];
        if ($value < 1) {
            throw new InvalidArgumentException(strtoupper($key) . '_REQUIRED');
        }

        return $value;
    }

    private function requiredPositiveAmount(array $request, array $keys): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $request)) {
                $amount = FinancialMoneyInput::money($request[$key]);
                if (!$amount->isPositive()) {
                    throw new InvalidArgumentException('PAYMENT_AMOUNT_INVALID');
                }

                return $amount->toString();
            }
        }

        throw new InvalidArgumentException('PAYMENT_AMOUNT_INVALID');
    }

    private function requiredString(array $request, array $keys, string $code): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $request)) {
                $value = trim((string) $request[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        throw new InvalidArgumentException($code);
    }

    private function optionalMoney(array $request, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $request) && $request[$key] !== '' && $request[$key] !== null) {
                return FinancialMoneyInput::money($request[$key])->toString();
            }
        }

        return null;
    }

    private function contextUserId(array $request, array $context): int
    {
        $userId = (int) ($request['user_id'] ?? $context['user_id'] ?? 1);
        if ($userId < 1) {
            throw new InvalidArgumentException('USER_ID_REQUIRED');
        }

        return $userId;
    }

    private function openDrawerSessionForCashPayment(mysqli $conn, int $userId, array $context): ?array
    {
        $hasDrawerTables = $this->tableExists($conn, 'drawer_sessions') && $this->tableExists($conn, 'drawer_movements');
        if (!$hasDrawerTables) {
            throw new RuntimeException('DRAWER_SESSION_REQUIRED');
        }

        $tenant = $this->contextNonNegativeInt($context, ['tenant', 'pos_tenant']);
        $branch = $this->contextNonNegativeInt($context, ['branch', 'pos_branch']);
        if ($tenant < 1 && session_status() === PHP_SESSION_ACTIVE) {
            $tenant = max(0, (int) ($_SESSION['pos_tenant'] ?? 0));
        }
        if ($branch < 1 && session_status() === PHP_SESSION_ACTIVE) {
            $branch = max(0, (int) ($_SESSION['pos_branch'] ?? 0));
        }
        if (!array_key_exists('drawer_session_id', $context) && session_status() === PHP_SESSION_ACTIVE) {
            $context['drawer_session_id'] = (int) ($_SESSION['pos_drawer_session_id'] ?? 0);
        }
        $context['tenant'] = $tenant;
        $context['branch'] = $branch;

        $session = $this->drawerSessionService->resolveOpenSessionForUser($conn, $userId, $context);
        if (!$session) {
            $sessionId = (int) ($context['drawer_session_id'] ?? 0);
            if ($sessionId > 0) {
                require_once __DIR__ . '/DrawerOverrideService.php';
                try {
                    $override = (new DrawerOverrideService($this->drawerSessionService))
                        ->requireActiveOverrideForWrite($conn, $userId, $sessionId);
                    $session = $override['drawer_session'] ?? null;
                } catch (RuntimeException $exception) {
                    $session = null;
                }
            }
        }
        if (!$session) {
            throw new RuntimeException('DRAWER_SESSION_REQUIRED');
        }

        return $session;
    }

    private function contextNonNegativeInt(array $context, array $keys): int
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $context) && $context[$key] !== '' && $context[$key] !== null) {
                return max(0, (int) $context[$key]);
            }
        }

        return 0;
    }

    private function tableExists(mysqli $conn, string $tableName): bool
    {
        $tableName = $conn->real_escape_string($tableName);
        $result = $conn->query("SHOW TABLES LIKE '{$tableName}'");

        return $result && $result->num_rows > 0;
    }

    private function insertOrderPaymentRecordIfAvailable(
        mysqli $conn,
        int $orderId,
        $amount,
        string $paymentMethod,
        int $userId,
        array $cashFacts = []
    ): ?int {
        $applied = FinancialMoneyInput::money($amount)->toString();
        if (!$this->tableExists($conn, 'order_payments')) {
            return null;
        }

        $tendered = FinancialMoneyInput::moneyString($cashFacts['tendered_amount'] ?? $applied);
        $changeDue = FinancialMoneyInput::moneyString($cashFacts['change_due'] ?? '0.00');
        $hasCashFacts = $this->columnExists($conn, 'order_payments', 'tendered_amount')
            && $this->columnExists($conn, 'order_payments', 'applied_amount')
            && $this->columnExists($conn, 'order_payments', 'change_due');

        if ($hasCashFacts) {
            $this->tableOrderService->execute($conn, "
                INSERT INTO order_payments (
                    order_id, amount, tendered_amount, applied_amount, change_due,
                    payment_method, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ", [$orderId, $applied, $tendered, $applied, $changeDue, $paymentMethod, $userId]);
        } else {
            $this->tableOrderService->execute($conn, "
                INSERT INTO order_payments (order_id, amount, payment_method, created_by, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ", [$orderId, $applied, $paymentMethod, $userId]);
        }
        $paymentId = (int) $conn->insert_id;
        $this->tableOrderService->assignUuidIfPresent($conn, 'order_payments', $paymentId);

        return $paymentId > 0 ? $paymentId : null;
    }

    private function columnExists(mysqli $conn, string $tableName, string $columnName): bool
    {
        $tableName = $conn->real_escape_string($tableName);
        $columnName = $conn->real_escape_string($columnName);
        $result = $conn->query("SHOW COLUMNS FROM `{$tableName}` LIKE '{$columnName}'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }
}
