<?php

require_once __DIR__ . '/PosCustomerOrderLinkService.php';
require_once __DIR__ . '/PosCustomerService.php';
require_once __DIR__ . '/OrderFulfillmentService.php';

class PosCustomerOrderSideEffects
{
    private PosCustomerOrderLinkService $linkService;
    private PosCustomerService $customerService;
    private OrderFulfillmentService $fulfillmentService;

    public function __construct(
        ?PosCustomerOrderLinkService $linkService = null,
        ?PosCustomerService $customerService = null,
        ?OrderFulfillmentService $fulfillmentService = null
    ) {
        $this->linkService = $linkService ?: new PosCustomerOrderLinkService();
        $this->customerService = $customerService ?: new PosCustomerService();
        $this->fulfillmentService = $fulfillmentService ?: new OrderFulfillmentService();
    }

    /**
     * Link order to CRM customer from request payload.
     *
     * @return array{linked:bool,customer_id:int,reason:?string}
     */
    public function linkFromRequest(mysqli $conn, int $orderId, array $request, string $fulfillmentType, array $options = []): array
    {
        if ($orderId < 1) {
            return ['linked' => false, 'customer_id' => 0, 'reason' => 'ORDER_ID_REQUIRED'];
        }

        $customerId = $this->linkService->resolveCustomerIdFromRequest($request);
        if ($customerId < 1) {
            return ['linked' => false, 'customer_id' => 0, 'reason' => 'CUSTOMER_ID_MISSING'];
        }

        try {
            $result = $this->linkService->linkOrder($conn, $orderId, $request + ['pos_customer_id' => $customerId], $fulfillmentType, $options);
            if ($result === null) {
                return ['linked' => false, 'customer_id' => $customerId, 'reason' => 'LINK_SKIPPED'];
            }

            return ['linked' => true, 'customer_id' => $customerId, 'reason' => null];
        } catch (Throwable $exception) {
            error_log('POS CRM link failed: ' . $exception->getMessage());

            return ['linked' => false, 'customer_id' => $customerId, 'reason' => $exception->getMessage()];
        }
    }

