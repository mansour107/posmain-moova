<?php

require_once __DIR__ . '/../../TableOrderService.php';

class PaymentService
{
    private $tableOrderService;

    public function __construct(?TableOrderService $tableOrderService = null)
    {
        $this->tableOrderService = $tableOrderService ?: new TableOrderService();
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

        return [
            'success' => true,
            'code' => 'OK',
            'message' => 'PAYMENT_APPLIED',
            'data' => $result,
        ];
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
}
