<?php

require_once __DIR__ . '/../../TableOrderService.php';
require_once __DIR__ . '/PaymentMethodService.php';
require_once __DIR__ . '/DrawerSessionService.php';

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
        $paymentMethod = $this->requiredString($request, ['payment_method', 'method'], 'PAYMENT_METHOD_REQUIRED');
        $notes = trim((string) ($request['notes'] ?? $request['payment_notes'] ?? $request['reference_no'] ?? ''));
        $userId = $this->contextUserId($request, $context);
        $discount = $this->optionalFloat($request, ['discount', 'fat_disc']);
        $netOverride = $this->optionalFloat($request, ['net', 'fat_net']);
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
            $netOverride
        );

        $movement = $this->recordCashDrawerMovementForPayment(
            $conn,
            $paymentMethod,
            (float) ($result['applied_amount'] ?? 0),
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

    public function preflightCashDrawerForPayment(mysqli $conn, string $paymentMethod, float $amount, int $userId, array $context = []): ?array
    {
        if ($amount <= 0 || !$this->isCashPaymentMethod($conn, $paymentMethod)) {
            return null;
        }

        return $this->openDrawerSessionForCashPayment($conn, $userId, $context);
    }

    public function recordCashDrawerMovementForPayment(mysqli $conn, string $paymentMethod, float $amount, int $orderId, int $userId, array $context = [], ?array $preflightSession = null, ?int $paymentId = null, ?int $refOtHeadId = null): ?array
    {
        if ($amount <= 0 || !$this->isCashPaymentMethod($conn, $paymentMethod)) {
            return null;
        }

        return $this->recordCashMovementWithFallback($conn, 'sale_cash', $amount, $orderId, $userId, $context, $preflightSession, $paymentId, $refOtHeadId);
    }

    public function recordCashRefundMovementForPayment(
        mysqli $conn,
        float $amount,
        int $orderId,
        int $userId,
        array $context = [],
        ?array $preflightSession = null,
        ?int $paymentId = null,
        ?int $refOtHeadId = null
    ): ?array {
        if ($amount <= 0) {
            return null;
        }

        return $this->recordCashMovementWithFallback($conn, 'refund_cash', $amount, $orderId, $userId, $context, $preflightSession, $paymentId, $refOtHeadId);
    }

    private function recordCashMovementWithFallback(
        mysqli $conn,
        string $movementType,
        float $amount,
        int $orderId,
        int $userId,
        array $context,
        ?array $preflightSession,
        ?int $paymentId,
        ?int $refOtHeadId
    ): ?array {
        if (!$this->tableExists($conn, 'drawer_movements')) {
            return null;
        }

        $reason = (string) ($context['drawer_reason'] ?? ($movementType === 'refund_cash' ? 'pos_cash_refund' : 'pos_cash_payment'));
        $movementPayload = [
            'movement_type' => $movementType,
            'amount' => $amount,
            'order_id' => $orderId,
            'payment_id' => $paymentId,
            'reason' => $reason,
            'created_by' => $userId,
            'tenant' => $this->contextNonNegativeInt($context, ['tenant', 'pos_tenant']),
            'branch' => $this->contextNonNegativeInt($context, ['branch', 'pos_branch']),
        ];
        if ($refOtHeadId !== null && $refOtHeadId > 0) {
            $movementPayload['ref_ot_head_id'] = $refOtHeadId;
        }

        try {
            $session = $preflightSession ?: $this->openDrawerSessionForCashPayment($conn, $userId, $context);
            if ($session) {
                return $this->drawerSessionService->recordMovement($conn, (int) $session['id'], $movementPayload);
            }

            $movementPayload['reason'] = $reason . ':unassigned';

            return $this->drawerSessionService->recordUnassignedMovement($conn, $movementPayload);
        } catch (Throwable $exception) {
            error_log('Drawer movement recording failed, attempting unassigned fallback: ' . $exception->getMessage());
            try {
                $movementPayload['reason'] = $reason . ':unassigned';

                return $this->drawerSessionService->recordUnassignedMovement($conn, $movementPayload);
            } catch (Throwable $fallbackException) {
                error_log('Unassigned drawer movement fallback failed: ' . $fallbackException->getMessage());

                return null;
            }
        }
    }

    public function netCashRecordedForOrder(mysqli $conn, int $orderId): float
    {
        return $this->drawerSessionService->netCashRecordedForOrder($conn, $orderId);
    }

    public function recordCollectedOrderPayments(
        mysqli $conn,
        int $orderId,
        float $cashAmount,
        float $bankAmount,
        int $userId,
        array $context = [],
        string $reason = 'pos_cash_payment'
    ): void {
        if ($cashAmount > 0) {
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
            $paymentId = $this->insertOrderPaymentRecordIfAvailable($conn, $orderId, $cashAmount, 'cash', $userId);
            $this->recordCashDrawerMovementForPayment(
                $conn,
                'cash',
                $cashAmount,
                $orderId,
                $userId,
                $drawerContext,
                null,
                $paymentId
            );
        }

        if ($bankAmount > 0) {
            $this->insertOrderPaymentRecordIfAvailable($conn, $orderId, $bankAmount, 'bank', $userId);
        }
    }

    public function isCashPaymentMethod(mysqli $conn, $paymentMethod): bool
    {
        $legacyCash = strtolower(trim((string) $paymentMethod)) === 'cash';
        if (!$this->tableExists($conn, 'payment_methods')) {
            return $legacyCash;
        }

        try {
            $method = $this->paymentMethodService->resolveActive($conn, $paymentMethod);
        } catch (Throwable $exception) {
            return $legacyCash;
        }

        return ($method['type'] ?? '') === 'cash';
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

    private function requiredPositiveAmount(array $request, array $keys): float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $request)) {
                $amount = (float) $request[$key];
                if ($amount <= 0) {
                    throw new InvalidArgumentException('PAYMENT_AMOUNT_INVALID');
                }

                return $amount;
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

    private function optionalFloat(array $request, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $request) && $request[$key] !== '' && $request[$key] !== null) {
                return (float) $request[$key];
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
            if ($this->requiresOpenShift()) {
                throw new RuntimeException('DRAWER_SESSION_REQUIRED');
            }

            return null;
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
        if (!$session && $this->requiresOpenShift()) {
            throw new RuntimeException('DRAWER_SESSION_REQUIRED');
        }

        return $session;
    }

    private function requiresOpenShift(): bool
    {
        $value = strtolower(trim((string) getenv('POSMAIN_REQUIRE_OPEN_SHIFT')));

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
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

    private function insertOrderPaymentRecordIfAvailable(mysqli $conn, int $orderId, float $amount, string $paymentMethod, int $userId): ?int
    {
        if (abs($amount) < 0.0001 || !$this->tableExists($conn, 'order_payments')) {
            return null;
        }

        $this->tableOrderService->execute($conn, "
            INSERT INTO order_payments (order_id, amount, payment_method, created_by, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ", [$orderId, $amount, $paymentMethod, $userId]);
        $paymentId = (int) $conn->insert_id;
        $this->tableOrderService->assignUuidIfPresent($conn, 'order_payments', $paymentId);

        return $paymentId > 0 ? $paymentId : null;
    }
}