    /**
     * Idempotent rollup: lifetime_paid += payment delta; orders_count++ once when fully paid.
     *
     * @return array{applied:bool,customer_id:int,paid_delta:float,orders_counted:bool,reason:?string}
     */
    public function applyPaymentRollup(mysqli $conn, int $orderId, array $options = []): array
    {
        $ownsTransaction = $this->ownsTransaction($options);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }
        try {
            $result = $this->applyPaymentRollupInsideTransaction($conn, $orderId, array_merge($options, [
                'in_transaction' => true,
            ]));
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

    private function applyPaymentRollupInsideTransaction(mysqli $conn, int $orderId, array $options = []): array
    {
        if ($orderId < 1 || !$this->customerService->tablesReady($conn)) {
            return ['applied' => false, 'customer_id' => 0, 'paid_delta' => 0.0, 'orders_counted' => false, 'reason' => 'NOT_READY'];
        }

        $order = $this->loadOrderPaymentState($conn, $orderId);
        if (!$order) {
            return ['applied' => false, 'customer_id' => 0, 'paid_delta' => 0.0, 'orders_counted' => false, 'reason' => 'ORDER_NOT_FOUND'];
        }

        $paidAmount = array_key_exists('paid_amount', $options)
            ? (float) $options['paid_amount']
            : (float) ($order['paid_amount'] ?? 0);
        $paymentStatus = (string) ($options['payment_status'] ?? $order['payment_status'] ?? 'unpaid');
        $isFullyPaid = strtolower($paymentStatus) === 'paid';

        $fulfillment = $this->fulfillmentService->fulfillmentForOrder($conn, $orderId);
        $customerId = (int) ($fulfillment['pos_customer_id'] ?? 0);
        if ($customerId < 1 && !empty($options['request']) && is_array($options['request'])) {
            $link = $this->linkFromRequest(
                $conn,
                $orderId,
                $options['request'],
                (string) ($options['fulfillment_type'] ?? 'takeaway'),
                $options
            );
            if ($link['linked']) {
                $fulfillment = $this->fulfillmentService->fulfillmentForOrder($conn, $orderId);
                $customerId = (int) ($fulfillment['pos_customer_id'] ?? 0);
            }
        }

        if ($customerId < 1) {
            return ['applied' => false, 'customer_id' => 0, 'paid_delta' => 0.0, 'orders_counted' => false, 'reason' => 'CUSTOMER_NOT_LINKED'];
        }

        if ($paidAmount <= 0) {
            return ['applied' => false, 'customer_id' => $customerId, 'paid_delta' => 0.0, 'orders_counted' => false, 'reason' => 'NO_PAYMENT'];
        }

        $rollupPaid = $this->rollupPaidAmount($conn, $orderId, $fulfillment);
        $rollupCounted = $this->rollupCounted($conn, $orderId, $fulfillment);
        $delta = round($paidAmount - $rollupPaid, 3);
        $ordersCounted = false;

        if ($delta > 0) {
            $this->customerService->applyRollupDelta($conn, $customerId, $delta, false, array_merge($options, [
                'in_transaction' => true,
                'defer_sync' => true,
            ]));
            $rollupPaid += $delta;
            $this->updateRollupPaidAmount($conn, $orderId, $rollupPaid);
        }

        if ($isFullyPaid && !$rollupCounted) {
            $this->customerService->applyRollupDelta($conn, $customerId, 0.0, true, array_merge($options, [
                'in_transaction' => true,
                'defer_sync' => true,
            ]));
            $this->updateRollupCounted($conn, $orderId, true);
            $ordersCounted = true;
        }

        if ($delta <= 0 && !$ordersCounted) {
            return ['applied' => false, 'customer_id' => $customerId, 'paid_delta' => 0.0, 'orders_counted' => false, 'reason' => 'ALREADY_ROLLED_UP'];
        }

        $this->customerService->recordSyncSnapshot($conn, $customerId, $options + [
            'event_type' => 'customer.order_rollup',
            'source_system' => 'pos_order_customer_rollup',
        ]);

        return [
            'applied' => true,
            'customer_id' => $customerId,
            'paid_delta' => max(0.0, $delta),
            'orders_counted' => $ordersCounted,
            'reason' => null,
        ];
    }

    /**
     * Link customer (if present) then apply payment rollup.
     */
    public function afterOrderSaved(
        mysqli $conn,
        int $orderId,
        array $request,
        string $fulfillmentType,
        array $options = []
    ): array {
        $ownsTransaction = $this->ownsTransaction($options);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }
        try {
            $link = $this->linkFromRequest($conn, $orderId, $request, $fulfillmentType, $options);
            $rollup = $this->applyPaymentRollup($conn, $orderId, array_merge($options, [
                'in_transaction' => true,
                'request' => $request,
                'fulfillment_type' => $fulfillmentType,
                'paid_amount' => $options['paid_amount'] ?? null,
                'payment_status' => $options['payment_status'] ?? null,
            ]));
            if ($ownsTransaction) {
                $conn->commit();
            }

            return ['link' => $link, 'rollup' => $rollup];
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }
    }

