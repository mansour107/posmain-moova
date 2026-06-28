<?php

require_once __DIR__ . '/../../TableOrderService.php';
require_once __DIR__ . '/PaymentService.php';
require_once __DIR__ . '/TableStateService.php';
require_once __DIR__ . '/InventoryMovementService.php';
require_once __DIR__ . '/OrderEventService.php';
require_once __DIR__ . '/IdempotencyService.php';
require_once __DIR__ . '/ItemAvailabilityService.php';
require_once __DIR__ . '/ManagerApprovalService.php';
require_once __DIR__ . '/ModifierLineNoteService.php';
require_once __DIR__ . '/../../Sync/SyncOutboxEventService.php';
require_once __DIR__ . '/../../Moova/MoovaNewOrderApplyService.php';
require_once __DIR__ . '/../../Moova/MoovaChangeOrderApplyService.php';
require_once __DIR__ . '/../../Recipe/RecipeDecimal.php';
require_once __DIR__ . '/../../Recipe/RecipeOrderLifecycleService.php';
require_once __DIR__ . '/../../Recipe/RecipeSettingsService.php';
require_once __DIR__ . '/../../Recipe/RecipeAuditService.php';
require_once __DIR__ . '/../../Recipe/DTO/RecipeActorContext.php';
require_once __DIR__ . '/../../Inventory/InventoryInvoiceBridge.php';
require_once __DIR__ . '/PosCustomerOrderLinkService.php';
require_once __DIR__ . '/PosCustomerOrderSideEffects.php';
require_once __DIR__ . '/PosCustomerService.php';
require_once __DIR__ . '/DeliveryZoneService.php';
require_once __DIR__ . '/OrderFulfillmentService.php';
require_once __DIR__ . '/SideEffectPolicy.php';
require_once __DIR__ . '/../../../includes/pos_user_context.php';
require_once __DIR__ . '/../../PosOrderService.php';
require_once __DIR__ . '/../../../includes/pos_default_accounts.php';

class PosOrderMutationService
{
    const SCOPE_TABLE_SAVE = 'pos.table.save';
    const SCOPE_TABLE_PAYMENT = 'pos.payment.table';
    const SCOPE_SPLIT_PAYMENT = 'pos.payment.split';
    const SCOPE_ORDER_CANCEL = 'pos.order.cancel';
    const SCOPE_ORDER_REFUND = 'pos.order.refund';
    const SCOPE_ORDER_VOID = 'pos.order.void';
    const SCOPE_TAKEAWAY_CREATE = 'pos.order.create.takeaway';
    const SCOPE_DELIVERY_CREATE = 'pos.order.create.delivery';
    const SCOPE_ORDER_UPDATE = 'pos.order.update';
    const SCOPE_TABLE_FREE = 'pos.table.free';
    const SCOPE_DELIVERY_CANCEL = 'pos.order.cancel.delivery';
    const SCOPE_MOOVA_CONFIRM = 'moova.order.confirm';
    const SCOPE_MOOVA_CHANGE = 'moova.order.change';
    const SCOPE_COFE_CREATE = 'pos.integration.cofe.create';
    const PAYMENT_ROUNDING_TOLERANCE = 0.01;

    private $paymentService;
    private $tableStateService;
    private $tableOrderService;
    private $inventoryMovementService;
    private $orderEventService;
    private $idempotencyService;
    private $itemAvailabilityService;
    private $managerApprovalService;
    private $modifierLineNoteService;
    private $recipeLifecycleService;
    private $recipeSettingsService;
    private $recipeAuditService;
    private $inventoryInvoiceBridge;

    public function __construct(?PaymentService $paymentService = null, ?TableStateService $tableStateService = null, ?TableOrderService $tableOrderService = null, ?InventoryMovementService $inventoryMovementService = null, ?OrderEventService $orderEventService = null, ?IdempotencyService $idempotencyService = null, ?ItemAvailabilityService $itemAvailabilityService = null, ?ManagerApprovalService $managerApprovalService = null, ?ModifierLineNoteService $modifierLineNoteService = null, ?RecipeOrderLifecycleService $recipeLifecycleService = null, ?RecipeSettingsService $recipeSettingsService = null, ?RecipeAuditService $recipeAuditService = null, ?InventoryInvoiceBridge $inventoryInvoiceBridge = null)
    {
        $this->paymentService = $paymentService ?: new PaymentService();
        $this->tableStateService = $tableStateService ?: new TableStateService();
        $this->tableOrderService = $tableOrderService ?: new TableOrderService();
        $this->inventoryMovementService = $inventoryMovementService ?: new InventoryMovementService();
        $this->orderEventService = $orderEventService ?: new OrderEventService();
        $this->idempotencyService = $idempotencyService ?: new IdempotencyService();
        $this->itemAvailabilityService = $itemAvailabilityService ?: new ItemAvailabilityService();
        $this->managerApprovalService = $managerApprovalService ?: new ManagerApprovalService();
        $this->modifierLineNoteService = $modifierLineNoteService ?: new ModifierLineNoteService();
        $this->recipeLifecycleService = $recipeLifecycleService ?: new RecipeOrderLifecycleService();
        $this->recipeSettingsService = $recipeSettingsService ?: new RecipeSettingsService();
        $this->recipeAuditService = $recipeAuditService ?: new RecipeAuditService();
        $this->inventoryInvoiceBridge = $inventoryInvoiceBridge ?: new InventoryInvoiceBridge();
    }

    public function payTableOrder(mysqli $conn, array $request, array $context = []): array
    {
        $result = $this->paymentService->payTableOrder($conn, $request, $context);
        $orderId = (int) ($result['data']['order_id'] ?? 0);
        if ($orderId > 0 && !empty($result['data']['fully_paid'])) {
            $lines = $this->loadRecipeOrderLineContexts($conn, $orderId, 'table', 'dine_in', $request, $context);
            $this->recordRecipeOrderPaid($conn, $orderId, $lines, 'table', 'dine_in', $request, $context);
        }
        if ($orderId > 0) {
            $this->customerSideEffects()->afterOrderSaved($conn, $orderId, $request, 'table', [
                'paid_amount' => (float) ($result['data']['paid_amount'] ?? 0),
                'payment_status' => (string) ($result['data']['payment_status'] ?? 'unpaid'),
            ]);
            $this->recordOrderEvent($conn, $orderId, 'order.payment_recorded', $context['event_source'] ?? 'pos_table_payment', $context, [
                'payment_status' => $result['data']['payment_status'] ?? null,
                'order_status' => $result['data']['order_status'] ?? null,
                'paid_amount' => $result['data']['paid_amount'] ?? null,
                'remaining_amount' => $result['data']['remaining_amount'] ?? null,
                'applied_amount' => $result['data']['applied_amount'] ?? null,
            ]);
        }

        return $result;
    }

    public function cancelTableOrder(mysqli $conn, array $request, array $context = []): array
    {
        $orderId = (int) ($request['order_id'] ?? 0);
        $lines = $orderId > 0
            ? $this->loadRecipeOrderLineContexts($conn, $orderId, 'table', 'dine_in', $request, $context)
            : [];
        $this->recordRecipeOrderLinesCancelled($conn, $lines, 'order_cancelled');
        $result = $this->tableStateService->cancelActiveOrder($conn, $request, $context);
        $orderId = (int) ($result['data']['order_id'] ?? 0);
        if ($orderId > 0) {
            $this->recordOrderEvent($conn, $orderId, 'order.cancelled', $context['event_source'] ?? 'pos_order_cancel', $context, [
                'table_id' => $result['data']['table_id'] ?? null,
                'table_freed' => $result['data']['table_freed'] ?? null,
                'reason' => $request['reason'] ?? $request['cancellation_reason'] ?? null,
            ]);
        }

        return $result;
    }

