<?php

require_once __DIR__ . '/../../TableOrderService.php';
require_once __DIR__ . '/../../Recipe/RecipeDecimal.php';
require_once __DIR__ . '/../../Recipe/RecipeOrderLifecycleService.php';
require_once __DIR__ . '/OrderEventService.php';

class TableMergeService
{
    private $tableOrderService;
    private $orderEventService;
    private $recipeLifecycleService;

    public function __construct(
        ?TableOrderService $tableOrderService = null,
        ?OrderEventService $orderEventService = null,
        ?RecipeOrderLifecycleService $recipeLifecycleService = null
    )
    {
        $this->tableOrderService = $tableOrderService ?: new TableOrderService();
        $this->orderEventService = $orderEventService ?: new OrderEventService();
        $this->recipeLifecycleService = $recipeLifecycleService ?: new RecipeOrderLifecycleService();
    }

    public function mergeOrders(mysqli $conn, array $request, array $context = []): array
    {
        $sourceTableId = $this->positiveInt($request['source_table_id'] ?? $request['from_table_id'] ?? 0, 'SOURCE_TABLE_REQUIRED');
        $destinationTableId = $this->positiveInt($request['destination_table_id'] ?? $request['to_table_id'] ?? 0, 'DESTINATION_TABLE_REQUIRED');
        if ($sourceTableId === $destinationTableId) {
            throw new InvalidArgumentException('TABLE_MERGE_SAME_TABLE');
        }

        $ownsTransaction = empty($context['in_transaction']) && empty($context['transaction_started']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $result = $this->mergeInsideTransaction($conn, $sourceTableId, $destinationTableId, $request, $context);
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

    private function mergeInsideTransaction(mysqli $conn, int $sourceTableId, int $destinationTableId, array $request, array $context): array
    {
        $sourceTable = $this->tableById($conn, $sourceTableId, true);
        $destinationTable = $this->tableById($conn, $destinationTableId, true);
        $sourceOrder = $this->activeOrderForTable($conn, $sourceTableId, (int) ($request['source_order_id'] ?? $request['order_id'] ?? 0));
        if (!$sourceOrder) {
            throw new RuntimeException('SOURCE_ORDER_NOT_ACTIVE');
        }
        $destinationOrder = $this->activeOrderForTable($conn, $destinationTableId, (int) ($request['destination_order_id'] ?? 0));
        if (!$destinationOrder) {
            throw new RuntimeException('DESTINATION_ORDER_NOT_ACTIVE');
        }

        $sourceOrderId = (int) $sourceOrder['id'];
        $destinationOrderId = (int) $destinationOrder['id'];
        if ($sourceOrderId === $destinationOrderId) {
            throw new InvalidArgumentException('TABLE_MERGE_SAME_ORDER');
        }

        $detailCount = $this->activeDetailCount($conn, $sourceOrderId);
        if ($detailCount < 1) {
            throw new RuntimeException('SOURCE_ORDER_EMPTY');
        }
        $movedRecipeLines = $this->recipeLineContextsForOrder($conn, $sourceOrderId, $sourceOrder, 'table', 'dine_in', $context);

        $beforeState = [
            'source_table_id' => $sourceTableId,
            'destination_table_id' => $destinationTableId,
            'source_order_id' => $sourceOrderId,
            'destination_order_id' => $destinationOrderId,
            'source_paid_amount' => (float) ($sourceOrder['paid_amount'] ?? 0),
            'destination_paid_amount' => (float) ($destinationOrder['paid_amount'] ?? 0),
        ];

        $this->tableOrderService->execute($conn, "
            UPDATE fat_details
            SET fatid = ?,
                pro_id = ?
            WHERE fatid = ?
                AND isdeleted = 0
        ", [$destinationOrderId, $destinationOrderId, $sourceOrderId]);
        $this->recordRecipeMergedLines($conn, $movedRecipeLines, $destinationOrder, $destinationOrderId);

        $totals = $this->tableOrderService->recalculateOrderTotals($conn, $destinationOrderId);
        $combinedPaid = min((float) $totals['net'], max(0, (float) ($sourceOrder['paid_amount'] ?? 0) + (float) ($destinationOrder['paid_amount'] ?? 0)));
        $status = $this->paidStatus((float) $totals['net'], $combinedPaid);
        $this->tableOrderService->execute($conn, "
            UPDATE ot_head
            SET paid_amount = ?,
                remaining_amount = ?,
                payment_status = ?,
                invoice_status = ?,
                order_status = ?,
                completed_at = CASE WHEN ? = 'completed' THEN NOW() ELSE completed_at END
            WHERE id = ?
        ", [
            $status['paid_amount'],
            $status['remaining_amount'],
            $status['payment_status'],
            $status['invoice_status'],
            $status['order_status'],
            $status['order_status'],
            $destinationOrderId,
        ]);

        $this->tableOrderService->execute($conn, "
            UPDATE ot_head
            SET order_status = 'cancelled',
                invoice_status = 'cancelled',
                payment_status = 'voided',
                isdeleted = 1,
                remaining_amount = 0,
                completed_at = NOW()
            WHERE id = ?
        ", [$sourceOrderId]);

        $sourceFreed = $this->setTableFreeIfNoActiveOrder($conn, $sourceTableId);
        $this->markTableOccupied($conn, $destinationTableId);
        $afterState = [
            'source_table_case' => (int) $this->tableById($conn, $sourceTableId, false)['table_case'],
            'destination_table_case' => (int) $this->tableById($conn, $destinationTableId, false)['table_case'],
            'merged_detail_count' => $detailCount,
            'payment_status' => $status['payment_status'],
            'order_status' => $status['order_status'],
            'paid_amount' => $status['paid_amount'],
            'remaining_amount' => $status['remaining_amount'],
            'net' => (float) $totals['net'],
        ];

        $metadata = [
            'source_table_name' => $sourceTable['tname'] ?? null,
            'destination_table_name' => $destinationTable['tname'] ?? null,
            'source_freed' => $sourceFreed,
        ];
        $this->recordEvent($conn, $destinationOrderId, 'table_merged', $context, $beforeState, $afterState, $metadata);
        $this->recordEvent($conn, $sourceOrderId, 'order_merged_into', $context, $beforeState, $afterState, $metadata);

        return [
            'success' => true,
            'code' => 'OK',
            'source_table_id' => $sourceTableId,
            'destination_table_id' => $destinationTableId,
            'source_order_id' => $sourceOrderId,
            'destination_order_id' => $destinationOrderId,
            'merged_detail_count' => $detailCount,
            'source_freed' => $sourceFreed,
            'payment_status' => $status['payment_status'],
            'order_status' => $status['order_status'],
            'paid_amount' => $status['paid_amount'],
            'remaining_amount' => $status['remaining_amount'],
            'net' => (float) $totals['net'],
        ];
    }

    private function tableById(mysqli $conn, int $tableId, bool $lock): array
    {
        $lockSql = $lock ? ' FOR UPDATE' : '';
        $row = $this->tableOrderService->queryOne($conn, "
            SELECT id, tname, table_case
            FROM tables
            WHERE id = ?
              AND isdeleted = 0
            LIMIT 1{$lockSql}
        ", [$tableId]);
        if (!$row) {
            throw new RuntimeException('TABLE_NOT_FOUND');
        }

        return $row;
    }

    private function activeOrderForTable(mysqli $conn, int $tableId, int $orderId = 0): ?array
    {
        if ($orderId > 0) {
            return $this->tableOrderService->queryOne($conn, "
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
            ", [$orderId, $tableId]);
        }

        return $this->tableOrderService->queryOne($conn, "
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
        ", [$tableId]);
    }

    private function activeDetailCount(mysqli $conn, int $orderId): int
    {
        $row = $this->tableOrderService->queryOne($conn, "
            SELECT COUNT(*) AS c
            FROM fat_details
            WHERE fatid = ?
              AND isdeleted = 0
        ", [$orderId]);

        return (int) ($row['c'] ?? 0);
    }

    private function recipeLineContextsForOrder(
        mysqli $conn,
        int $orderId,
        array $order,
        string $channel,
        string $orderType,
        array $context
    ): array {
        $rows = $this->tableOrderService->queryAll($conn, "
            SELECT *
            FROM fat_details
            WHERE fatid = ?
              AND isdeleted = 0
            ORDER BY id ASC
        ", [$orderId]);

        $lines = [];
        foreach ($rows as $row) {
            $itemId = (int) ($row['item_id'] ?? 0);
            $quantity = $this->recipeLineQuantity($row);
            if ($itemId < 1 || RecipeDecimal::compare($quantity, '0') <= 0) {
                continue;
            }

            $lines[] = [
                'conn' => $conn,
                'tenant' => (int) ($context['tenant'] ?? $context['pos_tenant'] ?? $order['tenant'] ?? 0),
                'branch' => (int) ($context['branch'] ?? $context['pos_branch'] ?? $order['branch'] ?? 0),
                'store_id' => max(0, (int) ($row['det_store'] ?? $order['store_id'] ?? 0)),
                'order_id' => $orderId,
                'fat_detail_id' => (int) ($row['id'] ?? 0),
                'order_line_uuid' => $this->nullableString($row['uuid'] ?? null),
                'sellable_item_id' => $itemId,
                'item_id' => $itemId,
                'quantity' => $quantity,
                'qty' => $quantity,
                'channel' => $channel,
                'order_type' => $orderType,
            ];
        }

        return $lines;
    }

    private function recordRecipeMergedLines(mysqli $conn, array $sourceLines, array $destinationOrder, int $destinationOrderId): void
    {
        $destinationLines = [];
        $normalizedSourceLines = [];
        foreach ($sourceLines as $sourceLine) {
            if (!is_array($sourceLine)) {
                continue;
            }

            $sourceLine['conn'] = $conn;
            $normalizedSourceLines[] = $sourceLine;

            $destinationLine = $sourceLine;
            $destinationLine['conn'] = $conn;
            $destinationLine['order_id'] = $destinationOrderId;
            $destinationLine['tenant'] = (int) ($destinationOrder['tenant'] ?? $sourceLine['tenant'] ?? 0);
            $destinationLine['branch'] = (int) ($destinationOrder['branch'] ?? $sourceLine['branch'] ?? 0);
            $destinationLines[] = $destinationLine;
        }

        if (!$normalizedSourceLines && !$destinationLines) {
            return;
        }

        $this->recipeLifecycleService->onOrderMerged([
            'conn' => $conn,
            'tenant' => (int) ($destinationOrder['tenant'] ?? $normalizedSourceLines[0]['tenant'] ?? 0),
            'branch' => (int) ($destinationOrder['branch'] ?? $normalizedSourceLines[0]['branch'] ?? 0),
            'store_id' => max(0, (int) ($destinationOrder['store_id'] ?? 0)),
            'channel' => 'table',
            'order_type' => 'dine_in',
            'reason' => 'table_merged',
            'source_lines' => $normalizedSourceLines,
            'destination_lines' => $destinationLines,
        ]);
    }

    private function recipeLineQuantity(array $row): string
    {
        $uVal = RecipeDecimal::normalize($row['u_val'] ?? '1');
        if (RecipeDecimal::compare($uVal, '0') <= 0) {
            $uVal = '1.000000';
        }

        if (array_key_exists('qty_out', $row) || array_key_exists('qty_in', $row)) {
            $qtyOut = RecipeDecimal::normalize($row['qty_out'] ?? '0');
            $qtyIn = RecipeDecimal::normalize($row['qty_in'] ?? '0');
            $difference = RecipeDecimal::compare($qtyOut, $qtyIn) >= 0
                ? RecipeDecimal::subtract($qtyOut, $qtyIn)
                : RecipeDecimal::subtract($qtyIn, $qtyOut);

            return RecipeDecimal::divide($difference, $uVal);
        }

        return '1.000000';
    }

    private function nullableString($value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function paidStatus(float $net, float $paid): array
    {
        $paid = min(max(0, $paid), max(0, $net));
        $remaining = max(0, $net - $paid);
        if ($paid <= 0) {
            $paymentStatus = 'unpaid';
            $invoiceStatus = 'draft';
            $orderStatus = 'active';
        } elseif ($remaining <= 0.0001) {
            $paymentStatus = 'paid';
            $invoiceStatus = 'completed';
            $orderStatus = 'completed';
        } else {
            $paymentStatus = 'partial';
            $invoiceStatus = 'draft';
            $orderStatus = 'active';
        }

        return [
            'paid_amount' => $paid,
            'remaining_amount' => $remaining,
            'payment_status' => $paymentStatus,
            'invoice_status' => $invoiceStatus,
            'order_status' => $orderStatus,
        ];
    }

    private function setTableFreeIfNoActiveOrder(mysqli $conn, int $tableId): bool
    {
        $row = $this->tableOrderService->queryOne($conn, "
            SELECT COUNT(*) AS active_count
            FROM ot_head
            WHERE table_id = ?
              AND pro_tybe = 9
              AND isdeleted = 0
              AND COALESCE(order_status, 'active') = 'active'
              AND COALESCE(payment_status, 'unpaid') IN ('unpaid', 'partial')
        ", [$tableId]);
        if ((int) ($row['active_count'] ?? 0) > 0) {
            return false;
        }

        $this->tableOrderService->execute($conn, "UPDATE tables SET table_case = 0 WHERE id = ?", [$tableId]);

        return true;
    }

    private function markTableOccupied(mysqli $conn, int $tableId): void
    {
        $this->tableOrderService->execute($conn, "UPDATE tables SET table_case = 1 WHERE id = ?", [$tableId]);
    }

    private function recordEvent(mysqli $conn, int $orderId, string $type, array $context, array $beforeState, array $afterState, array $metadata): void
    {
        $this->orderEventService->recordIfAvailable($conn, $orderId, $type, $context['event_source'] ?? 'pos_table_merge', [
            'actor_user_id' => $context['user_id'] ?? null,
            'tenant' => $context['tenant'] ?? 0,
            'branch' => $context['branch'] ?? 0,
            'before_state' => $beforeState,
            'after_state' => $afterState,
            'metadata' => $metadata,
        ]);
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