    public function rebuildCustomerRollups(mysqli $conn, int $customerId, array $options = []): array
    {
        if ($customerId < 1 || !$this->customerService->tablesReady($conn)) {
            return ['orders_count' => 0, 'lifetime_paid' => 0.0, 'last_order_at' => null, 'orders_reset' => 0];
        }

        if (!$this->columnExists($conn, 'order_fulfillment', 'pos_customer_id')
            || !$this->columnExists($conn, 'ot_head', 'id')) {
            return ['orders_count' => 0, 'lifetime_paid' => 0.0, 'last_order_at' => null, 'orders_reset' => 0];
        }

        $hasRollupColumns = $this->columnExists($conn, 'order_fulfillment', 'crm_rollup_paid_amount');

        $ownsTransaction = $this->ownsTransaction($options);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }
        try {
            $refundExpression = $this->tableExists($conn, 'credit_notes')
                ? "(SELECT COALESCE(SUM(cn.total_amount), 0)
                      FROM credit_notes cn
                     WHERE cn.original_order_id = o.id AND cn.status = 'posted')"
                : '0';
            $stmt = $conn->prepare("
                SELECT
                    o.id AS order_id,
                    o.paid_amount,
                    o.payment_status,
                    {$refundExpression} AS refunded_amount,
                    COALESCE(o.mdtime, o.crtime, o.pro_date) AS order_time
                FROM order_fulfillment f
                INNER JOIN ot_head o ON o.id = f.order_id AND o.isdeleted = 0
                WHERE f.pos_customer_id = ?
                ORDER BY order_time ASC
            ");
            $stmt->bind_param('i', $customerId);
            $stmt->execute();
            $result = $stmt->get_result();

            $ordersCount = 0;
            $lifetimePaid = 0.0;
            $lastOrderAt = null;
            $ordersReset = 0;

            while ($row = $result->fetch_assoc()) {
            $orderId = (int) $row['order_id'];
            $paid = max(0.0, (float) ($row['paid_amount'] ?? 0) - (float) ($row['refunded_amount'] ?? 0));
            $isPaid = in_array(strtolower((string) ($row['payment_status'] ?? '')), ['paid', 'refunded'], true);
            $orderTime = $row['order_time'] ?? null;

            if ($paid > 0) {
                $lifetimePaid += $paid;
            }
            if ($isPaid) {
                $ordersCount++;
            }
            if ($orderTime !== null && ($lastOrderAt === null || $orderTime > $lastOrderAt)) {
                $lastOrderAt = $orderTime;
            }

            if ($hasRollupColumns) {
                $this->updateRollupPaidAmount($conn, $orderId, $paid);
                $this->updateRollupCounted($conn, $orderId, $isPaid);
                $ordersReset++;
            }
            }
            $stmt->close();

            $update = $conn->prepare("
            UPDATE pos_customers
            SET orders_count = ?,
                lifetime_paid = ?,
                last_order_at = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
              AND isdeleted = 0
        ");
            $update->bind_param('idsi', $ordersCount, $lifetimePaid, $lastOrderAt, $customerId);
            $update->execute();
            $update->close();

            $this->customerService->recordSyncSnapshot($conn, $customerId, $options + [
                'event_type' => 'customer.rollup_rebuilt',
                'source_system' => 'pos_customer_rollup_rebuild',
            ]);

            if ($ownsTransaction) {
                $conn->commit();
            }

            return [
                'orders_count' => $ordersCount,
                'lifetime_paid' => $lifetimePaid,
                'last_order_at' => $lastOrderAt,
                'orders_reset' => $ordersReset,
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }
            throw $exception;
        }
    }

    public function liveStatsFromFulfillment(mysqli $conn, int $customerId): ?array
    {
        if ($customerId < 1 || !$this->columnExists($conn, 'order_fulfillment', 'pos_customer_id')) {
            return null;
        }
        if (!$this->columnExists($conn, 'ot_head', 'id')) {
            return null;
        }

        $refundExpression = $this->tableExists($conn, 'credit_notes')
            ? "(SELECT COALESCE(SUM(cn.total_amount), 0)
                  FROM credit_notes cn
                 WHERE cn.original_order_id = o.id AND cn.status = 'posted')"
            : '0';
        $stmt = $conn->prepare("
            SELECT
                COUNT(*) AS linked_orders,
                SUM(CASE WHEN o.payment_status IN ('paid', 'refunded') THEN 1 ELSE 0 END) AS paid_orders,
                COALESCE(SUM(GREATEST(0, o.paid_amount - {$refundExpression})), 0) AS lifetime_paid,
                MAX(COALESCE(o.mdtime, o.crtime, o.pro_date)) AS last_order_at
            FROM order_fulfillment f
            INNER JOIN ot_head o ON o.id = f.order_id AND o.isdeleted = 0
            WHERE f.pos_customer_id = ?
        ");
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || (int) ($row['linked_orders'] ?? 0) === 0) {
            return null;
        }

