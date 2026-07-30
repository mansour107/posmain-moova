<?php

require_once __DIR__ . '/OrderEventService.php';
require_once __DIR__ . '/OrderMutationVersionService.php';
require_once __DIR__ . '/../../Sync/SyncOutboxEventService.php';

class TableTransferService
{
    public function moveOrder(mysqli $conn, array $request, array $context = []): array
    {
        $sourceTableId = $this->positiveInt($request['source_table_id'] ?? $request['from_table_id'] ?? 0, 'SOURCE_TABLE_REQUIRED');
        $destinationTableId = $this->positiveInt($request['destination_table_id'] ?? $request['to_table_id'] ?? 0, 'DESTINATION_TABLE_REQUIRED');
        if ($sourceTableId === $destinationTableId) {
            throw new InvalidArgumentException('TABLE_MOVE_SAME_TABLE');
        }

        $ownsTransaction = empty($context['in_transaction']) && empty($context['transaction_started']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $result = $this->moveOrderInsideTransaction($conn, $sourceTableId, $destinationTableId, $request, $context);
            if ($ownsTransaction) {
                $conn->commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }

            throw $exception;
        }
    }

    private function moveOrderInsideTransaction(mysqli $conn, int $sourceTableId, int $destinationTableId, array $request, array $context): array
    {
        $lockedTables = $this->lockTablesInCanonicalOrder($conn, $sourceTableId, $destinationTableId);
        $sourceTable = $lockedTables[$sourceTableId];
        $destinationTable = $lockedTables[$destinationTableId];
        $order = $this->activeOrderForSource($conn, $sourceTableId, $request);
        if (!$order) {
            throw new RuntimeException('ORDER_NOT_ACTIVE');
        }

        if ($this->activeOrderForTable($conn, $destinationTableId, true)) {
            throw new RuntimeException('DESTINATION_TABLE_OCCUPIED');
        }

        $orderId = (int) $order['id'];
        $versionService = new OrderMutationVersionService();
        $versionService->lockAndAssert(
            $conn,
            $orderId,
            $request['mutation_version'] ?? $request['order_version'] ?? null,
            true
        );

        $beforeState = [
            'order_id' => $orderId,
            'source_table_id' => $sourceTableId,
            'destination_table_id' => $destinationTableId,
            'source_table_case' => (int) ($sourceTable['table_case'] ?? 0),
            'destination_table_case' => (int) ($destinationTable['table_case'] ?? 0),
            'payment_status' => $order['payment_status'] ?? null,
            'order_status' => $order['order_status'] ?? null,
        ];

        $stmt = $conn->prepare("UPDATE ot_head SET table_id = ? WHERE id = ?");
        $stmt->bind_param('ii', $destinationTableId, $orderId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('TABLE_MOVE_FAILED');
        }
        $stmt->close();

        $sourceFreed = $this->setTableFreeIfNoActiveOrder($conn, $sourceTableId);
        $this->markTableOccupied($conn, $destinationTableId);
        $mutationVersion = $versionService->bumpAndGet(
            $conn,
            $orderId,
            $request['mutation_version'] ?? $request['order_version'] ?? null
        );
        $movedOrder = $this->orderById($conn, $orderId);
        $afterState = [
            'order_id' => $orderId,
            'source_table_id' => $sourceTableId,
            'destination_table_id' => $destinationTableId,
            'source_table_case' => (int) $this->tableById($conn, $sourceTableId, false)['table_case'],
            'destination_table_case' => (int) $this->tableById($conn, $destinationTableId, false)['table_case'],
            'payment_status' => $movedOrder['payment_status'] ?? null,
            'order_status' => $movedOrder['order_status'] ?? null,
            'mutation_version' => $mutationVersion,
        ];

        $event = (new OrderEventService())->recordRequired(
            $conn,
            $orderId,
            'table_moved',
            $context['event_source'] ?? 'pos_table_transfer',
            [
                'actor_user_id' => $context['user_id'] ?? $request['user_id'] ?? null,
                'tenant' => $context['tenant'] ?? $request['tenant'] ?? $movedOrder['tenant'] ?? 0,
                'branch' => $context['branch'] ?? $request['branch'] ?? $movedOrder['branch'] ?? 0,
                'before_state' => $beforeState,
                'after_state' => $afterState,
                'metadata' => [
                    'source_table_name' => $sourceTable['tname'] ?? null,
                    'destination_table_name' => $destinationTable['tname'] ?? null,
                    'source_freed' => $sourceFreed,
                ],
            ]
        );
        if (!array_key_exists('record_outbox', $context) || (bool) $context['record_outbox']) {
            $syncOutbox = new SyncOutboxEventService();
            $syncOutbox->recordRequiredOrderSnapshot($conn, $orderId, [
                'event_type' => 'order.table_moved',
                'source_system' => 'pos_table_move',
            ]);
            $syncOutbox->recordRequiredTableSnapshot($conn, $sourceTableId, [
                'event_type' => 'table.updated',
                'source_system' => 'pos_table_move',
                'active_order_id' => null,
            ]);
            $syncOutbox->recordRequiredTableSnapshot($conn, $destinationTableId, [
                'event_type' => 'table.updated',
                'source_system' => 'pos_table_move',
                'active_order_id' => $orderId,
            ]);
        }

        return [
            'success' => true,
            'code' => 'OK',
            'order_id' => $orderId,
            'source_table_id' => $sourceTableId,
            'destination_table_id' => $destinationTableId,
            'source_freed' => $sourceFreed,
            'payment_status' => $movedOrder['payment_status'] ?? null,
            'order_status' => $movedOrder['order_status'] ?? null,
            'mutation_version' => $mutationVersion,
            'event_id' => $event['id'] ?? null,
        ];
    }