    public function cancelDeliveryOrder(mysqli $conn, array $request, array $context = []): array
    {
        $ownsTransaction = empty($context['in_transaction']) && empty($context['transaction_started']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $orderId = $this->requiredPositiveInt($request, 'order_id', 'ORDER_ID_REQUIRED');
            $userId = $this->contextUserId($request, $context);
            $reason = trim((string) ($request['reason'] ?? $request['cancellation_reason'] ?? ''));
            if ($reason === '') {
                $reason = 'delivery_cancelled';
            }
            $force = !empty($request['force']) || !empty($context['force']);

            $order = $this->tableOrderService->queryOne($conn, "
                SELECT id, pro_tybe, order_type, payment_status, invoice_status, order_status,
                       paid_amount, remaining_amount, isdeleted
                FROM ot_head
                WHERE id = ?
                  AND pro_tybe = 9
                LIMIT 1
                FOR UPDATE
            ", [$orderId]);
            if (!$order || (int) ($order['isdeleted'] ?? 0) === 1) {
                throw new RuntimeException('ORDER_NOT_FOUND');
            }
            if (strtolower(trim((string) ($order['order_type'] ?? ''))) !== 'delivery') {
                throw new InvalidArgumentException('ORDER_NOT_DELIVERY');
            }

            $paymentStatus = strtolower(trim((string) ($order['payment_status'] ?? 'unpaid')));
            if (in_array($paymentStatus, ['refunded', 'voided'], true)) {
                throw new RuntimeException('ORDER_ALREADY_CANCELLED');
            }
            if ($paymentStatus === 'paid' || (float) ($order['paid_amount'] ?? 0) > self::PAYMENT_ROUNDING_TOLERANCE) {
                throw new RuntimeException('DELIVERY_CANCEL_PAID_NOT_ALLOWED');
            }

            $fulfillmentService = new OrderFulfillmentService();
            $fulfillment = $fulfillmentService->fulfillmentForOrder($conn, $orderId);
            if (!$fulfillment) {
                throw new RuntimeException('FULFILLMENT_NOT_FOUND');
            }

            $currentStatus = (string) ($fulfillment['delivery_status'] ?? 'pending');
            if ($currentStatus !== 'cancelled') {
                $allowed = ['pending', 'accepted', 'preparing', 'ready'];
                if (!in_array($currentStatus, $allowed, true) && !$force) {
                    throw new InvalidArgumentException('DELIVERY_STATUS_TRANSITION_NOT_ALLOWED');
                }
            }

            $channel = strtolower(trim((string) ($fulfillment['order_channel'] ?? '')));
            $externalOrderId = trim((string) ($fulfillment['external_order_id'] ?? ''));
            if ($channel === 'moova_delivery' && $externalOrderId !== '') {
                $scope = [
                    'tenant' => (int) ($context['tenant'] ?? $request['tenant'] ?? 0),
                    'branch' => (int) ($context['branch'] ?? $request['branch'] ?? 0),
                    'user_id' => $userId,
                ];
                (new PosOrderService())->cancelMoovaDeliveryOrder($conn, $scope, $orderId, $externalOrderId);
                $this->finalizeDeliveryOrderVoid($conn, $orderId, $userId, $reason);
            } else {
                $recipeLines = $this->loadRecipeOrderLineContexts($conn, $orderId, 'pos', 'delivery', $request, $context);
                $this->recordRecipeOrderLinesCancelled($conn, $recipeLines, 'delivery_cancelled');
                $inventoryLines = $this->loadInventoryInvoiceBridgeLines($conn, $orderId);
                if ($inventoryLines) {
                    $this->recordInventoryInvoiceBridgeReversalLines(
                        $conn,
                        $orderId,
                        $inventoryLines,
                        'delivery_cancelled',
                        'pos',
                        'delivery',
                        $request,
                        $context
                    );
                }
                $this->voidDeliveryOrderHeaderAndLines($conn, $orderId, $userId, $reason);
            }

            $fulfillmentResult = $fulfillmentService->upsertForOrder($conn, $orderId, [
                'order_channel' => $fulfillment['order_channel'],
                'fulfillment_type' => $fulfillment['fulfillment_type'],
                'external_provider' => $fulfillment['external_provider'],
                'external_order_id' => $fulfillment['external_order_id'],
                'customer_name' => $fulfillment['customer_name'],
                'customer_phone' => $fulfillment['customer_phone'],
                'customer_address' => $fulfillment['customer_address'],
                'pos_customer_id' => $fulfillment['pos_customer_id'] ?? null,
                'delivery_zone' => $fulfillment['delivery_zone'],
                'delivery_fee' => $fulfillment['delivery_fee'],
                'delivery_status' => 'cancelled',
                'promised_at' => $fulfillment['promised_at'],
                'metadata_json' => is_array($fulfillment['metadata'] ?? null) ? $fulfillment['metadata'] : [],
            ], ['require_table' => false]);

            $this->recordOrderEvent($conn, $orderId, 'order.cancelled', $context['event_source'] ?? 'delivery_dispatch', $context, [
                'order_type' => 'delivery',
                'delivery_status_before' => $currentStatus,
                'delivery_status_after' => 'cancelled',
                'reason' => $reason,
                'order_channel' => $channel,
            ]);

            if ($ownsTransaction) {
                $conn->commit();
            }

            return [
                'success' => true,
                'code' => 'OK',
                'message' => 'DELIVERY_ORDER_CANCELLED',
                'data' => [
                    'order_id' => $orderId,
                    'fulfillment' => $fulfillmentResult,
                    'payment_status' => 'voided',
                    'invoice_status' => 'cancelled',
                    'order_status' => 'cancelled',
                ],
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }

            throw $exception;
        }
    }

    public function resolveDeliveryPostedTotals(mysqli $conn, array $request): array
    {
        $headDiscount = (float) ($request['headdisc'] ?? $request['discount'] ?? 0);
        $zoneResolved = (new DeliveryZoneService())->resolvePostedZone($conn, $request);
        $deliveryFee = max(0, (float) ($zoneResolved['delivery_fee'] ?? 0));
        $deliveryZoneName = trim((string) ($zoneResolved['delivery_zone_name'] ?? ''));
        $headPlus = (float) ($request['headplus'] ?? $request['plus'] ?? 0);
        if ($deliveryFee > $headPlus) {
            $headPlus = $deliveryFee;
        }

        $lineSubtotal = $this->sumPostedItemSubtotal($request);
        $headTotal = $lineSubtotal > 0
            ? $lineSubtotal
            : (float) ($request['headtotal'] ?? $request['total'] ?? 0);
        $headNet = max(0, $headTotal - $headDiscount + $headPlus);

        return [
            'headtotal' => $headTotal,
            'headdisc' => $headDiscount,
            'headplus' => $headPlus,
            'headnet' => $headNet,
            'delivery_fee' => $deliveryFee,
            'delivery_zone_name' => $deliveryZoneName,
            'delivery_zone_id' => $zoneResolved['delivery_zone_id'] ?? null,
        ];
    }

    public function reversePaidOrder(mysqli $conn, array $request, array $context = []): array
    {
        $ownsTransaction = empty($context['in_transaction']) && empty($context['transaction_started']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $orderId = $this->requiredPositiveInt($request, 'order_id', 'ORDER_ID_REQUIRED');
            $action = strtolower(trim((string) ($request['action'] ?? 'refund')));
            if (!in_array($action, ['refund', 'void'], true)) {
                throw new InvalidArgumentException('ORDER_REVERSAL_ACTION_INVALID');
            }

            $order = $this->tableOrderService->queryOne($conn, "
                SELECT id, table_id, pro_tybe, order_type, payment_status, invoice_status,
                       order_status, fat_net, paid_amount, remaining_amount, isdeleted
                FROM ot_head
                WHERE id = ?
                  AND pro_tybe = 9
                LIMIT 1
                FOR UPDATE
            ", [$orderId]);
            if (!$order || (int) ($order['isdeleted'] ?? 0) === 1) {
                throw new RuntimeException('ORDER_NOT_FOUND');
            }

            $paymentStatus = strtolower(trim((string) ($order['payment_status'] ?? 'unpaid')));
            if (in_array($paymentStatus, ['refunded', 'voided'], true)) {
                throw new RuntimeException('ORDER_ALREADY_REVERSED');
            }
            if ($paymentStatus !== 'paid') {
                throw new RuntimeException('ORDER_NOT_PAID');
            }

            $userId = $this->contextUserId($request, $context);
            $amount = max(0.0, (float) ($order['paid_amount'] ?? $order['fat_net'] ?? 0));
            $this->managerApprovalService->requireApprovedIfNeeded(
                $conn,
                $action === 'void' ? 'pos.void.paid' : 'pos.refund',
                'order',
                $orderId,
                $amount,
                $request,
                $context
            );

            [$channel, $orderType] = $this->recipeChannelAndOrderType((string) ($order['order_type'] ?? ''));
            $lines = $this->loadRecipeOrderLineContexts($conn, $orderId, $channel, $orderType, $request, $context);
            $recipeResult = $this->recordRecipePaidOrderReversal(
                $conn,
                $orderId,
                $lines,
                $channel,
                $orderType,
                $action,
                $request,
                $context
            );

            $newPaymentStatus = $action === 'void' ? 'voided' : 'refunded';
            $reason = trim((string) ($request['reason'] ?? $request['refund_reason'] ?? $request['void_reason'] ?? ''));
            if ($reason === '') {
                $reason = $action === 'void' ? 'paid_order_voided' : 'paid_order_refunded';
            }

            $this->tableOrderService->execute($conn, "
                UPDATE ot_head
                SET payment_status = ?,
                    invoice_status = 'cancelled',
                    order_status = 'cancelled',
                    remaining_amount = 0,
                    isdeleted = CASE WHEN ? = 'void' THEN 1 ELSE isdeleted END,
                    cancelled_at = NOW(),
                    cancelled_by = ?,
                    cancellation_reason = ?,
                    updated_by = ?,
                    mdtime = NOW()
                WHERE id = ?
            ", [$newPaymentStatus, $action, $userId, $reason, $userId, $orderId]);

            $tableId = (int) ($order['table_id'] ?? 0);
            if ($tableId > 0) {
                $this->tableOrderService->setTableFreeIfNoActiveOrder($conn, $tableId);
            }

            $eventType = $action === 'void' ? 'order.voided' : 'order.refunded';
            $policy = $this->resolveRecipeRefundPolicy($request, $context);
            $this->recordOrderEvent($conn, $orderId, $eventType, $context['event_source'] ?? 'pos_paid_reversal', $context, [
                'payment_status_before' => $paymentStatus,
                'payment_status_after' => $newPaymentStatus,
                'refund_stock_policy' => $policy,
                'reason' => $reason,
                'amount' => $amount,
            ]);

            if ($ownsTransaction) {
                $conn->commit();
            }

            return [
                'success' => true,
                'code' => 'OK',
                'message' => $action === 'void' ? 'ORDER_VOIDED' : 'ORDER_REFUNDED',
                'data' => [
                    'order_id' => $orderId,
                    'table_id' => $tableId,
                    'action' => $action,
                    'payment_status' => $newPaymentStatus,
                    'invoice_status' => 'cancelled',
                    'order_status' => 'cancelled',
                    'refund_stock_policy' => $policy,
                    'recipe' => $recipeResult,
                ],
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }

            throw $exception;
        }
    }

    public function saveTableOrder(mysqli $conn, array $request, array $context = []): array
    {
        $ownsTransaction = empty($context['in_transaction']) && empty($context['transaction_started']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $result = $this->saveTableOrderInsideTransaction($conn, $request, $context);
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

    public function splitTablePayment(mysqli $conn, array $request, array $context = []): array
    {
        $ownsTransaction = empty($context['in_transaction']) && empty($context['transaction_started']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $result = $this->splitTablePaymentInsideTransaction($conn, $request, $context);
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

    public function createTakeawayOrder(mysqli $conn, array $request, array $context = []): array
    {
        if (!empty($context['skip_idempotency'])) {
            return $this->createTakeawayOrderInsideTransaction($conn, $request, $context);
        }

        $ownsTransaction = empty($context['in_transaction']) && empty($context['transaction_started']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $idempotency = $this->beginIdempotency($conn, self::SCOPE_TAKEAWAY_CREATE, $request, $context);
            if ($idempotency['status'] === 'completed') {
                if ($ownsTransaction) {
                    $conn->commit();
                }

                $response = is_array($idempotency['response'] ?? null) ? $idempotency['response'] : [];
                $response['idempotency_replayed'] = true;
                return $response;
            }

            $result = $this->createTakeawayOrderInsideTransaction($conn, $request, $context);
            $this->idempotencyService->complete($conn, self::SCOPE_TAKEAWAY_CREATE, $idempotency['key'], $idempotency['hash'], $result);
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

    public function createDeliveryOrder(mysqli $conn, array $request, array $context = []): array
    {
        if (!empty($context['skip_idempotency'])) {
            return $this->createDeliveryOrderInsideTransaction($conn, $request, $context);
        }

        $ownsTransaction = empty($context['in_transaction']) && empty($context['transaction_started']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $idempotency = $this->beginIdempotency($conn, self::SCOPE_DELIVERY_CREATE, $request, $context);
            if ($idempotency['status'] === 'completed') {
                if ($ownsTransaction) {
                    $conn->commit();
                }

                $response = is_array($idempotency['response'] ?? null) ? $idempotency['response'] : [];
                $response['idempotency_replayed'] = true;
                return $response;
            }

            $result = $this->createDeliveryOrderInsideTransaction($conn, $request, $context);
            $this->idempotencyService->complete($conn, self::SCOPE_DELIVERY_CREATE, $idempotency['key'], $idempotency['hash'], $result);
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

    public function updateCashierOrder(mysqli $conn, array $request, array $context = []): array
    {
        if (!empty($context['skip_idempotency'])) {
            return $this->updateCashierOrderInsideTransaction($conn, $request, $context);
        }

        $ownsTransaction = empty($context['in_transaction']) && empty($context['transaction_started']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $idempotency = $this->beginIdempotency($conn, self::SCOPE_ORDER_UPDATE, $request, $context);
            if ($idempotency['status'] === 'completed') {
                if ($ownsTransaction) {
                    $conn->commit();
                }

                $response = is_array($idempotency['response'] ?? null) ? $idempotency['response'] : [];
                $response['idempotency_replayed'] = true;
                return $response;
            }

            $result = $this->updateCashierOrderInsideTransaction($conn, $request, $context);
            $this->idempotencyService->complete($conn, self::SCOPE_ORDER_UPDATE, $idempotency['key'], $idempotency['hash'], $result);
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

    public function freeTable(mysqli $conn, array $request, array $context = []): array
    {
        if (!empty($context['skip_idempotency'])) {
            return $this->freeTableInsideTransaction($conn, $request, $context);
        }

        $ownsTransaction = empty($context['in_transaction']) && empty($context['transaction_started']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $idempotency = $this->beginIdempotency($conn, self::SCOPE_TABLE_FREE, $request, $context);
            if ($idempotency['status'] === 'completed') {
                if ($ownsTransaction) {
                    $conn->commit();
                }

                $response = is_array($idempotency['response'] ?? null) ? $idempotency['response'] : [];
                $response['idempotency_replayed'] = true;
                return $response;
            }

            $result = $this->freeTableInsideTransaction($conn, $request, $context);
            $this->idempotencyService->complete($conn, self::SCOPE_TABLE_FREE, $idempotency['key'], $idempotency['hash'], $result);
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

    public function confirmMoovaOrder(mysqli $conn, array $request, array $context = []): array
    {
        $link = $request['link'] ?? $context['link'] ?? null;
        $payload = $request['payload'] ?? $request;
        if (!is_array($link)) {
            throw new InvalidArgumentException('MOOVA_LINK_REQUIRED');
        }
        if (!is_array($payload)) {
            throw new InvalidArgumentException('INVALID_PAYLOAD');
        }

        return (new MoovaNewOrderApplyService())->applyInTransaction($conn, $link, $payload, $context);
    }

    public function changeMoovaOrder(mysqli $conn, array $request, array $context = []): array
    {
        $link = $request['link'] ?? $context['link'] ?? null;
        $payload = $request['payload'] ?? $request;
        if (!is_array($link)) {
            throw new InvalidArgumentException('MOOVA_LINK_REQUIRED');
        }
        if (!is_array($payload)) {
            throw new InvalidArgumentException('INVALID_PAYLOAD');
        }

        $result = (new MoovaChangeOrderApplyService())->applyInTransaction($conn, $link, $payload, $context);
        if (
            strtolower((string) ($context['action'] ?? $payload['action'] ?? '')) === 'cancel'
            && (string) ($result['status'] ?? '') === 'applied'
        ) {
            $tableId = (int) ($result['response']['tableId'] ?? $result['response']['table_id'] ?? 0);
            if ($tableId > 0) {
                $this->tableOrderService->setTableFreeIfNoActiveOrder($conn, $tableId);
            }
        }

        return $result;
    }

    private function beginIdempotency(mysqli $conn, string $scope, array $request, array $context): array
    {
        $key = $this->idempotencyService->resolveKey($request, [], $context);
        $hash = $this->idempotencyService->requestHashForPayload($request);
        $begin = $this->idempotencyService->begin($conn, $scope, $key, $hash, [
            'user_id' => $context['user_id'] ?? $request['user_id'] ?? null,
            'tenant' => $context['tenant'] ?? $request['tenant'] ?? 0,
            'branch' => $context['branch'] ?? $request['branch'] ?? 0,
            'stale_after_seconds' => $context['idempotency_stale_after_seconds'] ?? 300,
        ]);

        if (($begin['status'] ?? '') === 'conflict') {
            throw new RuntimeException('IDEMPOTENCY_CONFLICT');
        }
        if (!in_array($begin['status'] ?? '', ['started', 'reclaimed', 'completed'], true)) {
            throw new RuntimeException('IDEMPOTENCY_PROCESSING');
        }

        return [
            'key' => $key,
            'hash' => $hash,
            'status' => $begin['status'],
            'response' => $begin['response'] ?? null,
        ];
    }

    private function freeTableInsideTransaction(mysqli $conn, array $request, array $context): array
    {
        $tableId = $this->requiredPositiveInt($request, 'table_id', 'الرجاء اختيار طاولة');
        if (!$this->tableOrderService->setTableFreeIfNoActiveOrder($conn, $tableId)) {
            throw new InvalidArgumentException('لا يمكن إفراغ الطاولة لأن عليها طلب مفتوح');
        }

        if (!array_key_exists('record_outbox', $context) || $context['record_outbox']) {
            try {
                $syncOutbox = new SyncOutboxEventService();
                $syncOutbox->recordTableSnapshot($conn, $tableId, [
                    'event_type' => 'table.updated',
                    'source_system' => 'pos_cashier_empty_table',
                    'active_order_id' => null,
                ]);
            } catch (Throwable $exception) {
                error_log('POS empty table outbox skipped: ' . $exception->getMessage());
            }
        }

        return [
            'success' => true,
            'code' => 'OK',
            'message' => 'TABLE_FREED',
            'data' => [
                'table_id' => $tableId,
                'updated_state' => [
                    'table_id' => $tableId,
                    'cleared' => true,
                ],
            ],
        ];
    }

    private function updateCashierOrderInsideTransaction(mysqli $conn, array $request, array $context): array
    {
        $orderId = (int) ($request['edit_id'] ?? $request['order_id'] ?? 0);
        if ($orderId < 1) {
            throw new InvalidArgumentException('ORDER_ID_REQUIRED');
        }

        $order = $this->tableOrderService->queryOne($conn, 'SELECT * FROM ot_head WHERE id = ? LIMIT 1', [$orderId]);
        if (!$order) {
            throw new InvalidArgumentException('ORDER_NOT_FOUND');
        }

        $orderType = trim((string) ($order['order_type'] ?? 'takeaway'));
        if ($orderType === 'table') {
            throw new InvalidArgumentException('USE_TABLE_SAVE_FOR_TABLE_ORDERS');
        }

        $request = $this->resolvePosRequestAccounts($conn, $request);
        $storeId = $this->requiredPositiveInt($request, 'store_id', 'بيانات مطلوبة مفقودة - المخزن');
        $customerId = $this->requiredPositiveInt($request, 'acc2_id', 'بيانات مطلوبة مفقودة - العميل');
        $empId = $this->requiredPositiveInt($request, 'emp_id', 'بيانات مطلوبة مفقودة - الموظف');
        $fundId = $this->requiredPositiveInt($request, 'fund_id', 'بيانات مطلوبة مفقودة - الصندوق');
        $items = $this->normalizeTakeawayItems($request);
        $this->assertItemsAvailable($conn, $items, $request, $context);
        $userId = $this->contextUserId($request, $context);
        $date = $this->requestDate($request, ['pro_date', 'order_date', 'date'], (string) ($order['pro_date'] ?? date('Y-m-d')));
        $accrualDate = $this->requestDate($request, ['accural_date', 'accrual_date'], $date);
        $headTotal = (float) ($request['headtotal'] ?? $request['total'] ?? 0);
        $headDiscount = (float) ($request['headdisc'] ?? $request['discount'] ?? 0);
        $this->requireDiscountApprovalIfNeeded($conn, $orderId, $headDiscount, $request, $context);
        $headPlus = (float) ($request['headplus'] ?? $request['plus'] ?? 0);
        $headNet = (float) ($request['headnet'] ?? $request['net'] ?? max(0, $headTotal - $headDiscount + $headPlus));
        if ($orderType === 'delivery') {
            $deliveryName = trim((string) ($request['delivery_customer_name'] ?? ''));
            $deliveryPhone = trim((string) ($request['delivery_customer_phone'] ?? ''));
            $deliveryAddress = trim((string) ($request['delivery_customer_address'] ?? ''));
            if ($deliveryName === '' || $deliveryPhone === '' || $deliveryAddress === '') {
                throw new InvalidArgumentException('يجب إدخال بيانات عميل الدليفري');
            }
            $posCustomerService = new PosCustomerService();
            $postedCustomerId = (int) ($request['pos_customer_id'] ?? 0);
            if ($postedCustomerId > 0 && $posCustomerService->getProfile($conn, $postedCustomerId, false)) {
                $request['pos_customer_id'] = $postedCustomerId;
            } else {
                $zoneId = (int) ($request['delivery_zone_id'] ?? 0);
                $upserted = $posCustomerService->upsertForDelivery($conn, $deliveryPhone, $deliveryName, $deliveryAddress, $zoneId > 0 ? $zoneId : null);
                $request['pos_customer_id'] = (int) ($upserted['id'] ?? 0);
            }
            $resolvedTotals = $this->resolveDeliveryPostedTotals($conn, $request);
            $headPlus = (float) $resolvedTotals['headplus'];
            $headTotal = (float) $resolvedTotals['headtotal'];
            $headNet = (float) $resolvedTotals['headnet'];
        }
        if ($headNet < 0) {
            throw new InvalidArgumentException('PAYMENT_AMOUNT_INVALID');
        }

        $paidCash = max(0, (float) ($request['paid_cash'] ?? $request['paid'] ?? 0));
        $paidBank = max(0, (float) ($request['paid_bank'] ?? 0));
        $paymentFundId = (int) ($request['payment_fund_id'] ?? $fundId);
        $paymentBankId = (int) ($request['payment_bank_id'] ?? 0);
        $payment = $this->calculateTakeawayPayment($headNet, $paidCash, $paidBank);
        if ($payment['cash'] > 0 && $paymentFundId <= 0) {
            throw new InvalidArgumentException('PAYMENT_FUND_REQUIRED');
        }
        if ($payment['bank'] > 0 && $paymentBankId <= 0) {
            throw new InvalidArgumentException('PAYMENT_BANK_REQUIRED');
        }

        $status = $this->paidStatusForNet($headNet, $payment['applied']);
        $proId = (int) ($order['pro_id'] ?? 0);
        $channel = $orderType === 'delivery' ? 'delivery' : 'takeaway';
        $info = $this->tableOrderService->buildInfo($channel, '', (string) ($request['info'] ?? ''));
        $fatDiscPer = $headTotal > 0 && $headDiscount > 0 ? round($headDiscount / $headTotal * 100, 2) : 0.0;
        $fatPlusPer = $headTotal > 0 && $headPlus > 0 ? round($headPlus / $headTotal * 100, 2) : 0.0;

        $recipeChannel = $orderType === 'delivery' ? 'delivery' : 'takeaway';
        $oldRecipeLines = $this->loadRecipeOrderLineContexts($conn, $orderId, 'pos', $recipeChannel, $request, $context);
        $oldInventoryBridgeLines = $this->loadInventoryInvoiceBridgeLines($conn, $orderId);
        $this->recordRecipeOrderLinesCancelled($conn, $oldRecipeLines, 'order_updated');
        $this->recordInventoryInvoiceBridgeReversalLines($conn, $orderId, $oldInventoryBridgeLines, 'order_updated', 'pos', $recipeChannel, $request, $context);
        $this->clearCashierOrderFinancialLinks($conn, $orderId);
        $this->tableOrderService->execute($conn, 'DELETE FROM fat_details WHERE fatid = ?', [$orderId]);

        $this->tableOrderService->execute($conn, "
            UPDATE ot_head SET
                info = ?, accural_date = ?, pro_serial = ?, store_id = ?, emp_id = ?, emp2_id = ?,
                acc1 = ?, acc2 = ?, pro_value = ?, fat_total = ?, fat_disc = ?, fat_disc_per = ?,
                fat_plus = ?, fat_plus_per = ?, fat_net = ?, user = ?, jal_name = ?, jal_notes = ?, jal_amount = ?,
                order_type = ?, payment_status = ?, invoice_status = ?, order_status = ?,
                paid_amount = ?, remaining_amount = ?,
                payment_date = CASE WHEN ? = 'paid' THEN NOW() ELSE NULL END,
                completed_at = CASE WHEN ? = 'completed' THEN NOW() ELSE NULL END
            WHERE id = ?
        ", [
            $info,
            $accrualDate,
            trim((string) ($request['pro_serial'] ?? '')),
            $storeId,
            $empId,
            $empId,
            $fundId,
            $customerId,
            $headTotal,
            $headTotal,
            $headDiscount,
            $fatDiscPer,
            $headPlus,
            $fatPlusPer,
            $headNet,
            $userId,
            $this->nullableString($request['jal_name'] ?? null),
            $this->nullableString($request['jal_notes'] ?? null),
            (float) ($request['jal_amount'] ?? 0),
            $channel,
            $status['payment_status'],
            $status['invoice_status'],
            $status['order_status'],
            $status['paid_amount'],
            $status['remaining_amount'],
            $status['payment_status'],
            $status['order_status'],
            $orderId,
        ]);

        $salesJournal = $this->insertTakeawaySalesJournal($conn, $orderId, $proId, $headNet, $date, $customerId, $userId, (int) ($request['sales_account_id'] ?? 0));
        $receipts = [];
        if ($payment['cash'] > 0) {
            $receipts[] = $this->insertTakeawayReceipt($conn, $orderId, $proId, $info, $date, $empId, $paymentFundId, $customerId, $payment['cash'], 'كاش', $userId);
        }
        if ($payment['bank'] > 0) {
            $receipts[] = $this->insertTakeawayReceipt($conn, $orderId, $proId, $info, $date, $empId, $paymentBankId, $customerId, $payment['bank'], 'صرافة', $userId);
        }

        $lineResult = $this->inventoryMovementService->normalizeInvoiceLines($conn, InventoryMovementService::TYPE_POS, $items, [
            'store_id' => $storeId,
        ]);
        $recipeLines = [];
        $inventoryBridgeLines = [];
        foreach ($lineResult['lines'] as $index => $line) {
            $line['_source_item'] = $items[$index] ?? [];
            $line['note'] = $this->lineNoteFromItem($line['_source_item']);
            $recipeLine = $this->insertTakeawayDetailLine($conn, $orderId, $storeId, $line, $context);
            $recipeLines[] = $recipeLine;
            $inventoryBridgeLines[] = $this->inventoryBridgeLineFromLegacyLine($line, $recipeLine, $storeId);
        }
        $this->recordInventoryInvoiceBridgeLines($conn, $orderId, $inventoryBridgeLines, 'pos', $recipeChannel, $request, $context);
        $this->recordRecipeOrderLinesAdded($conn, $recipeLines);
        if ($status['order_status'] === 'completed') {
            $this->recordRecipeOrderPaid($conn, $orderId, $recipeLines, 'pos', $recipeChannel, $request, $context);
        }
        $this->tableOrderService->execute($conn, 'UPDATE ot_head SET profit = ? WHERE id = ?', [
            (float) $lineResult['totals']['profit'],
            $orderId,
        ]);

        $this->customerSideEffects()->afterOrderSaved($conn, $orderId, $request, $channel, [
            'paid_amount' => (float) $status['paid_amount'],
            'payment_status' => (string) $status['payment_status'],
        ]);

        if (!array_key_exists('record_outbox', $context) || $context['record_outbox']) {
            try {
                $syncOutbox = new SyncOutboxEventService();
                $syncOutbox->recordOrderSnapshot($conn, $orderId, [
                    'event_type' => 'order.updated',
                    'source_system' => $orderType === 'delivery' ? 'pos_cashier_delivery' : 'pos_cashier',
                ]);
            } catch (Throwable $exception) {
                error_log('POS order update outbox skipped: ' . $exception->getMessage());
            }
        }

        $this->recordOrderEvent($conn, $orderId, 'order.updated', $context['event_source'] ?? 'pos_cashier_update', $context, [
            'order_type' => $channel,
            'payment_status' => $status['payment_status'],
            'order_status' => $status['order_status'],
            'paid_amount' => $status['paid_amount'],
            'remaining_amount' => $status['remaining_amount'],
            'line_count' => count($lineResult['lines']),
        ]);

        return [
            'success' => true,
            'code' => 'OK',
            'message' => 'ORDER_UPDATED',
            'data' => [
                'order_id' => $orderId,
                'pro_id' => $proId,
                'payment_status' => $status['payment_status'],
                'invoice_status' => $status['invoice_status'],
                'order_status' => $status['order_status'],
                'paid_amount' => $status['paid_amount'],
                'remaining_amount' => $status['remaining_amount'],
                'profit' => (float) $lineResult['totals']['profit'],
                'journal_head_id' => $salesJournal['journal_head_id'],
                'journal_id' => $salesJournal['journal_id'],
                'receipt_ids' => array_column($receipts, 'receipt_id'),
            ],
        ];
    }

    private function clearCashierOrderFinancialLinks(mysqli $conn, int $orderId): void
    {
        $stmt = $conn->prepare('SELECT id FROM journal_heads WHERE op_id = ?');
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $result = $stmt->get_result();
        $journalIds = [];
        while ($row = $result->fetch_assoc()) {
            $journalIds[] = (int) ($row['id'] ?? 0);
        }
        $stmt->close();

        foreach ($journalIds as $journalId) {
            if ($journalId > 0) {
                $this->tableOrderService->execute($conn, 'DELETE FROM journal_entries WHERE journal_id = ?', [$journalId]);
            }
        }
        $this->tableOrderService->execute($conn, 'DELETE FROM journal_heads WHERE op_id = ?', [$orderId]);
        $this->tableOrderService->execute($conn, 'DELETE FROM ot_head WHERE op2 = ?', [$orderId]);
    }

    private function createTakeawayOrderInsideTransaction(mysqli $conn, array $request, array $context): array
    {
        $request = $this->resolvePosRequestAccounts($conn, $request);
        $storeId = $this->requiredPositiveInt($request, 'store_id', 'بيانات مطلوبة مفقودة - المخزن');
        $customerId = $this->requiredPositiveInt($request, 'acc2_id', 'بيانات مطلوبة مفقودة - العميل');
        $empId = $this->requiredPositiveInt($request, 'emp_id', 'بيانات مطلوبة مفقودة - الموظف');
        $fundId = $this->requiredPositiveInt($request, 'fund_id', 'بيانات مطلوبة مفقودة - الصندوق');
        $items = $this->normalizeTakeawayItems($request);
        $this->assertItemsAvailable($conn, $items, $request, $context);
        $userId = $this->contextUserId($request, $context);
        $date = $this->requestDate($request, ['pro_date', 'order_date', 'date']);
        $accrualDate = $this->requestDate($request, ['accural_date', 'accrual_date'], $date);
        $headTotal = (float) ($request['headtotal'] ?? $request['total'] ?? 0);
        $headDiscount = (float) ($request['headdisc'] ?? $request['discount'] ?? 0);
        $this->requireDiscountApprovalIfNeeded($conn, null, $headDiscount, $request, $context);
        $headPlus = (float) ($request['headplus'] ?? $request['plus'] ?? 0);
        $headNet = (float) ($request['headnet'] ?? $request['net'] ?? max(0, $headTotal - $headDiscount + $headPlus));
        if ($headNet < 0) {
            throw new InvalidArgumentException('PAYMENT_AMOUNT_INVALID');
        }

        $paidCash = max(0, (float) ($request['paid_cash'] ?? $request['paid'] ?? 0));
        $paidBank = max(0, (float) ($request['paid_bank'] ?? 0));
        $paymentFundId = (int) ($request['payment_fund_id'] ?? $fundId);
        $paymentBankId = (int) ($request['payment_bank_id'] ?? 0);
        $payment = $this->calculateTakeawayPayment($headNet, $paidCash, $paidBank);
        if ($payment['cash'] > 0 && $paymentFundId <= 0) {
            throw new InvalidArgumentException('PAYMENT_FUND_REQUIRED');
        }
        if ($payment['bank'] > 0 && $paymentBankId <= 0) {
            throw new InvalidArgumentException('PAYMENT_BANK_REQUIRED');
        }

        $status = $this->paidStatusForNet($headNet, $payment['applied']);
        $proId = $this->nextInvoiceProId($conn, InventoryMovementService::TYPE_POS, 0, 0);
        $info = $this->tableOrderService->buildInfo('takeaway', '', (string) ($request['info'] ?? ''));
        $fatDiscPer = $headTotal > 0 && $headDiscount > 0 ? round($headDiscount / $headTotal * 100, 2) : 0.0;
        $fatPlusPer = $headTotal > 0 && $headPlus > 0 ? round($headPlus / $headTotal * 100, 2) : 0.0;

        $this->tableOrderService->execute($conn, "
            INSERT INTO ot_head (
                pro_id, pro_tybe, is_stock, is_journal, journal_tybe, info, pro_date,
                accural_date, pro_pattren, pro_serial, price_list, store_id, emp_id,
                emp2_id, acc1, acc2, pro_value, fat_cost, cost_center, profit,
                fat_total, fat_disc, fat_disc_per, fat_plus, fat_plus_per,
                fat_tax, fat_tax_per, fat_net, user, jal_name, jal_notes, jal_amount,
                table_id, order_type, payment_status, invoice_status, order_status,
                paid_amount, remaining_amount, waiter_id, payment_date, completed_at
            ) VALUES (
                ?, 9, 1, 1, 9, ?, ?,
                ?, 1, ?, 1, ?, ?,
                ?, ?, ?, ?, 0, 1, 0,
                ?, ?, ?, ?, ?,
                0, 0, ?, ?, ?, ?, ?,
                NULL, 'takeaway', ?, ?, ?,
                ?, ?, ?, CASE WHEN ? = 'paid' THEN NOW() ELSE NULL END,
                CASE WHEN ? = 'completed' THEN NOW() ELSE NULL END
            )
        ", [
            $proId,
            $info,
            $date,
            $accrualDate,
            trim((string) ($request['pro_serial'] ?? '')),
            $storeId,
            $empId,
            $empId,
            $fundId,
            $customerId,
            $headTotal,
            $headTotal,
            $headDiscount,
            $fatDiscPer,
            $headPlus,
            $fatPlusPer,
            $headNet,
            $userId,
            $this->nullableString($request['jal_name'] ?? null),
            $this->nullableString($request['jal_notes'] ?? null),
            (float) ($request['jal_amount'] ?? 0),
            $status['payment_status'],
            $status['invoice_status'],
            $status['order_status'],
            $status['paid_amount'],
            $status['remaining_amount'],
            $empId,
            $status['payment_status'],
            $status['order_status'],
        ]);
        $orderId = (int) $conn->insert_id;
        $this->tableOrderService->assignUuidIfPresent($conn, 'ot_head', $orderId);

        $salesJournal = $this->insertTakeawaySalesJournal($conn, $orderId, $proId, $headNet, $date, $customerId, $userId, (int) ($request['sales_account_id'] ?? 0));
        $receipts = [];
        if ($payment['cash'] > 0) {
            $receipts[] = $this->insertTakeawayReceipt($conn, $orderId, $proId, $info, $date, $empId, $paymentFundId, $customerId, $payment['cash'], 'كاش', $userId);
        }
        if ($payment['bank'] > 0) {
            $receipts[] = $this->insertTakeawayReceipt($conn, $orderId, $proId, $info, $date, $empId, $paymentBankId, $customerId, $payment['bank'], 'صرافة', $userId);
        }

        $lineResult = $this->inventoryMovementService->normalizeInvoiceLines($conn, InventoryMovementService::TYPE_POS, $items, [
            'store_id' => $storeId,
        ]);
        $recipeLines = [];
        $inventoryBridgeLines = [];
        foreach ($lineResult['lines'] as $index => $line) {
            $line['_source_item'] = $items[$index] ?? [];
            $line['note'] = $this->lineNoteFromItem($line['_source_item']);
            $recipeLine = $this->insertTakeawayDetailLine($conn, $orderId, $storeId, $line, $context);
            $recipeLines[] = $recipeLine;
            $inventoryBridgeLines[] = $this->inventoryBridgeLineFromLegacyLine($line, $recipeLine, $storeId);
        }
        $this->recordInventoryInvoiceBridgeLines($conn, $orderId, $inventoryBridgeLines, 'pos', 'takeaway', $request, $context);
        $this->recordRecipeOrderLinesAdded($conn, $recipeLines);
        if ($status['order_status'] === 'completed') {
            $this->recordRecipeOrderPaid($conn, $orderId, $recipeLines, 'pos', 'takeaway', $request, $context);
        }
        $this->tableOrderService->execute($conn, "UPDATE ot_head SET profit = ? WHERE id = ?", [
            (float) $lineResult['totals']['profit'],
            $orderId,
        ]);

        $this->customerSideEffects()->afterOrderSaved($conn, $orderId, $request, 'takeaway', [
            'paid_amount' => (float) $status['paid_amount'],
            'payment_status' => (string) $status['payment_status'],
        ]);

        $outboxResult = null;
        if (!array_key_exists('record_outbox', $context) || $context['record_outbox']) {
            try {
                $syncOutbox = new SyncOutboxEventService();
                $options = [
                    'event_type' => 'order.saved',
                    'source_system' => 'pos_cashier',
                ];
                $branchConfig = $this->syncBranchConfig($request, $context);
                if (isset($context['config']) && is_array($context['config'])) {
                    $options['config'] = $context['config'];
                    if ($branchConfig) {
                        $options['config']['branch'] = array_merge($options['config']['branch'] ?? [], $branchConfig);
                    }
                } elseif ($branchConfig) {
                    $options['config'] = ['branch' => $branchConfig];
                }
                $outboxResult = $syncOutbox->recordOrderSnapshot($conn, $orderId, $options);
            } catch (Throwable $exception) {
                error_log('POS order outbox skipped: ' . $exception->getMessage());
            }
        }

        $this->recordOrderEvent($conn, $orderId, 'order.saved', $context['event_source'] ?? 'pos_cashier', $context, [
            'order_type' => 'takeaway',
            'payment_status' => $status['payment_status'],
            'order_status' => $status['order_status'],
            'paid_amount' => $status['paid_amount'],
            'remaining_amount' => $status['remaining_amount'],
            'line_count' => count($lineResult['lines']),
            'outbox_id' => $outboxResult['outbox_id'] ?? null,
        ]);

        $this->tableOrderService->execute($conn, "INSERT INTO process (type) VALUES ('add cash')");

        return [
            'success' => true,
            'code' => 'OK',
            'message' => 'TAKEAWAY_ORDER_CREATED',
            'data' => [
                'order_id' => $orderId,
                'pro_id' => $proId,
                'payment_status' => $status['payment_status'],
                'invoice_status' => $status['invoice_status'],
                'order_status' => $status['order_status'],
                'paid_amount' => $status['paid_amount'],
                'remaining_amount' => $status['remaining_amount'],
                'profit' => (float) $lineResult['totals']['profit'],
                'journal_head_id' => $salesJournal['journal_head_id'],
                'journal_id' => $salesJournal['journal_id'],
                'receipt_ids' => array_column($receipts, 'receipt_id'),
                'outbox_id' => $outboxResult['outbox_id'] ?? null,
            ],
        ];
    }

    private function createDeliveryOrderInsideTransaction(mysqli $conn, array $request, array $context): array
    {
        $request = $this->resolvePosRequestAccounts($conn, $request);
        $storeId = $this->requiredPositiveInt($request, 'store_id', 'بيانات مطلوبة مفقودة - المخزن');
        $customerId = $this->requiredPositiveInt($request, 'acc2_id', 'بيانات مطلوبة مفقودة - العميل');
        $empId = $this->requiredPositiveInt($request, 'emp_id', 'بيانات مطلوبة مفقودة - الموظف');
        $fundId = $this->requiredPositiveInt($request, 'fund_id', 'بيانات مطلوبة مفقودة - الصندوق');

        $deliveryName = trim((string) ($request['delivery_customer_name'] ?? ''));
        $deliveryPhone = trim((string) ($request['delivery_customer_phone'] ?? ''));
        $deliveryAddress = trim((string) ($request['delivery_customer_address'] ?? ''));
        if ($deliveryName === '' || $deliveryPhone === '' || $deliveryAddress === '') {
            throw new InvalidArgumentException('يجب إدخال بيانات عميل الدليفري');
        }

        $posCustomerService = new PosCustomerService();
        $postedCustomerId = (int) ($request['pos_customer_id'] ?? 0);
        if ($postedCustomerId > 0 && $posCustomerService->getProfile($conn, $postedCustomerId, false)) {
            $request['pos_customer_id'] = $postedCustomerId;
        } else {
            $zoneId = (int) ($request['delivery_zone_id'] ?? 0);
            $upserted = $posCustomerService->upsertForDelivery($conn, $deliveryPhone, $deliveryName, $deliveryAddress, $zoneId > 0 ? $zoneId : null);
            $request['pos_customer_id'] = (int) ($upserted['id'] ?? 0);
        }
        $deliveryCustomerId = (int) ($request['pos_customer_id'] ?? 0);

        $items = $this->normalizeTakeawayItems($request);
        $this->assertItemsAvailable($conn, $items, $request, $context);
        $userId = $this->contextUserId($request, $context);
        $date = $this->requestDate($request, ['pro_date', 'order_date', 'date']);
        $accrualDate = $this->requestDate($request, ['accural_date', 'accrual_date'], $date);
        $headTotal = (float) ($request['headtotal'] ?? $request['total'] ?? 0);
        $headDiscount = (float) ($request['headdisc'] ?? $request['discount'] ?? 0);
        $this->requireDiscountApprovalIfNeeded($conn, null, $headDiscount, $request, $context);
        $resolvedTotals = $this->resolveDeliveryPostedTotals($conn, $request);
        $deliveryFee = (float) $resolvedTotals['delivery_fee'];
        $deliveryZoneName = (string) $resolvedTotals['delivery_zone_name'];
        $headPlus = (float) $resolvedTotals['headplus'];
        $headTotal = (float) $resolvedTotals['headtotal'];
        $headNet = (float) $resolvedTotals['headnet'];
        if ($headNet < 0) {
            throw new InvalidArgumentException('PAYMENT_AMOUNT_INVALID');
        }

        $isSaveOnly = (($request['submit'] ?? '') === 'save');
        $paidCash = $isSaveOnly ? 0 : max(0, (float) ($request['paid_cash'] ?? $request['paid'] ?? 0));
        $paidBank = $isSaveOnly ? 0 : max(0, (float) ($request['paid_bank'] ?? 0));
        $paymentFundId = (int) ($request['payment_fund_id'] ?? $fundId);
        $paymentBankId = (int) ($request['payment_bank_id'] ?? 0);
        $payment = $this->calculateTakeawayPayment($headNet, $paidCash, $paidBank);
        if ($payment['cash'] > 0 && $paymentFundId <= 0) {
            throw new InvalidArgumentException('PAYMENT_FUND_REQUIRED');
        }
        if ($payment['bank'] > 0 && $paymentBankId <= 0) {
            throw new InvalidArgumentException('PAYMENT_BANK_REQUIRED');
        }

        $status = $this->paidStatusForNet($headNet, $payment['applied']);
        $proId = $this->nextInvoiceProId($conn, InventoryMovementService::TYPE_POS, 0, 0);
        $info = $this->tableOrderService->buildInfo('delivery', '', (string) ($request['info'] ?? ''));
        $fatDiscPer = $headTotal > 0 && $headDiscount > 0 ? round($headDiscount / $headTotal * 100, 2) : 0.0;
        $fatPlusPer = $headTotal > 0 && $headPlus > 0 ? round($headPlus / $headTotal * 100, 2) : 0.0;

        $this->tableOrderService->execute($conn, "
            INSERT INTO ot_head (
                pro_id, pro_tybe, is_stock, is_journal, journal_tybe, info, pro_date,
                accural_date, pro_pattren, pro_serial, price_list, store_id, emp_id,
                emp2_id, acc1, acc2, pro_value, fat_cost, cost_center, profit,
                fat_total, fat_disc, fat_disc_per, fat_plus, fat_plus_per,
                fat_tax, fat_tax_per, fat_net, user, jal_name, jal_notes, jal_amount,
                table_id, order_type, payment_status, invoice_status, order_status,
                paid_amount, remaining_amount, waiter_id, payment_date, completed_at
            ) VALUES (
                ?, 9, 1, 1, 9, ?, ?,
                ?, 1, ?, 1, ?, ?,
                ?, ?, ?, ?, 0, 1, 0,
                ?, ?, ?, ?, ?,
                0, 0, ?, ?, ?, ?, ?,
                NULL, 'delivery', ?, ?, ?,
                ?, ?, ?, CASE WHEN ? = 'paid' THEN NOW() ELSE NULL END,
                CASE WHEN ? = 'completed' THEN NOW() ELSE NULL END
            )
        ", [
            $proId,
            $info,
            $date,
            $accrualDate,
            trim((string) ($request['pro_serial'] ?? '')),
            $storeId,
            $empId,
            $empId,
            $fundId,
            $customerId,
            $headTotal,
            $headTotal,
            $headDiscount,
            $fatDiscPer,
            $headPlus,
            $fatPlusPer,
            $headNet,
            $userId,
            $this->nullableString($request['jal_name'] ?? null),
            $this->nullableString($request['jal_notes'] ?? null),
            (float) ($request['jal_amount'] ?? 0),
            $status['payment_status'],
            $status['invoice_status'],
            $status['order_status'],
            $status['paid_amount'],
            $status['remaining_amount'],
            $empId,
            $status['payment_status'],
            $status['order_status'],
        ]);
        $orderId = (int) $conn->insert_id;
        $this->tableOrderService->assignUuidIfPresent($conn, 'ot_head', $orderId);

        $salesJournal = null;
        $receipts = [];
        if ($status['payment_status'] === 'paid' || $payment['applied'] > 0) {
            $salesJournal = $this->insertTakeawaySalesJournal($conn, $orderId, $proId, $headNet, $date, $customerId, $userId, (int) ($request['sales_account_id'] ?? 0));
            if ($payment['cash'] > 0) {
                $receipts[] = $this->insertTakeawayReceipt($conn, $orderId, $proId, $info, $date, $empId, $paymentFundId, $customerId, $payment['cash'], 'كاش', $userId);
            }
            if ($payment['bank'] > 0) {
                $receipts[] = $this->insertTakeawayReceipt($conn, $orderId, $proId, $info, $date, $empId, $paymentBankId, $customerId, $payment['bank'], 'صرافة', $userId);
            }
        }

        $lineResult = $this->inventoryMovementService->normalizeInvoiceLines($conn, InventoryMovementService::TYPE_POS, $items, [
            'store_id' => $storeId,
        ]);
        $recipeLines = [];
        $inventoryBridgeLines = [];
        foreach ($lineResult['lines'] as $index => $line) {
            $line['_source_item'] = $items[$index] ?? [];
            $line['note'] = $this->lineNoteFromItem($line['_source_item']);
            $recipeLine = $this->insertTakeawayDetailLine($conn, $orderId, $storeId, $line, $context);
            $recipeLines[] = $recipeLine;
            $inventoryBridgeLines[] = $this->inventoryBridgeLineFromLegacyLine($line, $recipeLine, $storeId);
        }
        $this->recordInventoryInvoiceBridgeLines($conn, $orderId, $inventoryBridgeLines, 'pos', 'delivery', $request, $context);
        $this->recordRecipeOrderLinesAdded($conn, $recipeLines);
        if ($status['order_status'] === 'completed') {
            $this->recordRecipeOrderPaid($conn, $orderId, $recipeLines, 'pos', 'delivery', $request, $context);
        }
        $this->tableOrderService->execute($conn, "UPDATE ot_head SET profit = ? WHERE id = ?", [
            (float) $lineResult['totals']['profit'],
            $orderId,
        ]);

        $this->customerSideEffects()->afterOrderSaved($conn, $orderId, $request, 'delivery', [
            'paid_amount' => (float) $status['paid_amount'],
            'payment_status' => (string) $status['payment_status'],
        ]);

        $outboxResult = null;
        if (!array_key_exists('record_outbox', $context) || $context['record_outbox']) {
            try {
                $syncOutbox = new SyncOutboxEventService();
                $options = [
                    'event_type' => 'order.saved',
                    'source_system' => 'pos_cashier_delivery',
                ];
                $branchConfig = $this->syncBranchConfig($request, $context);
                if (isset($context['config']) && is_array($context['config'])) {
                    $options['config'] = $context['config'];
                    if ($branchConfig) {
                        $options['config']['branch'] = array_merge($options['config']['branch'] ?? [], $branchConfig);
                    }
                } elseif ($branchConfig) {
                    $options['config'] = ['branch' => $branchConfig];
                }
                $outboxResult = $syncOutbox->recordOrderSnapshot($conn, $orderId, $options);
            } catch (Throwable $exception) {
                error_log('POS delivery outbox skipped: ' . $exception->getMessage());
            }
        }

        $this->recordOrderEvent($conn, $orderId, 'order.saved', $context['event_source'] ?? 'pos_cashier_delivery', $context, [
            'order_type' => 'delivery',
            'payment_status' => $status['payment_status'],
            'order_status' => $status['order_status'],
            'paid_amount' => $status['paid_amount'],
            'remaining_amount' => $status['remaining_amount'],
            'line_count' => count($lineResult['lines']),
            'pos_customer_id' => $deliveryCustomerId,
            'outbox_id' => $outboxResult['outbox_id'] ?? null,
        ]);

        $this->tableOrderService->execute($conn, "INSERT INTO process (type) VALUES ('add delivery')");

        return [
            'success' => true,
            'code' => 'OK',
            'message' => 'DELIVERY_ORDER_CREATED',
            'data' => [
                'order_id' => $orderId,
                'pro_id' => $proId,
                'payment_status' => $status['payment_status'],
                'invoice_status' => $status['invoice_status'],
                'order_status' => $status['order_status'],
                'paid_amount' => $status['paid_amount'],
                'remaining_amount' => $status['remaining_amount'],
                'profit' => (float) $lineResult['totals']['profit'],
                'pos_customer_id' => $deliveryCustomerId,
                'journal_head_id' => $salesJournal['journal_head_id'] ?? null,
                'journal_id' => $salesJournal['journal_id'] ?? null,
                'receipt_ids' => array_column($receipts, 'receipt_id'),
                'outbox_id' => $outboxResult['outbox_id'] ?? null,
            ],
        ];
    }

    private function normalizeTakeawayItems(array $request): array
    {
        if (isset($request['items']) && is_array($request['items']) && $request['items']) {
            return $request['items'];
        }

        if (!isset($request['itmname']) || !is_array($request['itmname'])) {
            throw new InvalidArgumentException('الرجاء إضافة أصناف للطلب');
        }

        $items = [];
        foreach ($request['itmname'] as $index => $itemId) {
            if ((int) $itemId <= 0) {
                continue;
            }
            $items[] = [
                'item_id' => (int) $itemId,
                'qty' => (float) ($request['itmqty'][$index] ?? 1),
                'price' => (float) ($request['itmprice'][$index] ?? 0),
                'discount' => (float) ($request['itmdisc'][$index] ?? 0),
                'u_val' => (float) ($request['u_val'][$index] ?? 1),
                'note' => (string) ($request['itmnote'][$index] ?? ''),
                'modifiers' => $this->decodeLineModifiers($request['itmmodifiers'][$index] ?? []),
                'base_price' => (float) ($request['itmbaseprice'][$index] ?? $request['itmprice'][$index] ?? 0),
                'manager_approval_id' => (int) ($request['itmmanagerapproval'][$index] ?? $request['manager_approval_id'][$index] ?? 0),
            ];
        }

        if (!$items) {
            throw new InvalidArgumentException('الرجاء إضافة أصناف للطلب');
        }

        return $items;
    }

    private function sumPostedItemSubtotal(array $request): float
    {
        if (!isset($request['itmname']) || !is_array($request['itmname'])) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($request['itmname'] as $index => $itemId) {
            if ((int) $itemId <= 0) {
                continue;
            }
            $qty = (float) ($request['itmqty'][$index] ?? 1);
            $price = (float) ($request['itmprice'][$index] ?? 0);
            $discount = (float) ($request['itmdisc'][$index] ?? 0);
            $total += max(0, $qty * ($price - $discount));
        }

        return round($total, 4);
    }

    private function calculateTakeawayPayment(float $headNet, float $paidCash, float $paidBank): array
    {
        $totalPaid = $paidCash + $paidBank;
        $change = max(0, $totalPaid - $headNet);
        $cash = max(0, $paidCash - $change);
        $bank = $paidBank;

        if ($change > $paidCash) {
            $remainingChange = $change - $paidCash;
            $cash = 0;
            $bank = max(0, $paidBank - $remainingChange);
        }

        $applied = min($headNet, max(0, $cash + $bank));

        return [
            'cash' => $cash,
            'bank' => $bank,
            'applied' => $applied,
            'change' => $change,
        ];
    }

    private function paidStatusForNet(float $headNet, float $paidAmount): array
    {
        $appliedPaid = min(max(0, $paidAmount), max(0, $headNet));
        $remaining = max(0, $headNet - $appliedPaid);
        if ($appliedPaid <= 0) {
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
            'paid_amount' => $appliedPaid,
            'remaining_amount' => $remaining,
            'payment_status' => $paymentStatus,
            'invoice_status' => $invoiceStatus,
            'order_status' => $orderStatus,
        ];
    }

    private function insertTakeawaySalesJournal(mysqli $conn, int $orderId, int $proId, float $amount, string $date, int $customerId, int $userId, int $salesAccountId = 0): array
    {
        $salesAccountId = posmain_ensure_sales_account($conn, $salesAccountId > 0 ? $salesAccountId : 91);
        if ($salesAccountId <= 0) {
            throw new InvalidArgumentException('لا يوجد حساب مبيعات صالح في دليل الحسابات');
        }

        $journalId = $this->tableOrderService->nextJournalId($conn, 0, 0);
        $details = 'فاتورة ريسيت _ ' . $orderId;
        $this->tableOrderService->execute($conn, "
            INSERT INTO journal_heads (journal_id, total, jdate, details, user, op_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ", [$journalId, $amount, $date, $details, $userId, $orderId]);
        $journalHeadId = (int) $conn->insert_id;

        $this->tableOrderService->execute($conn, "
            INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op_id)
            VALUES (?, ?, ?, 0, 0, ?)
        ", [$journalHeadId, $customerId, $amount, $orderId]);
        $this->tableOrderService->execute($conn, "
            INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op_id)
            VALUES (?, ?, 0, ?, 1, ?)
        ", [$journalHeadId, $salesAccountId, $amount, $orderId]);

        return [
            'journal_id' => $journalId,
            'journal_head_id' => $journalHeadId,
            'pro_id' => $proId,
        ];
    }

    private function insertTakeawayReceipt(mysqli $conn, int $orderId, int $proId, string $info, string $date, int $empId, int $fundAccountId, int $customerId, float $amount, string $methodLabel, int $userId): array
    {
        $receiptProId = $this->nextInvoiceProId($conn, 1, 0, 0);
        $receiptInfo = $info . ' - دفع ' . $methodLabel;
        $this->tableOrderService->execute($conn, "
            INSERT INTO ot_head (
                pro_id, pro_tybe, is_journal, journal_tybe, info, pro_date,
                emp_id, acc1, acc2, pro_value, cost_center, profit, user, op2
            ) VALUES (?, 1, 1, 1, ?, ?, ?, ?, ?, ?, 1, 0, ?, ?)
        ", [$receiptProId, $receiptInfo, $date, $empId, $fundAccountId, $customerId, $amount, $userId, $orderId]);
        $receiptId = (int) $conn->insert_id;
        $this->tableOrderService->assignUuidIfPresent($conn, 'ot_head', $receiptId);

        $journalId = $this->tableOrderService->nextJournalId($conn, 0, 0);
        $details = 'سند قبض ' . $methodLabel . ' _ ' . $proId;
        $this->tableOrderService->execute($conn, "
            INSERT INTO journal_heads (journal_id, op_id, total, jdate, details, user, op2)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ", [$journalId, $receiptId, $amount, $date, $details, $userId, $orderId]);
        $journalHeadId = (int) $conn->insert_id;

        $this->tableOrderService->execute($conn, "
            INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op2)
            VALUES (?, ?, ?, 0, 0, ?)
        ", [$journalHeadId, $fundAccountId, $amount, $orderId]);
        $this->tableOrderService->execute($conn, "
            INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op2)
            VALUES (?, ?, 0, ?, 1, ?)
        ", [$journalHeadId, $customerId, $amount, $orderId]);

        return [
            'receipt_id' => $receiptId,
            'receipt_pro_id' => $receiptProId,
            'journal_id' => $journalId,
            'journal_head_id' => $journalHeadId,
        ];
    }

    private function insertTakeawayDetailLine(mysqli $conn, int $orderId, int $storeId, array $line, array $context = []): array
    {
        $this->tableOrderService->execute($conn, "
            INSERT INTO fat_details (
                pro_tybe, pro_id, item_id, u_val, qty_in, qty_out, price,
                discount, det_value, fatid, fat_tybe, det_store, cost_price, profit
            ) VALUES (9, ?, ?, ?, ?, ?, ?, ?, ?, ?, 9, ?, ?, ?)
        ", [
            $orderId,
            (int) $line['item_id'],
            (float) $line['u_val'],
            (float) $line['qty_in'],
            (float) $line['qty_out'],
            (float) $line['price'],
            (float) $line['discount'],
            (float) $line['det_value'],
            $orderId,
            $storeId,
            (float) $line['cost_price'],
            (float) $line['profit'],
        ]);
        $detailId = (int) $conn->insert_id;
        $detailUuid = $this->tableOrderService->assignUuidIfPresent($conn, 'fat_details', $detailId);
        $sourceItem = is_array($line['_source_item'] ?? null) ? $line['_source_item'] : $line;
        $this->persistLineCustomizationsIfAvailable(
            $conn,
            $orderId,
            $detailId,
            (int) $line['item_id'],
            $sourceItem,
            abs((float) ($line['qty_out'] ?? 0) - (float) ($line['qty_in'] ?? 0)),
            $context
        );

        $quantity = $this->recipeQuantityFromLegacyStockValues(
            $line['qty_in'] ?? '0',
            $line['qty_out'] ?? '0',
            $line['u_val'] ?? '1'
        );

        return $this->recipeLineContext(
            $conn,
            $orderId,
            $detailId,
            $detailUuid,
            (int) $line['item_id'],
            $quantity,
            $storeId,
            'pos',
            'takeaway',
            $sourceItem,
            [],
            $context
        );
    }

    private function nextInvoiceProId(mysqli $conn, int $invoiceType, int $tenant, int $branch): int
    {
        return $this->tableOrderService->nextPosProId($conn, $invoiceType, $tenant, $branch);
    }

    private function requestDate(array $request, array $keys, ?string $default = null): string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($request[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return $default ?: date('Y-m-d');
    }

    private function nullableString($value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function saveTableOrderInsideTransaction(mysqli $conn, array $request, array $context): array
    {
        $request = $this->resolvePosRequestAccounts($conn, $request);
        $tableId = $this->requiredPositiveInt($request, 'table_id', 'الرجاء اختيار طاولة');
        $orderId = (int) ($request['order_id'] ?? 0);
        $orderDate = trim((string) ($request['order_date'] ?? date('Y-m-d')));
        $storeId = (int) ($request['store_id'] ?? 0);
        if ($storeId < 1) {
            throw new RuntimeException('بيانات المخزن أو الموظف أو الصندوق ناقصة');
        }
        $empId = $this->requiredPositiveInt($request, 'emp_id', 'بيانات المخزن أو الموظف أو الصندوق ناقصة');
        $fundId = $this->requiredPositiveInt($request, 'fund_id', 'بيانات المخزن أو الموظف أو الصندوق ناقصة');
        $items = $this->requiredItems($request);
        $this->assertItemsAvailable($conn, $items, $request, $context);
        $total = (float) ($request['total'] ?? 0);
        $discount = (float) ($request['discount'] ?? 0);
        $this->requireDiscountApprovalIfNeeded($conn, $orderId > 0 ? $orderId : null, $discount, $request, $context);
        $net = (float) ($request['net'] ?? max(0, $total - $discount));
        $userId = $this->contextUserId($request, $context);
        $isUpdate = $orderId > 0;

        $table = $this->tableOrderService->requireTable($conn, $tableId);
        $existingPaid = 0.0;
        if ($orderId > 0) {
            $activeOrder = $this->tableOrderService->findActiveOrderByTableAndOrderId($conn, $tableId, $orderId, true);
            if (!$activeOrder) {
                throw new RuntimeException('الطلب المحدد لا يخص هذه الطاولة أو لم يعد نشطاً');
            }
            $existingPaid = (float) ($activeOrder['paid_amount'] ?? 0);
        } else {
            $existingOrder = $this->tableOrderService->findActiveOrderByTableId($conn, $tableId, true);
            if ($existingOrder) {
                throw new RuntimeException('هذه الطاولة لديها طلب نشط بالفعل. أعد تحميل الطلب قبل الحفظ.');
            }
        }

        $clientId = $this->resolveDefaultClientId($conn);
        $info = $this->tableOrderService->buildInfo('table', $table['tname'] ?? '', '');
        $oldRecipeLines = $isUpdate
            ? $this->loadRecipeOrderLineContexts($conn, $orderId, 'table', 'dine_in', $request, $context)
            : [];
        $oldInventoryBridgeLines = $isUpdate
            ? $this->loadInventoryInvoiceBridgeLines($conn, $orderId)
            : [];
        if ($orderId > 0) {
            $this->updateTableOrderHeader(
                $conn,
                $orderId,
                $tableId,
                $orderDate,
                $storeId,
                $empId,
                $fundId,
                $clientId,
                $total,
                $discount,
                $net,
                $info
            );
            $this->recordRecipeOrderLinesCancelled($conn, $oldRecipeLines, 'order_updated');
            $this->recordInventoryInvoiceBridgeReversalLines($conn, $orderId, $oldInventoryBridgeLines, 'order_updated', 'table', 'dine_in', $request, $context);
            $this->tableOrderService->execute($conn, "UPDATE fat_details SET isdeleted = 1 WHERE fatid = ?", [$orderId]);
        } else {
            $orderId = $this->insertTableOrderHeader(
                $conn,
                $tableId,
                $orderDate,
                $storeId,
                $empId,
                $fundId,
                $clientId,
                $total,
                $discount,
                $net,
                $info,
                $userId
            );
        }
        $this->tableOrderService->assignUuidIfPresent($conn, 'ot_head', $orderId);

        $recipeLines = $this->insertTableOrderItems($conn, $orderId, $storeId, $items, $context);
        $this->recordInventoryInvoiceBridgeLines($conn, $orderId, $this->inventoryBridgeLinesFromRecipeLines($recipeLines), 'table', 'dine_in', $request, $context);
        $this->recordRecipeOrderLinesAdded($conn, $recipeLines);
        $totals = $this->tableOrderService->recalculateOrderTotals($conn, $orderId);
        $status = $this->applyPaidState($conn, $orderId, $tableId, $existingPaid, (float) $totals['net']);
        if ($status['order_status'] === 'completed') {
            $this->recordRecipeOrderPaid($conn, $orderId, $recipeLines, 'table', 'dine_in', $request, $context);
        }

        if ($status['order_status'] === 'completed') {
            $this->tableOrderService->setTableFreeIfNoActiveOrder($conn, $tableId);
        } else {
            $this->tableOrderService->markTableOccupied($conn, $tableId);
        }

        $this->recordOrderEvent($conn, $orderId, $isUpdate ? 'order.updated' : 'order.saved', $context['event_source'] ?? 'pos_table_save', $context, [
            'table_id' => $tableId,
            'is_update' => $isUpdate,
            'payment_status' => $status['payment_status'],
            'order_status' => $status['order_status'],
            'invoice_status' => $status['invoice_status'],
            'paid_amount' => $status['paid_amount'],
            'remaining_amount' => $status['remaining_amount'],
            'net' => (float) $totals['net'],
        ]);

        $this->customerSideEffects()->afterOrderSaved($conn, $orderId, $request, 'table', [
            'paid_amount' => (float) $status['paid_amount'],
            'payment_status' => (string) $status['payment_status'],
        ]);

        return [
            'success' => true,
            'code' => 'OK',
            'message' => 'TABLE_ORDER_SAVED',
            'data' => [
                'order_id' => $orderId,
                'table_id' => $tableId,
                'is_update' => $isUpdate,
                'payment_status' => $status['payment_status'],
                'order_status' => $status['order_status'],
                'invoice_status' => $status['invoice_status'],
                'remaining_amount' => $status['remaining_amount'],
                'paid_amount' => $status['paid_amount'],
                'total' => (float) $totals['total'],
                'net' => (float) $totals['net'],
            ],
        ];
    }

    private function splitTablePaymentInsideTransaction(mysqli $conn, array $request, array $context): array
    {
        $originalOrderId = (int) ($request['order_id'] ?? $request['original_order_id'] ?? 0);
        $tableId = (int) ($request['table_id'] ?? 0);
        $splitRequests = $this->normalizeSplitRequests($request['items'] ?? []);
        $selectedItems = array_keys($splitRequests);
        $paidAmount = (float) ($request['paid_amount'] ?? $request['paid'] ?? 0);
        $paymentMethod = trim((string) ($request['payment_method'] ?? 'cash'));
        $userId = $this->contextUserId($request, $context);

        if ($originalOrderId <= 0 || $tableId <= 0 || !$selectedItems || $paidAmount <= 0) {
            throw new InvalidArgumentException('بيانات السداد المقسم غير صحيحة');
        }

        $this->tableOrderService->requireTable($conn, $tableId);
        $originalOrder = $this->tableOrderService->findActiveOrderByTableAndOrderId($conn, $tableId, $originalOrderId, true);
        if (!$originalOrder) {
            throw new RuntimeException('الطلب الأصلي غير موجود أو لم يعد نشطاً لهذه الطاولة');
        }

        $details = $this->loadSplitDetails($conn, $originalOrderId, $selectedItems);
        if (count($details) !== count($selectedItems)) {
            throw new RuntimeException('بعض الأصناف المختارة لا تخص الطلب الأصلي');
        }

        $splitLines = $this->buildSplitLines($selectedItems, $splitRequests, $details);
        $childTotal = 0.0;
        foreach ($splitLines as $line) {
            $childTotal += (float) $line['value'];
        }
        if ($childTotal <= 0) {
            throw new RuntimeException('قيمة الأصناف المختارة غير صحيحة');
        }
        if ($paidAmount + self::PAYMENT_ROUNDING_TOLERANCE < $childTotal) {
            throw new RuntimeException('المبلغ المدفوع أقل من قيمة الأصناف المختارة');
        }

        $drawerContext = array_merge($request, $context, ['drawer_reason' => 'split_payment']);
        $drawerSession = $this->paymentService->preflightCashDrawerForPayment($conn, $paymentMethod, $childTotal, $userId, $drawerContext);
        $newHeadId = $this->insertSplitChildOrder($conn, $originalOrder, $tableId, $originalOrderId, $childTotal, $paymentMethod, $userId);
        foreach ($splitLines as $line) {
            $this->moveOrCopySplitLine($conn, $newHeadId, $line);
        }

        $recipeSplitAdjustments = $this->recipeSplitOriginalAdjustments($conn, $originalOrderId, $splitLines, 'table', 'dine_in', $request, $context);
        $remainingTotals = $this->tableOrderService->recalculateOrderTotals($conn, $originalOrderId);
        $activeTableOrderId = $this->refreshOriginalAfterSplit($conn, $originalOrder, $originalOrderId, $tableId, (float) $remainingTotals['net']);
        $paymentId = $this->insertSplitPaymentRecordIfAvailable($conn, $newHeadId, $childTotal, $paymentMethod, $userId);
        $this->paymentService->recordCashDrawerMovementForPayment($conn, $paymentMethod, $childTotal, $newHeadId, $userId, $drawerContext, $drawerSession, $paymentId);
        $splitRecipeLines = $this->loadRecipeOrderLineContexts($conn, $newHeadId, 'table', 'dine_in', $request, $context);
        $this->recordRecipeOrderSplit(
            $conn,
            $originalOrderId,
            $newHeadId,
            $recipeSplitAdjustments['source_lines'],
            $recipeSplitAdjustments['remaining_lines'],
            $splitRecipeLines,
            'table',
            'dine_in',
            $request,
            $context
        );
        $this->recordOrderEvent($conn, $originalOrderId, 'order.updated', $context['event_source'] ?? 'pos_split_payment', $context, [
            'table_id' => $tableId,
            'split_child_order_id' => $newHeadId,
            'remaining_total' => (float) $remainingTotals['net'],
            'active_order_id' => $activeTableOrderId,
        ]);
        $this->recordOrderEvent($conn, $newHeadId, 'order.split_paid', $context['event_source'] ?? 'pos_split_payment', $context, [
            'table_id' => $tableId,
            'original_order_id' => $originalOrderId,
            'paid_amount' => $childTotal,
            'payment_method' => $paymentMethod,
        ]);

        $crmRequest = $request;
        $parentFulfillment = (new OrderFulfillmentService())->fulfillmentForOrder($conn, $originalOrderId);
        if (!empty($parentFulfillment['pos_customer_id'])) {
            $crmRequest['pos_customer_id'] = (int) $parentFulfillment['pos_customer_id'];
        }
        $this->customerSideEffects()->afterOrderSaved($conn, $newHeadId, $crmRequest, 'table', [
            'paid_amount' => $childTotal,
            'payment_status' => 'paid',
        ]);

        return [
            'success' => true,
            'code' => 'OK',
            'message' => 'SPLIT_PAYMENT_CREATED',
            'data' => [
                'new_invoice_id' => $newHeadId,
                'order_id' => $newHeadId,
                'original_order_id' => $originalOrderId,
                'table_id' => $tableId,
                'split_group_id' => $this->splitGroupIdForOrder($conn, $newHeadId),
                'remaining_total' => (float) $remainingTotals['net'],
                'active_order_id' => $activeTableOrderId,
                'paid_amount' => $childTotal,
            ],
        ];
    }

    private function normalizeSplitRequests($items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $splitRequests = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $detailId = (int) ($item['detail_id'] ?? $item['detailId'] ?? $item['id'] ?? 0);
                $qty = isset($item['qty']) ? (float) $item['qty'] : (isset($item['quantity']) ? (float) $item['quantity'] : null);
            } else {
                $detailId = (int) $item;
                $qty = null;
            }

            if ($detailId > 0) {
                if (!isset($splitRequests[$detailId])) {
                    $splitRequests[$detailId] = ['qty' => null];
                }
                if ($qty !== null) {
                    $splitRequests[$detailId]['qty'] = ($splitRequests[$detailId]['qty'] ?? 0) + $qty;
                }
            }
        }

        return $splitRequests;
    }

    private function loadSplitDetails(mysqli $conn, int $originalOrderId, array $selectedItems): array
    {
        $placeholders = implode(',', array_fill(0, count($selectedItems), '?'));
        $detailParams = array_merge([$originalOrderId], $selectedItems);
        $rows = $this->tableOrderService->queryAll($conn, "
            SELECT *
            FROM fat_details
            WHERE fatid = ?
              AND isdeleted = 0
              AND id IN ($placeholders)
            FOR UPDATE
        ", $detailParams);

        $detailsById = [];
        foreach ($rows as $row) {
            $detailsById[(int) $row['id']] = $row;
        }

        return $detailsById;
    }

    private function buildSplitLines(array $selectedItems, array $splitRequests, array $detailsById): array
    {
        $splitLines = [];
        foreach ($selectedItems as $detailId) {
            $detail = $detailsById[$detailId] ?? null;
            if (!$detail) {
                throw new RuntimeException('بعض الأصناف المختارة لا تخص الطلب الأصلي');
            }

            $availableQty = max(0, (float) ($detail['qty_out'] ?? 0) - (float) ($detail['qty_in'] ?? 0));
            $requestedQty = $splitRequests[$detailId]['qty'];
            if ($requestedQty === null) {
                $requestedQty = $availableQty;
            }
            if ($availableQty <= 0 || $requestedQty <= 0 || $requestedQty > $availableQty + 0.0001) {
                throw new RuntimeException('كمية الصنف المختارة غير صحيحة');
            }

            $ratio = min(1, $requestedQty / $availableQty);
            $splitLines[] = [
                'detail' => $detail,
                'qty' => $requestedQty,
                'value' => round((float) ($detail['det_value'] ?? 0) * $ratio, 4),
                'profit' => round((float) ($detail['profit'] ?? 0) * $ratio, 4),
                'is_full' => abs($requestedQty - $availableQty) <= 0.0001,
            ];
        }

        return $splitLines;
    }

    private function insertSplitChildOrder(mysqli $conn, array $originalOrder, int $tableId, int $originalOrderId, float $childTotal, string $paymentMethod, int $userId): int
    {
        $newInvoiceNum = $this->tableOrderService->nextPosProId($conn, TableOrderService::POS_TYPE, 0, 0);
        $splitGroupId = bin2hex(random_bytes(16));
        $date = date('Y-m-d');
        $info = 'سداد جزئي من طاولة ' . $tableId . ' - أصل الطلب ' . $originalOrderId;
        $emp2Id = (int) ($originalOrder['emp2_id'] ?? ($originalOrder['emp_id'] ?? 0));

        $this->tableOrderService->execute($conn, "
            INSERT INTO ot_head (
                pro_id, branch_id, table_id, order_type, pro_tybe, pro_date, accural_date,
                store_id, emp_id, emp2_id, acc1, acc2, pro_value, fat_total, fat_disc,
                fat_net, paid_amount, remaining_amount, payment_status, invoice_status,
                order_status, payment_method, payment_date, completed_at, parent_order_id,
                split_group_id, info, user
            ) VALUES (
                ?, ?, ?, 'table', 9, ?, ?,
                ?, ?, ?, ?, ?, ?, ?, 0,
                ?, ?, 0, 'paid', 'completed',
                'completed', ?, NOW(), NOW(), ?,
                ?, ?, ?
            )
        ", [
            $newInvoiceNum,
            (int) ($originalOrder['branch_id'] ?? 0),
            $tableId,
            $date,
            $date,
            (int) ($originalOrder['store_id'] ?? 0),
            (int) ($originalOrder['emp_id'] ?? 0),
            $emp2Id,
            (int) ($originalOrder['acc1'] ?? 0),
            (int) ($originalOrder['acc2'] ?? 0),
            $childTotal,
            $childTotal,
            $childTotal,
            $childTotal,
            $paymentMethod,
            $originalOrderId,
            $splitGroupId,
            $info,
            $userId,
        ]);

        $orderId = (int) $conn->insert_id;
        $this->tableOrderService->assignUuidIfPresent($conn, 'ot_head', $orderId);

        return $orderId;
    }

    private function moveOrCopySplitLine(mysqli $conn, int $newHeadId, array $line): void
    {
        $detailId = (int) $line['detail']['id'];
        if ($line['is_full']) {
            $this->tableOrderService->execute($conn, "
                UPDATE fat_details
                SET fatid = ?,
                    pro_id = ?,
                    pro_tybe = 9,
                    fat_tybe = 9
                WHERE id = ?
            ", [$newHeadId, $newHeadId, $detailId]);
            $this->tableOrderService->assignUuidIfPresent($conn, 'fat_details', $detailId);
            return;
        }

        $this->tableOrderService->execute($conn, "
            INSERT INTO fat_details (
                pro_tybe, det_store, pro_id, item_id, u_val, qty_in, qty_out,
                price, cost_price, stock_value, discount, plus, det_value,
                profit, fatid, fat_tybe, tenant, branch
            )
            SELECT
                9, det_store, ?, item_id, u_val, 0, ?,
                price, cost_price, stock_value, discount, plus, ?,
                ?, ?, 9, tenant, branch
            FROM fat_details
            WHERE id = ?
        ", [$newHeadId, $line['qty'], $line['value'], $line['profit'], $newHeadId, $detailId]);
        $this->tableOrderService->assignUuidIfPresent($conn, 'fat_details', (int) $conn->insert_id);

        $this->tableOrderService->execute($conn, "
            UPDATE fat_details
            SET qty_out = qty_out - ?,
                det_value = GREATEST(0, det_value - ?),
                profit = profit - ?
            WHERE id = ?
        ", [$line['qty'], $line['value'], $line['profit'], $detailId]);
    }

    private function refreshOriginalAfterSplit(mysqli $conn, array $originalOrder, int $originalOrderId, int $tableId, float $remainingNet): ?int
    {
        $remainingLines = $this->tableOrderService->queryOne($conn, "
            SELECT COUNT(*) AS c
            FROM fat_details
            WHERE fatid = ?
              AND isdeleted = 0
              AND qty_out > qty_in
        ", [$originalOrderId]);

        if ((int) ($remainingLines['c'] ?? 0) > 0 && $remainingNet > 0) {
            $originalPaid = min((float) ($originalOrder['paid_amount'] ?? 0), $remainingNet);
            $originalRemaining = max(0, $remainingNet - $originalPaid);
            if ($originalPaid <= 0) {
                $paymentStatus = 'unpaid';
                $invoiceStatus = 'draft';
                $orderStatus = 'active';
            } elseif ($originalRemaining <= 0.0001) {
                $paymentStatus = 'paid';
                $invoiceStatus = 'completed';
                $orderStatus = 'completed';
            } else {
                $paymentStatus = 'partial';
                $invoiceStatus = 'draft';
                $orderStatus = 'active';
            }

            $this->tableOrderService->execute($conn, "
                UPDATE ot_head
                SET payment_status = ?,
                    invoice_status = ?,
                    order_status = ?,
                    paid_amount = ?,
                    remaining_amount = ?
                WHERE id = ?
                  AND table_id = ?
            ", [$paymentStatus, $invoiceStatus, $orderStatus, $originalPaid, $originalRemaining, $originalOrderId, $tableId]);

            if ($orderStatus === 'completed') {
                $this->tableOrderService->setTableFreeIfNoActiveOrder($conn, $tableId);
                return null;
            }

            $this->tableOrderService->markTableOccupied($conn, $tableId);
            return $originalOrderId;
        }

        $this->tableOrderService->execute($conn, "
            UPDATE ot_head
            SET payment_status = 'paid',
                invoice_status = 'completed',
                order_status = 'completed',
                paid_amount = 0,
                remaining_amount = 0,
                completed_at = NOW()
            WHERE id = ?
              AND table_id = ?
        ", [$originalOrderId, $tableId]);
        $this->tableOrderService->setTableFreeIfNoActiveOrder($conn, $tableId);

        return null;
    }

    private function insertSplitPaymentRecordIfAvailable(mysqli $conn, int $newHeadId, float $childTotal, string $paymentMethod, int $userId): ?int
    {
        $tableCheck = $conn->query("SHOW TABLES LIKE 'order_payments'");
        if ($tableCheck && $tableCheck->num_rows > 0) {
            $this->tableOrderService->execute($conn, "
                INSERT INTO order_payments (order_id, amount, payment_method, created_by, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ", [$newHeadId, $childTotal, $paymentMethod, $userId]);
            $paymentId = (int) $conn->insert_id;
            $this->tableOrderService->assignUuidIfPresent($conn, 'order_payments', $paymentId);

            return $paymentId;
        }

        return null;
    }

    private function splitGroupIdForOrder(mysqli $conn, int $orderId): ?string
    {
        $row = $this->tableOrderService->queryOne($conn, "SELECT split_group_id FROM ot_head WHERE id = ? LIMIT 1", [$orderId]);
        if (!$row || !array_key_exists('split_group_id', $row)) {
            return null;
        }

        return (string) $row['split_group_id'];
    }

    private function updateTableOrderHeader(
        mysqli $conn,
        int $orderId,
        int $tableId,
        string $orderDate,
        int $storeId,
        int $empId,
        int $fundId,
        int $clientId,
        float $total,
        float $discount,
        float $net,
        string $info
    ): void {
        $this->tableOrderService->execute($conn, "
            UPDATE ot_head
            SET pro_date = ?,
                accural_date = ?,
                store_id = ?,
                emp_id = ?,
                emp2_id = ?,
                acc1 = ?,
                acc2 = ?,
                fat_total = ?,
                fat_disc = ?,
                fat_net = ?,
                pro_value = ?,
                remaining_amount = ?,
                table_id = ?,
                order_type = 'table',
                payment_status = 'unpaid',
                invoice_status = 'draft',
                order_status = 'active',
                waiter_id = ?,
                info = ?
            WHERE id = ?
        ", [
            $orderDate,
            $orderDate,
            $storeId,
            $empId,
            $empId,
            $fundId,
            $clientId,
            $total,
            $discount,
            $net,
            $net,
            $net,
            $tableId,
            $empId,
            $info,
            $orderId,
        ]);
    }

    private function insertTableOrderHeader(
        mysqli $conn,
        int $tableId,
        string $orderDate,
        int $storeId,
        int $empId,
        int $fundId,
        int $clientId,
        float $total,
        float $discount,
        float $net,
        string $info,
        int $userId
    ): int {
        $proId = $this->tableOrderService->nextPosProId($conn, TableOrderService::POS_TYPE, 0, 0);
        $this->tableOrderService->execute($conn, "
            INSERT INTO ot_head (
                pro_id, pro_tybe, pro_date, accural_date, store_id, emp_id, emp2_id,
                acc1, acc2, fat_total, fat_disc, fat_net, pro_value, remaining_amount,
                table_id, order_type, payment_status, invoice_status, order_status, waiter_id,
                info, user, crtime
            ) VALUES (
                ?, 9, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?,
                ?, 'table', 'unpaid', 'draft', 'active', ?,
                ?, ?, CURRENT_TIMESTAMP
            )
        ", [
            $proId,
            $orderDate,
            $orderDate,
            $storeId,
            $empId,
            $empId,
            $fundId,
            $clientId,
            $total,
            $discount,
            $net,
            $net,
            $net,
            $tableId,
            $empId,
            $info,
            $userId,
        ]);

        return (int) $conn->insert_id;
    }

    private function insertTableOrderItems(mysqli $conn, int $orderId, int $storeId, array $items, array $context = []): array
    {
        $recipeLines = [];
        foreach ($items as $item) {
            $itemId = (int) ($item['id'] ?? $item['item_id'] ?? 0);
            $qty = (float) ($item['qty'] ?? 0);
            $price = (float) ($item['price'] ?? 0);
            if ($itemId <= 0 || $qty <= 0) {
                continue;
            }

            $detValue = $qty * $price;
            $this->tableOrderService->execute($conn, "
                INSERT INTO fat_details (
                    pro_tybe, pro_id, item_id, u_val, qty_in, qty_out, price,
                    discount, det_value, fatid, fat_tybe, det_store
                ) VALUES (9, ?, ?, 1, 0, ?, ?, 0, ?, ?, 9, ?)
            ", [$orderId, $itemId, $qty, $price, $detValue, $orderId, $storeId]);
            $detailId = (int) $conn->insert_id;
            $detailUuid = $this->tableOrderService->assignUuidIfPresent($conn, 'fat_details', $detailId);
            $this->persistLineCustomizationsIfAvailable($conn, $orderId, $detailId, $itemId, $item, $qty, $context);
            $recipeLines[] = $this->recipeLineContext(
                $conn,
                $orderId,
                $detailId,
                $detailUuid,
                $itemId,
                $qty,
                $storeId,
                'table',
                'dine_in',
                $item,
                [],
                $context
            );
        }

        return $recipeLines;
    }

    private function loadRecipeOrderLineContexts(
        mysqli $conn,
        int $orderId,
        string $channel,
        string $orderType,
        array $request = [],
        array $context = []
    ): array {
        if ($orderId < 1) {
            return [];
        }
        foreach (['id', 'item_id', 'qty_in', 'qty_out', 'u_val', 'det_store', 'fatid', 'isdeleted'] as $column) {
            if (!$this->columnExists($conn, 'fat_details', $column)) {
                return [];
            }
        }

        $rows = $this->tableOrderService->queryAll($conn, "
            SELECT id, item_id, qty_in, qty_out, u_val, det_store
            FROM fat_details
            WHERE fatid = ?
              AND isdeleted = 0
              AND qty_out > qty_in
            ORDER BY id ASC
        ", [$orderId]);

        $lines = [];
        foreach ($rows as $row) {
            $quantity = $this->recipeQuantityFromLegacyStockValues(
                $row['qty_in'] ?? '0',
                $row['qty_out'] ?? '0',
                $row['u_val'] ?? '1'
            );
            if (RecipeDecimal::compare($quantity, '0') <= 0) {
                continue;
            }

            $lines[] = $this->recipeLineContext(
                $conn,
                $orderId,
                (int) $row['id'],
                null,
                (int) $row['item_id'],
                $quantity,
                (int) ($row['det_store'] ?? 0),
                $channel,
                $orderType,
                [],
                $request,
                $context
            );
        }

        return $lines;
    }

    private function loadInventoryInvoiceBridgeLines(mysqli $conn, int $orderId): array
    {
        if ($orderId < 1 || !$this->columnExists($conn, 'fat_details', 'id')) {
            return [];
        }

        $costSelect = $this->columnExists($conn, 'fat_details', 'cost_price')
            ? 'COALESCE(cost_price, 0) AS cost_price'
            : '0 AS cost_price';
        $uuidSelect = $this->columnExists($conn, 'fat_details', 'uuid')
            ? 'uuid AS order_line_uuid'
            : 'NULL AS order_line_uuid';

        return $this->tableOrderService->queryAll($conn, "
            SELECT id, item_id, u_val, qty_in, qty_out, det_store, {$costSelect}, {$uuidSelect}
            FROM fat_details
            WHERE fatid = ?
              AND isdeleted = 0
              AND (qty_in > 0 OR qty_out > 0)
            ORDER BY id ASC
        ", [$orderId]);
    }

    private function inventoryBridgeLinesFromRecipeLines(array $recipeLines): array
    {
        $lines = [];
        foreach ($recipeLines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $detailId = (int) ($line['fat_detail_id'] ?? 0);
            $itemId = (int) ($line['item_id'] ?? $line['sellable_item_id'] ?? 0);
            $qty = (string) ($line['qty'] ?? $line['quantity'] ?? '0');
            if ($detailId < 1 || $itemId < 1) {
                continue;
            }
            $lines[] = [
                'id' => $detailId,
                'item_id' => $itemId,
                'qty_in' => '0',
                'qty_out' => $qty,
                'u_val' => '1',
                'cost_price' => '0',
                'det_store' => (int) ($line['store_id'] ?? 0),
                'order_line_uuid' => $this->nullableString($line['order_line_uuid'] ?? null),
            ];
        }

        return $lines;
    }

    private function inventoryBridgeLineFromLegacyLine(array $legacyLine, array $recipeLine, int $storeId): array
    {
        return [
            'id' => (int) ($recipeLine['fat_detail_id'] ?? 0),
            'item_id' => (int) ($legacyLine['item_id'] ?? $recipeLine['item_id'] ?? 0),
            'qty_in' => (string) ($legacyLine['qty_in'] ?? '0'),
            'qty_out' => (string) ($legacyLine['qty_out'] ?? $recipeLine['qty'] ?? '0'),
            'u_val' => (string) ($legacyLine['u_val'] ?? '1'),
            'cost_price' => (string) ($legacyLine['cost_price'] ?? '0'),
            'det_store' => (int) ($legacyLine['det_store'] ?? $storeId),
            'order_line_uuid' => $this->nullableString($recipeLine['order_line_uuid'] ?? null),
        ];
    }

    private function recordInventoryInvoiceBridgeLines(
        mysqli $conn,
        int $orderId,
        array $lines,
        string $channel,
        string $orderType,
        array $request,
        array $context
    ): void {
        if ($orderId < 1 || !$lines) {
            return;
        }

        try {
            $result = $this->inventoryInvoiceBridge->recordInvoiceLines(
                $conn,
                InventoryInvoiceBridge::TYPE_POS,
                $orderId,
                $lines,
                $this->inventoryInvoiceBridgeContext($request, $context, $channel, $orderType)
            );
            if (!empty($result['errors'])) {
                error_log('POS inventory invoice bridge shadow errors: ' . json_encode($result['errors'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            if (SideEffectPolicy::inventoryBridgeShouldRollback(new RuntimeException('bridge_errors'), $result)) {
                throw new RuntimeException('INVENTORY_BRIDGE_FAILED');
            }
        } catch (Throwable $exception) {
            error_log('POS inventory invoice bridge shadow failed: ' . $exception->getMessage());
            if (SideEffectPolicy::inventoryBridgeShouldRollback($exception)) {
                throw $exception;
            }
        }
    }

    private function recordInventoryInvoiceBridgeReversalLines(
        mysqli $conn,
        int $orderId,
        array $lines,
        string $reason,
        string $channel,
        string $orderType,
        array $request,
        array $context
    ): void {
        if ($orderId < 1 || !$lines) {
            return;
        }

        try {
            $result = $this->inventoryInvoiceBridge->recordInvoiceReversalLines(
                $conn,
                InventoryInvoiceBridge::TYPE_POS,
                $orderId,
                $lines,
                $reason,
                $this->inventoryInvoiceBridgeContext($request, $context, $channel, $orderType)
            );
            if (!empty($result['errors'])) {
                error_log('POS inventory invoice bridge reversal shadow errors: ' . json_encode($result['errors'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        } catch (Throwable $exception) {
            error_log('POS inventory invoice bridge reversal shadow failed: ' . $exception->getMessage());
            if (SideEffectPolicy::inventoryBridgeShouldRollback($exception)) {
                throw $exception;
            }
        }
    }

    private function inventoryInvoiceBridgeContext(array $request, array $context, string $channel, string $orderType): array
    {
        return [
            'store_id' => (int) ($request['store_id'] ?? $context['store_id'] ?? 0),
            'user_id' => $this->contextUserId($request, $context),
            'channel' => $channel,
            'order_type' => $orderType,
            'source_system' => $context['event_source'] ?? $context['source_system'] ?? 'pos_order_mutation_service',
        ];
    }

    private function recipeSplitOriginalAdjustments(
        mysqli $conn,
        int $originalOrderId,
        array $splitLines,
        string $channel,
        string $orderType,
        array $request = [],
        array $context = []
    ): array {
        $sourceLines = [];
        $remainingLines = [];
        if ($originalOrderId < 1 || !$splitLines) {
            return [
                'source_lines' => $sourceLines,
                'remaining_lines' => $remainingLines,
            ];
        }

        foreach ($splitLines as $splitLine) {
            if (!is_array($splitLine) || !is_array($splitLine['detail'] ?? null)) {
                continue;
            }

            $detail = $splitLine['detail'];
            $detailId = (int) ($detail['id'] ?? 0);
            $itemId = (int) ($detail['item_id'] ?? 0);
            if ($detailId < 1 || $itemId < 1) {
                continue;
            }
            $uVal = RecipeDecimal::normalize($detail['u_val'] ?? '1');
            if (RecipeDecimal::compare($uVal, '0') <= 0) {
                $uVal = '1.000000';
            }

            $qtyOut = RecipeDecimal::normalize($detail['qty_out'] ?? '0');
            $qtyIn = RecipeDecimal::normalize($detail['qty_in'] ?? '0');
            $oldRawQty = RecipeDecimal::compare($qtyOut, $qtyIn) > 0
                ? RecipeDecimal::subtract($qtyOut, $qtyIn)
                : '0.000000';
            $splitRawQty = RecipeDecimal::normalize($splitLine['qty'] ?? '0');
            if (RecipeDecimal::compare($oldRawQty, '0') <= 0 || RecipeDecimal::compare($splitRawQty, '0') <= 0) {
                continue;
            }

            $oldQty = RecipeDecimal::divide($oldRawQty, $uVal);
            $remainingRawQty = RecipeDecimal::compare($oldRawQty, $splitRawQty) > 0
                ? RecipeDecimal::subtract($oldRawQty, $splitRawQty)
                : '0.000000';
            $oldContext = $this->recipeLineContext(
                $conn,
                $originalOrderId,
                $detailId,
                $this->nullableString($detail['uuid'] ?? null),
                $itemId,
                $oldQty,
                (int) ($detail['det_store'] ?? 0),
                $channel,
                $orderType,
                [],
                $request,
                $context
            );
            $sourceLines[] = $oldContext;

            if (!empty($splitLine['is_full']) || RecipeDecimal::compare($remainingRawQty, '0.000100') <= 0) {
                continue;
            }

            $newContext = $this->recipeLineContext(
                $conn,
                $originalOrderId,
                $detailId,
                $this->nullableString($detail['uuid'] ?? null),
                $itemId,
                RecipeDecimal::divide($remainingRawQty, $uVal),
                (int) ($detail['det_store'] ?? 0),
                $channel,
                $orderType,
                [],
                $request,
                $context
            );
            $remainingLines[] = $newContext;
        }

        return [
            'source_lines' => $sourceLines,
            'remaining_lines' => $remainingLines,
        ];
    }

    private function recordRecipeOrderSplit(
        mysqli $conn,
        int $originalOrderId,
        int $childOrderId,
        array $sourceLines,
        array $remainingLines,
        array $paidLines,
        string $channel,
        string $orderType,
        array $request = [],
        array $context = []
    ): void {
        if ($originalOrderId < 1 || $childOrderId < 1) {
            return;
        }

        $sourceLines = array_values(array_filter($sourceLines, 'is_array'));
        $remainingLines = array_values(array_filter($remainingLines, 'is_array'));
        $paidLines = array_values(array_filter($paidLines, 'is_array'));
        if (!$sourceLines && !$remainingLines && !$paidLines) {
            return;
        }

        $base = $this->recipeBaseContext($conn, $originalOrderId, 0, $channel, $orderType, $request, $context);
        $base['reason'] = 'split_payment';
        $base['source_lines'] = $sourceLines;
        $base['remaining_lines'] = $remainingLines;
        $base['paid_order_id'] = $childOrderId;
        $base['paid_lines'] = $paidLines;
        $this->recipeLifecycleService->onOrderSplit($base);
    }

    private function recordRecipeOrderLinesAdded(mysqli $conn, array $lines): void
    {
        foreach ($lines as $line) {
            if (is_array($line)) {
                $line['conn'] = $conn;
                $this->recipeLifecycleService->onOrderLineAdded($line);
            }
        }
    }

    private function recordRecipeOrderLinesCancelled(mysqli $conn, array $lines, string $reason): void
    {
        foreach ($lines as $line) {
            if (is_array($line)) {
                $line['conn'] = $conn;
                $this->recipeLifecycleService->onOrderLineCancelled($line, $reason);
            }
        }
    }

    private function recordRecipeOrderPaid(
        mysqli $conn,
        int $orderId,
        array $lines,
        string $channel,
        string $orderType,
        array $request = [],
        array $context = []
    ): void {
        if ($orderId < 1 || !$lines) {
            return;
        }

        $base = $this->recipeBaseContext($conn, $orderId, 0, $channel, $orderType, $request, $context);
        $base['lines'] = array_values(array_filter($lines, 'is_array'));
        if (!$base['lines']) {
            return;
        }

        $this->recipeLifecycleService->onOrderPaid($base);
    }

    private function recordRecipePaidOrderReversal(
        mysqli $conn,
        int $orderId,
        array $lines,
        string $channel,
        string $orderType,
        string $action,
        array $request = [],
        array $context = []
    ): ?array {
        if ($orderId < 1 || !$lines) {
            return null;
        }

        $base = $this->recipeBaseContext($conn, $orderId, 0, $channel, $orderType, $request, $context);
        $base['lines'] = array_values(array_filter($lines, 'is_array'));
        if (!$base['lines']) {
            return null;
        }

        $reverseContext = array_merge($context, [
            'created_by' => $this->contextUserId($request, $context),
            'policy' => $this->resolveRecipeRefundPolicy($request, $context),
            'refund_uuid' => $this->nullableString(
                $request['refund_uuid']
                ?? $request['void_uuid']
                ?? ($action === 'void' ? 'pos-order-void:' . $orderId : 'pos-order-refund:' . $orderId)
            ),
        ]);

        return $action === 'void'
            ? $this->recipeLifecycleService->onOrderVoided($base, $reverseContext)
            : $this->recipeLifecycleService->onOrderRefunded($base, $reverseContext);
    }

    private function resolveRecipeRefundPolicy(array $request = [], array $context = []): string
    {
        $configured = $this->recipeSettingsService->refundStockPolicy($context);
        if ($configured !== 'manager_choice') {
            return $configured;
        }

        $requested = strtolower(trim((string) (
            $request['refund_stock_policy']
            ?? $request['policy']
            ?? $context['refund_stock_policy']
            ?? $context['policy']
            ?? ''
        )));

        return in_array($requested, ['waste', 'return_to_stock'], true) ? $requested : 'waste';
    }

    private function recipeChannelAndOrderType(string $storedOrderType): array
    {
        $storedOrderType = strtolower(trim($storedOrderType));
        if ($storedOrderType === 'table') {
            return ['table', 'dine_in'];
        }
        if ($storedOrderType === 'delivery') {
            return ['pos', 'delivery'];
        }

        return ['pos', 'takeaway'];
    }

    private function recipeLineContext(
        mysqli $conn,
        int $orderId,
        int $detailId,
        ?string $detailUuid,
        int $itemId,
        $quantity,
        int $storeId,
        string $channel,
        string $orderType,
        array $sourceItem = [],
        array $request = [],
        array $context = []
    ): array {
        $line = $this->recipeBaseContext($conn, $orderId, $storeId, $channel, $orderType, $request, $context);
        $line['fat_detail_id'] = $detailId > 0 ? $detailId : null;
        $line['order_line_uuid'] = $this->nullableString($detailUuid);
        $line['sellable_item_id'] = $itemId;
        $line['item_id'] = $itemId;
        $line['quantity'] = $this->decimalString($quantity);
        $line['qty'] = $this->decimalString($quantity);

        $variantId = (int) ($sourceItem['variant_id'] ?? $sourceItem['variantId'] ?? 0);
        if ($variantId > 0) {
            $line['variant_id'] = $variantId;
        }

        foreach (['modifiers', 'modifier_options', 'selected_modifiers', 'options'] as $modifierKey) {
            if (isset($sourceItem[$modifierKey]) && is_array($sourceItem[$modifierKey])) {
                $line['modifiers'] = $sourceItem[$modifierKey];
                break;
            }
        }
        $managerApprovalId = (int) (
            $sourceItem['manager_approval_id']
            ?? $sourceItem['recipe_stock_manager_approval_id']
            ?? $request['manager_approval_id']
            ?? $context['manager_approval_id']
            ?? 0
        );
        if ($managerApprovalId > 0) {
            $line['manager_approval_id'] = $managerApprovalId;
        }

        return $line;
    }

    private function recipeQuantityFromLegacyStockValues($qtyIn, $qtyOut, $uVal): string
    {
        $qtyIn = RecipeDecimal::normalize($qtyIn);
        $qtyOut = RecipeDecimal::normalize($qtyOut);
        $unitValue = RecipeDecimal::normalize($uVal);
        if (RecipeDecimal::compare($unitValue, '0') <= 0) {
            $unitValue = '1.000000';
        }

        $difference = RecipeDecimal::compare($qtyOut, $qtyIn) >= 0
            ? RecipeDecimal::subtract($qtyOut, $qtyIn)
            : RecipeDecimal::subtract($qtyIn, $qtyOut);

        if (RecipeDecimal::compare($difference, '0') <= 0) {
            return '0.000000';
        }

        return RecipeDecimal::divide($difference, $unitValue);
    }

    private function recipeBaseContext(
        mysqli $conn,
        int $orderId,
        int $storeId,
        string $channel,
        string $orderType,
        array $request = [],
        array $context = []
    ): array {
        $config = is_array($context['config'] ?? null) ? $context['config'] : [];
        $branchUuid = $this->nullableString(
            $context['branch_uuid']
            ?? $request['branch_uuid']
            ?? ($config['sync']['branch_uuid'] ?? null)
            ?? getenv('POSMAIN_BRANCH_UUID')
            ?: null
        );

        return [
            'conn' => $conn,
            'tenant' => (int) ($context['tenant'] ?? $context['pos_tenant'] ?? $request['tenant'] ?? $request['pos_tenant'] ?? 0),
            'branch' => (int) ($context['branch'] ?? $context['pos_branch'] ?? $request['branch'] ?? $request['pos_branch'] ?? 0),
            'branch_uuid' => $branchUuid,
            'store_id' => max(0, $storeId),
            'order_id' => $orderId,
            'channel' => $channel,
            'order_type' => $orderType,
            'requested_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function decimalString($value): string
    {
        return RecipeDecimal::normalize($value);
    }

    private function applyPaidState(mysqli $conn, int $orderId, int $tableId, float $existingPaid, float $net): array
    {
        $appliedPaid = min($existingPaid, $net);
        $remaining = max(0, $net - $appliedPaid);
        if ($appliedPaid <= 0) {
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

        $this->tableOrderService->execute($conn, "
            UPDATE ot_head
            SET paid_amount = ?,
                remaining_amount = ?,
                payment_status = ?,
                invoice_status = ?,
                order_status = ?,
                completed_at = CASE WHEN ? = 'completed' THEN NOW() ELSE completed_at END
            WHERE id = ?
              AND table_id = ?
        ", [$appliedPaid, $remaining, $paymentStatus, $invoiceStatus, $orderStatus, $orderStatus, $orderId, $tableId]);

        return [
            'paid_amount' => $appliedPaid,
            'remaining_amount' => $remaining,
            'payment_status' => $paymentStatus,
            'invoice_status' => $invoiceStatus,
            'order_status' => $orderStatus,
        ];
    }

    private function resolveDefaultClientId(mysqli $conn): int
    {
        $settings = $conn->query("SELECT def_pos_client FROM settings WHERE isdeleted = 0 ORDER BY id ASC LIMIT 1");
        $settingsRow = $settings ? $settings->fetch_assoc() : null;

        return $this->tableOrderService->resolveDefaultCustomerId($conn, (int) ($settingsRow['def_pos_client'] ?? 0));
    }

    private function resolvePosRequestAccounts(mysqli $conn, array $request): array
    {
        $settingsRow = posmain_load_pos_settings_row($conn);
        $resolved = posmain_resolve_pos_invoice_accounts($conn, $settingsRow, $request);

        return array_merge($request, $resolved);
    }

    private function customerSideEffects(): PosCustomerOrderSideEffects
    {
        static $instance = null;

        return $instance ??= new PosCustomerOrderSideEffects();
    }

    private function recordOrderEvent(mysqli $conn, int $orderId, string $eventType, string $eventSource, array $context, array $metadata = []): ?array
    {
        try {
            return $this->orderEventService->recordIfAvailable($conn, $orderId, $eventType, $eventSource, [
                'actor_user_id' => $context['user_id'] ?? null,
                'tenant' => $context['tenant'] ?? $context['pos_tenant'] ?? 0,
                'branch' => $context['branch'] ?? $context['pos_branch'] ?? 0,
                'metadata' => $metadata,
            ]);
        } catch (Throwable $exception) {
            error_log('Order event recording skipped: ' . $exception->getMessage());
            if (SideEffectPolicy::orderEventShouldRollback($exception)) {
                throw $exception;
            }

            return null;
        }
    }

    private function syncBranchConfig(array $request = [], array $context = []): array
    {
        $config = is_array($context['config'] ?? null) ? $context['config'] : [];
        $branchConfig = is_array($config['branch'] ?? null) ? $config['branch'] : [];
        $syncConfig = is_array($config['sync'] ?? null) ? $config['sync'] : [];

        $uuid = $this->nullableString(
            $context['branch_uuid']
            ?? $request['branch_uuid']
            ?? ($branchConfig['uuid'] ?? null)
            ?? ($syncConfig['branch_uuid'] ?? null)
            ?? getenv('POSMAIN_BRANCH_UUID')
            ?: null
        );
        $cloudBaseUrl = $this->nullableString(
            $context['cloud_base_url']
            ?? $request['cloud_base_url']
            ?? ($branchConfig['cloud_base_url'] ?? null)
            ?? ($syncConfig['cloud_base_url'] ?? null)
            ?? getenv('POSMAIN_CLOUD_BASE_URL')
            ?: null
        );

        $branch = [];
        if ($uuid !== null) {
            $branch['uuid'] = $uuid;
        }
        if (array_key_exists('tenant', $context) || array_key_exists('pos_tenant', $context) || array_key_exists('tenant', $request) || array_key_exists('pos_tenant', $request)) {
            $branch['pos_tenant'] = (int) ($context['tenant'] ?? $context['pos_tenant'] ?? $request['tenant'] ?? $request['pos_tenant'] ?? 0);
        }
        if (array_key_exists('branch', $context) || array_key_exists('pos_branch', $context) || array_key_exists('branch', $request) || array_key_exists('pos_branch', $request)) {
            $branch['pos_branch'] = (int) ($context['branch'] ?? $context['pos_branch'] ?? $request['branch'] ?? $request['pos_branch'] ?? 0);
        }
        if ($cloudBaseUrl !== null) {
            $branch['cloud_base_url'] = $cloudBaseUrl;
        }

        return $branch;
    }

    private function requiredItems(array $request): array
    {
        $items = $request['items'] ?? null;
        if (!is_array($items) || !$items) {
            throw new InvalidArgumentException('الرجاء إضافة أصناف للطلب');
        }

        return $items;
    }

    private function lineNoteFromItem(array $item): string
    {
        foreach (['note', 'kitchen_note', 'notes', 'line_note'] as $key) {
            if (array_key_exists($key, $item)) {
                if (is_array($item[$key])) {
                    continue;
                }

                return trim((string) $item[$key]);
            }
        }

        return '';
    }

    private function persistLineCustomizationsIfAvailable(
        mysqli $conn,
        int $orderId,
        int $detailId,
        int $itemId,
        array $item,
        float $lineQty,
        array $context = []
    ): void {
        $note = $this->lineNoteFromItem($item);
        $modifiers = $this->lineModifiersFromItem($item, $lineQty);
        $hasModifierPayload = $this->itemHasModifierPayload($item);

        if (!$hasModifierPayload && !$modifiers) {
            $this->persistLineNoteIfAvailable($conn, $orderId, $detailId, $itemId, $note, $context);
            return;
        }

        if (!$this->lineNoteServiceTablesAvailable($conn)) {
            $this->persistLineNoteIfAvailable($conn, $orderId, $detailId, $itemId, $note, $context);
            return;
        }

        $notes = $note !== '' ? [['note_type' => 'kitchen', 'note_text' => $note]] : [];
        $this->modifierLineNoteService->saveLineCustomizations(
            $conn,
            $orderId,
            $detailId,
            $itemId,
            $modifiers,
            $notes,
            [
                'modifiers_enabled' => true,
                'user_id' => (int) ($context['user_id'] ?? 0),
            ]
        );
    }

    private function itemHasModifierPayload(array $item): bool
    {
        foreach (['modifiers', 'modifier_options', 'selected_modifiers', 'itmmodifiers'] as $key) {
            if (array_key_exists($key, $item)) {
                return true;
            }
        }

        return false;
    }

    private function lineModifiersFromItem(array $item, float $lineQty): array
    {
        foreach (['modifiers', 'modifier_options', 'selected_modifiers', 'itmmodifiers'] as $key) {
            if (!array_key_exists($key, $item)) {
                continue;
            }

            $decoded = $this->decodeLineModifiers($item[$key]);
            if (!$decoded) {
                return [];
            }

            $lineQty = $lineQty > 0 ? $lineQty : 1.0;
            $scaled = [];
            foreach ($decoded as $modifier) {
                if (is_array($modifier)) {
                    $optionId = (int) ($modifier['option_id'] ?? $modifier['id'] ?? $modifier['modifier_option_id'] ?? 0);
                    if ($optionId <= 0) {
                        continue;
                    }
                    $perItemQty = (float) ($modifier['qty'] ?? $modifier['quantity'] ?? 1);
                    if ($perItemQty <= 0) {
                        continue;
                    }
                    $scaled[] = [
                        'option_id' => $optionId,
                        'qty' => $perItemQty * $lineQty,
                    ];
                } else {
                    $optionId = (int) $modifier;
                    if ($optionId > 0) {
                        $scaled[] = [
                            'option_id' => $optionId,
                            'qty' => $lineQty,
                        ];
                    }
                }
            }

            return $scaled;
        }

        return [];
    }

    private function decodeLineModifiers($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function persistLineNoteIfAvailable(mysqli $conn, int $orderId, int $detailId, int $itemId, $note, array $context = []): void
    {
        $note = trim((string) $note);
        if ($note === '' || !$this->tableExists($conn, 'order_line_notes')) {
            return;
        }

        if ($this->lineNoteServiceTablesAvailable($conn)) {
            try {
                $this->modifierLineNoteService->saveLineCustomizations(
                    $conn,
                    $orderId,
                    $detailId,
                    $itemId,
                    [],
                    [['note_type' => 'kitchen', 'note_text' => $note]],
                    [
                        'modifiers_enabled' => true,
                        'user_id' => (int) ($context['user_id'] ?? 0),
                    ]
                );
                return;
            } catch (Throwable $exception) {
                error_log('Modifier line note service skipped: ' . $exception->getMessage());
            }
        }

        $this->replaceKitchenLineNoteDirectly($conn, $orderId, $detailId, $note, (int) ($context['user_id'] ?? 0));
    }

    private function lineNoteServiceTablesAvailable(mysqli $conn): bool
    {
        foreach (['order_line_modifiers', 'item_modifier_groups', 'modifier_groups', 'modifier_options'] as $tableName) {
            if (!$this->tableExists($conn, $tableName)) {
                return false;
            }
        }

        return true;
    }

    private function replaceKitchenLineNoteDirectly(mysqli $conn, int $orderId, int $detailId, string $note, int $userId): void
    {
        try {
            if (function_exists('mb_substr')) {
                $note = mb_substr($note, 0, 500);
            } else {
                $note = substr($note, 0, 500);
            }

            $delete = $conn->prepare("DELETE FROM order_line_notes WHERE order_id = ? AND detail_id = ? AND note_type = 'kitchen'");
            $delete->bind_param('ii', $orderId, $detailId);
            $delete->execute();
            $delete->close();

            $type = 'kitchen';
            $createdBy = $userId > 0 ? $userId : null;
            $insert = $conn->prepare("
                INSERT INTO order_line_notes (order_id, detail_id, note_type, note_text, created_by)
                VALUES (?, ?, ?, ?, ?)
            ");
            $insert->bind_param('iissi', $orderId, $detailId, $type, $note, $createdBy);
            $insert->execute();
            $insert->close();
        } catch (Throwable $exception) {
            error_log('Direct line note persistence skipped: ' . $exception->getMessage());
        }
    }

    private function assertItemsAvailable(mysqli $conn, array $items, array $request, array $context): void
    {
        if (!$this->tableExists($conn, 'item_availability')) {
            return;
        }

        $scope = [
            'tenant' => $this->scopeNonNegativeInt($request, $context, ['tenant', 'pos_tenant']),
            'branch' => $this->scopeNonNegativeInt($request, $context, ['branch', 'pos_branch']),
            'channel' => $request['availability_channel'] ?? $request['channel'] ?? $context['availability_channel'] ?? $context['channel'] ?? 'pos',
            'order_type' => $request['order_type'] ?? $context['order_type'] ?? 'takeaway',
            'store_id' => $request['store_id'] ?? $context['store_id'] ?? 0,
        ];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $itemId = (int) ($item['id'] ?? $item['item_id'] ?? 0);
            if ($itemId <= 0) {
                continue;
            }

            $availability = $this->itemAvailabilityService->availabilityForItem($conn, $itemId, $scope);
            if (!empty($availability['is_available'])) {
                continue;
            }

            $this->itemAvailabilityService->assertAvailabilityCanAdd($availability);
            $isRecipeUnavailable = !empty($availability['manual_is_available'])
                && !empty($availability['recipe_enabled'])
                && (string) ($availability['availability_status'] ?? '') === 'recipe_unavailable';
            if ($isRecipeUnavailable) {
                // Warn-only contract (Fix 3): when the shop runs non-strict with
                // allow-negative-stock-with-approval, the cashier presentation marks the
                // item availability_warn_only=true and the JS shows a non-blocking toast
                // instead of the manager-approval modal. The server MUST honor the same
                // contract here — do NOT throw MANAGER_APPROVAL_REQUIRED for warn-only.
                // Record a warn-only audit entry (no approval id) and proceed with the sale.
                if (!empty($availability['availability_warn_only'])) {
                    $this->recordRecipeStockOverrideAudit(
                        $conn,
                        $itemId,
                        $availability,
                        ['approved_by' => $this->contextUserId($request, $context), 'warn_only' => true],
                        $request,
                        $context,
                    );
                    continue;
                }

                $approval = $this->managerApprovalService->requireApprovedIfNeeded(
                    $conn,
                    'recipe.stock_override',
                    'item',
                    $itemId,
                    1.0,
                    [
                        'manager_approval_id' => $item['manager_approval_id']
                            ?? $item['recipe_stock_manager_approval_id']
                            ?? $request['manager_approval_id']
                            ?? null,
                    ],
                    ['require_manager_approval' => true]
                );
                $this->recordRecipeStockOverrideAudit($conn, $itemId, $availability, $approval, $request, $context);
            }
        }
    }

    private function recordRecipeStockOverrideAudit(
        mysqli $conn,
        int $itemId,
        array $availability,
        ?array $approval,
        array $request,
        array $context
    ): void {
        if (!$approval || !$this->tableExists($conn, 'recipe_audit_log')) {
            return;
        }

        try {
            $actor = new RecipeActorContext(
                (int) ($approval['approved_by'] ?? $this->contextUserId($request, $context)),
                (int) ($context['tenant'] ?? $context['pos_tenant'] ?? $request['tenant'] ?? $request['pos_tenant'] ?? 0),
                (int) ($context['branch'] ?? $context['pos_branch'] ?? $request['branch'] ?? $request['pos_branch'] ?? 0),
                $context['branch_uuid'] ?? $request['branch_uuid'] ?? null,
                ['pos.recipe_stock_override'],
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            );
            $this->recipeAuditService->record(
                $conn,
                $actor,
                'availability_override',
                'item',
                $itemId,
                isset($availability['recipe_id']) ? (int) $availability['recipe_id'] : null,
                null,
                [
                    'manager_approval_id' => (int) ($approval['id'] ?? 0),
                    'unavailable_reason' => $availability['unavailable_reason'] ?? $availability['recipe_unavailable_reason'] ?? null,
                    'effective_available_qty' => $availability['recipe_effective_available_qty'] ?? null,
                    'source' => $context['event_source'] ?? 'pos_order',
                    'warn_only' => !empty($approval['warn_only']),
                ]
            );
        } catch (Throwable $exception) {
            error_log('Recipe stock override audit skipped: ' . $exception->getMessage());
        }
    }

    private function requireDiscountApprovalIfNeeded(mysqli $conn, ?int $orderId, float $discount, array $request, array $context): void
    {
        $this->managerApprovalService->requireApprovedIfNeeded(
            $conn,
            'discount.override',
            'pos_order',
            $orderId,
            max(0, $discount),
            $request,
            $context
        );
    }

    private function itemIdsFromLines(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $itemId = (int) ($item['id'] ?? $item['item_id'] ?? 0);
            if ($itemId > 0) {
                $ids[$itemId] = $itemId;
            }
        }

        return array_values($ids);
    }

    private function scopeNonNegativeInt(array $request, array $context, array $keys): int
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $request) && $request[$key] !== '' && $request[$key] !== null) {
                return max(0, (int) $request[$key]);
            }
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

    private function columnExists(mysqli $conn, string $tableName, string $columnName): bool
    {
        $tableName = $conn->real_escape_string($tableName);
        $columnName = $conn->real_escape_string($columnName);
        $result = $conn->query("SHOW COLUMNS FROM `{$tableName}` LIKE '{$columnName}'");

        return $result && $result->num_rows > 0;
    }

    private function requiredPositiveInt(array $request, string $key, string $message): int
    {
        $value = (int) ($request[$key] ?? 0);
        if ($value <= 0) {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }

    private function contextUserId(array $request, array $context): int
    {
        return posmain_resolve_pos_user_id(array_merge($context, $request));
    }

    private function voidDeliveryOrderHeaderAndLines(mysqli $conn, int $orderId, int $userId, string $reason): void
    {
        $this->tableOrderService->execute($conn, "
            UPDATE ot_head
            SET order_status = 'cancelled',
                invoice_status = 'cancelled',
                payment_status = 'voided',
                isdeleted = 1,
                cancelled_at = NOW(),
                cancelled_by = ?,
                cancellation_reason = ?
            WHERE id = ?
              AND pro_tybe = 9
        ", [$userId, $reason, $orderId]);

        $this->tableOrderService->execute($conn, "
            UPDATE fat_details
            SET isdeleted = 1
            WHERE fatid = ?
        ", [$orderId]);
    }

    private function finalizeDeliveryOrderVoid(mysqli $conn, int $orderId, int $userId, string $reason): void
    {
        $this->tableOrderService->execute($conn, "
            UPDATE ot_head
            SET payment_status = 'voided',
                cancelled_at = NOW(),
                cancelled_by = ?,
                cancellation_reason = ?
            WHERE id = ?
              AND pro_tybe = 9
        ", [$userId, $reason, $orderId]);
    }
}