        return [
            'orders_count' => (int) ($row['paid_orders'] ?? 0),
            'lifetime_paid' => (float) ($row['lifetime_paid'] ?? 0),
            'last_order_at' => $row['last_order_at'] ?? null,
            'linked_orders' => (int) ($row['linked_orders'] ?? 0),
        ];
    }

    /** Refresh the linked customer's materialized rollup after a refund. */
    public function refreshCustomerRollupForOrder(mysqli $conn, int $orderId, array $options = []): array
    {
        if ($orderId < 1 || !$this->tableExists($conn, 'order_fulfillment')) {
            return ['applied' => false, 'customer_id' => 0, 'reason' => 'CUSTOMER_NOT_LINKED'];
        }
        $stmt = $conn->prepare('SELECT pos_customer_id FROM order_fulfillment WHERE order_id = ? LIMIT 1');
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $customerId = (int) ($row['pos_customer_id'] ?? 0);
        if ($customerId < 1) {
            return ['applied' => false, 'customer_id' => 0, 'reason' => 'CUSTOMER_NOT_LINKED'];
        }

        $rollup = $this->rebuildCustomerRollups($conn, $customerId, $options + ['in_transaction' => true]);
        return ['applied' => true, 'customer_id' => $customerId, 'reason' => null, 'rollup' => $rollup];
    }

    private function loadOrderPaymentState(mysqli $conn, int $orderId): ?array
    {
        if (!$this->columnExists($conn, 'ot_head', 'id')) {
            return null;
        }

        $stmt = $conn->prepare("
            SELECT paid_amount, payment_status
            FROM ot_head
            WHERE id = ?
              AND isdeleted = 0
            LIMIT 1
        ");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function rollupPaidAmount(mysqli $conn, int $orderId, ?array $fulfillment): float
    {
        if (is_array($fulfillment) && array_key_exists('crm_rollup_paid_amount', $fulfillment)) {
            return (float) ($fulfillment['crm_rollup_paid_amount'] ?? 0);
        }

        if (!$this->columnExists($conn, 'order_fulfillment', 'crm_rollup_paid_amount')) {
            return 0.0;
        }

        $stmt = $conn->prepare('SELECT crm_rollup_paid_amount FROM order_fulfillment WHERE order_id = ? LIMIT 1');
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (float) ($row['crm_rollup_paid_amount'] ?? 0);
    }

    private function rollupCounted(mysqli $conn, int $orderId, ?array $fulfillment): bool
    {
        if (is_array($fulfillment) && array_key_exists('crm_rollup_counted', $fulfillment)) {
            return (int) ($fulfillment['crm_rollup_counted'] ?? 0) === 1;
        }

        if (!$this->columnExists($conn, 'order_fulfillment', 'crm_rollup_counted')) {
            return false;
        }

        $stmt = $conn->prepare('SELECT crm_rollup_counted FROM order_fulfillment WHERE order_id = ? LIMIT 1');
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['crm_rollup_counted'] ?? 0) === 1;
    }

    private function updateRollupPaidAmount(mysqli $conn, int $orderId, float $amount): void
    {
        if (!$this->columnExists($conn, 'order_fulfillment', 'crm_rollup_paid_amount')) {
            return;
        }

        $stmt = $conn->prepare('UPDATE order_fulfillment SET crm_rollup_paid_amount = ? WHERE order_id = ?');
        $stmt->bind_param('di', $amount, $orderId);
        $stmt->execute();
        $stmt->close();
    }

    private function updateRollupCounted(mysqli $conn, int $orderId, bool $counted): void
    {
        if (!$this->columnExists($conn, 'order_fulfillment', 'crm_rollup_counted')) {
            return;
        }

        $flag = $counted ? 1 : 0;
        $stmt = $conn->prepare('UPDATE order_fulfillment SET crm_rollup_counted = ? WHERE order_id = ?');
        $stmt->bind_param('ii', $flag, $orderId);
        $stmt->execute();
        $stmt->close();
    }

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $stmt = $conn->prepare("
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1
        ");
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $exists;
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $stmt = $conn->prepare("
            SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            LIMIT 1
        ");
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $exists;
    }

    private function ownsTransaction(array $options): bool
    {
        return empty($options['in_transaction']) && empty($options['transaction_started']);
    }
}