    private function tableById(mysqli $conn, int $tableId, bool $lock): array
    {
        $lockSql = $lock ? ' FOR UPDATE' : '';
        $stmt = $conn->prepare("
            SELECT id, tname, table_case
            FROM tables
            WHERE id = ?
              AND isdeleted = 0
            LIMIT 1{$lockSql}
        ");
        $stmt->bind_param('i', $tableId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new RuntimeException('TABLE_NOT_FOUND');
        }

        return $row;
    }

    private function lockTablesInCanonicalOrder(mysqli $conn, int $firstTableId, int $secondTableId): array
    {
        $tableIds = [$firstTableId, $secondTableId];
        sort($tableIds, SORT_NUMERIC);
        $rows = [];
        foreach ($tableIds as $tableId) {
            $rows[$tableId] = $this->tableById($conn, $tableId, true);
        }

        return $rows;
    }

    private function activeOrderForSource(mysqli $conn, int $sourceTableId, array $request): ?array
    {
        if (array_key_exists('order_id', $request) && (int) $request['order_id'] > 0) {
            $stmt = $conn->prepare("
                SELECT *
                FROM ot_head
                WHERE id = ?
                  AND table_id = ?
                  AND pro_tybe = 9
                  AND isdeleted = 0
                  AND COALESCE(order_status, 'active') = 'active'
                  AND COALESCE(payment_status, 'unpaid') IN ('unpaid', 'partial')
                LIMIT 1
                FOR UPDATE
            ");
            $orderId = (int) $request['order_id'];
            $stmt->bind_param('ii', $orderId, $sourceTableId);
        } else {
            $stmt = $conn->prepare("
                SELECT *
                FROM ot_head
                WHERE table_id = ?
                  AND pro_tybe = 9
                  AND isdeleted = 0
                  AND COALESCE(order_status, 'active') = 'active'
                  AND COALESCE(payment_status, 'unpaid') IN ('unpaid', 'partial')
                ORDER BY id DESC
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->bind_param('i', $sourceTableId);
        }

        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function activeOrderForTable(mysqli $conn, int $tableId, bool $lock): ?array
    {
        $lockSql = $lock ? ' FOR UPDATE' : '';
        $stmt = $conn->prepare("
            SELECT *
            FROM ot_head
            WHERE table_id = ?
              AND pro_tybe = 9
              AND isdeleted = 0
              AND COALESCE(order_status, 'active') = 'active'
              AND COALESCE(payment_status, 'unpaid') IN ('unpaid', 'partial')
            ORDER BY id DESC
            LIMIT 1{$lockSql}
        ");
        $stmt->bind_param('i', $tableId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function orderById(mysqli $conn, int $orderId): array
    {
        $stmt = $conn->prepare("SELECT * FROM ot_head WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new RuntimeException('ORDER_NOT_FOUND');
        }

        return $row;
    }

    private function markTableOccupied(mysqli $conn, int $tableId): void
    {
        $stmt = $conn->prepare("UPDATE tables SET table_case = 1 WHERE id = ?");
        $stmt->bind_param('i', $tableId);
        $stmt->execute();
        $stmt->close();
    }

    private function setTableFreeIfNoActiveOrder(mysqli $conn, int $tableId): bool
    {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS active_count
            FROM ot_head
            WHERE table_id = ?
              AND pro_tybe = 9
              AND isdeleted = 0
              AND COALESCE(order_status, 'active') = 'active'
              AND COALESCE(payment_status, 'unpaid') IN ('unpaid', 'partial')
        ");
        $stmt->bind_param('i', $tableId);
        $stmt->execute();
        $activeCount = (int) $stmt->get_result()->fetch_assoc()['active_count'];
        $stmt->close();

        if ($activeCount > 0) {
            return false;
        }

        $stmt = $conn->prepare("UPDATE tables SET table_case = 0 WHERE id = ?");
        $stmt->bind_param('i', $tableId);
        $stmt->execute();
        $stmt->close();

        return true;
    }

    private function positiveInt($value, string $code): int
    {
        $value = (int) $value;
        if ($value < 1) {
            throw new InvalidArgumentException($code);
        }

        return $value;
    }
}
