<?php

require_once __DIR__ . '/../../TableOrderService.php';
require_once __DIR__ . '/PaymentMethodService.php';
require_once __DIR__ . '/DrawerSessionService.php';
require_once __DIR__ . '/../../Financial/Money.php';

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
        $discount = $this->optionalMoney($request, ['discount', 'fat_disc']);
        $netOverride = $this->optionalMoney($request, ['net', 'fat_net']);
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
        if (!Money::fromLegacy($amount)->isPositive() || !$this->isCashPaymentMethod($conn, $paymentMethod)) {
            return null;
        }

        return $this->openDrawerSessionForCashPayment($conn, $userId, $context);
    }

    public function recordCashDrawerMovementForPayment(mysqli $conn, string $paymentMethod, $amount, int $orderId, int $userId, array $context = [], ?array $preflightSession = null, ?int $paymentId = null, ?int $refOtHeadId = null): ?array
    {
        if (!Money::fromLegacy($amount)->isPositive() || !$this->isCashPaymentMethod($conn, $paymentMethod)) {
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
        if (!Money::fromLegacy($amount)->isPositive()) {
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
        $cashAmount = Money::fromLegacy($cashAmount);
        $bankAmount = Money::fromLegacy($bankAmount);
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
            $paymentId = $this->insertOrderPaymentRecordIfAvailable($conn, $orderId, $cashAmount->toString(), 'cash', $userId);
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
                $amount = Money::fromLegacy($request[$key]);
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
                return Money::fromLegacy($request[$key])->toString();
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

    private function insertOrderPaymentRecordIfAvailable(mysqli $conn, int $orderId, $amount, string $paymentMethod, int $userId): ?int
    {
        $amount = Money::fromLegacy($amount)->toString();
        if (!$this->tableExists($conn, 'order_payments')) {
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
