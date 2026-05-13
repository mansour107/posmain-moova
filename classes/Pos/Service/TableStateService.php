<?php

require_once __DIR__ . '/../../TableOrderService.php';

class TableStateService
{
    private $tableOrderService;

    public function __construct(?TableOrderService $tableOrderService = null)
    {
        $this->tableOrderService = $tableOrderService ?: new TableOrderService();
    }

    public function findActiveOrder(mysqli $conn, int $tableId, bool $lock = false): ?array
    {
        $this->assertPositiveInt($tableId, 'TABLE_ID_REQUIRED');
        $order = $this->tableOrderService->findActiveOrderByTableId($conn, $tableId, $lock);

        return $order ?: null;
    }

    public function requireTable(mysqli $conn, int $tableId): array
    {
        $this->assertPositiveInt($tableId, 'TABLE_ID_REQUIRED');

        return $this->tableOrderService->requireTable($conn, $tableId);
    }

    public function markOccupied(mysqli $conn, int $tableId): void
    {
        $this->assertPositiveInt($tableId, 'TABLE_ID_REQUIRED');
        $this->tableOrderService->markTableOccupied($conn, $tableId);
    }

    public function freeIfNoActiveOrder(mysqli $conn, int $tableId): bool
    {
        $this->assertPositiveInt($tableId, 'TABLE_ID_REQUIRED');

        return $this->tableOrderService->setTableFreeIfNoActiveOrder($conn, $tableId);
    }

    public function cancelActiveOrder(mysqli $conn, array $request, array $context = []): array
    {
        $tableId = $this->requiredPositiveInt($request, 'table_id');
        $orderId = $this->requiredPositiveInt($request, 'order_id');
        $reason = trim((string) ($request['reason'] ?? $request['cancellation_reason'] ?? ''));
        $userId = $this->contextUserId($request, $context);

        $before = $this->tableOrderService->cancelTableOrder($conn, $tableId, $orderId, $reason, $userId);
        $tableFreed = $this->tableOrderService->setTableFreeIfNoActiveOrder($conn, $tableId);

        return [
            'success' => true,
            'code' => 'OK',
            'message' => 'ORDER_CANCELLED',
            'data' => [
                'order_id' => $orderId,
                'table_id' => $tableId,
                'table_freed' => $tableFreed,
                'cancelled_order' => $before,
            ],
        ];
    }

    private function requiredPositiveInt(array $request, string $key): int
    {
        if (!array_key_exists($key, $request)) {
            throw new InvalidArgumentException(strtoupper($key) . '_REQUIRED');
        }

        $value = (int) $request[$key];
        $this->assertPositiveInt($value, strtoupper($key) . '_REQUIRED');

        return $value;
    }

    private function contextUserId(array $request, array $context): int
    {
        $userId = (int) ($request['user_id'] ?? $context['user_id'] ?? 1);
        if ($userId < 1) {
            throw new InvalidArgumentException('USER_ID_REQUIRED');
        }

        return $userId;
    }

    private function assertPositiveInt(int $value, string $code): void
    {
        if ($value < 1) {
            throw new InvalidArgumentException($code);
        }
    }
}
