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

        $this->recordCashDrawerMovementForPayment(
            $conn,
            $paymentMethod,
            (float) ($result['applied_amount'] ?? 0),
            (int) ($result['order_id'] ?? $orderId),
            $userId,
            $drawerContext,
            $drawerSession
        );

        return [
            'success' => true,
            'code' => 'OK',
            'message' => 'PAYMENT_APPLIED',
            'data' => $result,
        ];
    }

    public function preflightCashDrawerForPayment(mysqli $conn, string $paymentMethod, float $amount, int $userId, array $context = []): ?array
    {
        if ($amount <= 0 || !$this->isCashPaymentMethod($conn, $paymentMethod)) {
            return null;
        }

        return $this->openDrawerSessionForCashPayment($conn, $userId, $context);
    }

    public function recordCashDrawerMovementForPayment(mysqli $conn, string $paymentMethod, float $amount, int $orderId, int $userId, array $context = [], ?array $preflightSession = null, ?int $paymentId = null): ?array
    {
        if ($amount <= 0 || !$this->isCashPaymentMethod($conn, $paymentMethod)) {
            return null;
        }

        $session = $preflightSession ?: $this->openDrawerSessionForCashPayment($conn, $userId, $context);
        if (!$session) {
            return null;
        }

        return $this->drawerSessionService->recordMovement($conn, (int) $session['id'], [
            'movement_type' => 'sale_cash',
            'amount' => $amount,
            'order_id' => $orderId,
            'payment_id' => $paymentId,
            'reason' => $context['drawer_reason'] ?? 'pos_cash_payment',
            'created_by' => $userId,
        ]);
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
        $session = $this->drawerSessionService->findOpenSession($conn, $userId, $tenant, $branch);
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
}
