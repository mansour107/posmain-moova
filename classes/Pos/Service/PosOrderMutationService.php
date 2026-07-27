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
require_once __DIR__ . '/PreparationSelectionService.php';
require_once __DIR__ . '/../../Sync/SyncOutboxEventService.php';
require_once __DIR__ . '/../../Moova/MoovaNewOrderApplyService.php';
require_once __DIR__ . '/../../Moova/MoovaChangeOrderApplyService.php';
require_once __DIR__ . '/../../Recipe/RecipeDecimal.php';
require_once __DIR__ . '/../../Recipe/RecipeOrderLifecycleService.php';
require_once __DIR__ . '/../../Recipe/RecipeSettingsService.php';
require_once __DIR__ . '/../../Recipe/RecipeAuditService.php';
require_once __DIR__ . '/../../Recipe/DTO/RecipeActorContext.php';
require_once __DIR__ . '/../../Recipe/DTO/RecipeScope.php';
require_once __DIR__ . '/../../Recipe/ExternalOrderLineIdentityService.php';
require_once __DIR__ . '/../../Inventory/InventoryInvoiceBridge.php';
require_once __DIR__ . '/PosCustomerOrderLinkService.php';
require_once __DIR__ . '/PosCustomerOrderSideEffects.php';
require_once __DIR__ . '/PosCustomerService.php';
require_once __DIR__ . '/DeliveryZoneService.php';
require_once __DIR__ . '/OrderFulfillmentService.php';
$deliveryWorkerServicePath = __DIR__ . '/DeliveryWorkerService.php';
if (is_file($deliveryWorkerServicePath)) {
    require_once $deliveryWorkerServicePath;
}
require_once __DIR__ . '/SideEffectPolicy.php';
require_once __DIR__ . '/OrderRevisionService.php';
require_once __DIR__ . '/OrderMutationVersionService.php';
require_once __DIR__ . '/KdsTicketService.php';
require_once __DIR__ . '/../../../includes/pos_user_context.php';
require_once __DIR__ . '/../../PosOrderService.php';
require_once __DIR__ . '/../../../includes/pos_default_accounts.php';
require_once __DIR__ . '/../../Items/ItemUnitResolver.php';
require_once __DIR__ . '/../../Items/ItemUnitConversionFeatureFlags.php';
require_once __DIR__ . '/../../Accounting/PaymentReconciliationService.php';
require_once __DIR__ . '/DrawerSessionService.php';
require_once __DIR__ . '/PaymentMethodService.php';
require_once __DIR__ . '/../../Financial/Money.php';
require_once __DIR__ . '/../../Financial/DecimalQuantity.php';
require_once __DIR__ . '/../../Financial/UnitPrice.php';
require_once __DIR__ . '/../../Financial/RoundingPolicy.php';
require_once __DIR__ . '/../../Financial/FinancialCertifiedMode.php';
require_once __DIR__ . '/../../Financial/FinancialInvoicePostingService.php';
require_once __DIR__ . '/../../Financial/FinancialRefundService.php';
require_once __DIR__ . '/../../Accounting/JournalPostingService.php';

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
    private $paymentService;
    private $tableStateService;
    private $tableOrderService;
    private $inventoryMovementService;
    private $orderEventService;
    private $idempotencyService;
    private $itemAvailabilityService;
    private $managerApprovalService;
    private $modifierLineNoteService;
    private $preparationSelectionService;
    private $recipeLifecycleService;
    private $recipeSettingsService;
    private $recipeAuditService;
    private $inventoryInvoiceBridge;
    private $drawerSessionService;

    public function __construct(?PaymentService $paymentService = null, ?TableStateService $tableStateService = null, ?TableOrderService $tableOrderService = null, ?InventoryMovementService $inventoryMovementService = null, ?OrderEventService $orderEventService = null, ?IdempotencyService $idempotencyService = null, ?ItemAvailabilityService $itemAvailabilityService = null, ?ManagerApprovalService $managerApprovalService = null, ?ModifierLineNoteService $modifierLineNoteService = null, ?RecipeOrderLifecycleService $recipeLifecycleService = null, ?RecipeSettingsService $recipeSettingsService = null, ?RecipeAuditService $recipeAuditService = null, ?InventoryInvoiceBridge $inventoryInvoiceBridge = null, ?DrawerSessionService $drawerSessionService = null)
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
        $this->preparationSelectionService = new PreparationSelectionService();
        $this->recipeLifecycleService = $recipeLifecycleService ?: new RecipeOrderLifecycleService();
        $this->recipeSettingsService = $recipeSettingsService ?: new RecipeSettingsService();
        $this->recipeAuditService = $recipeAuditService ?: new RecipeAuditService();
        $this->inventoryInvoiceBridge = $inventoryInvoiceBridge ?: new InventoryInvoiceBridge();
        $this->drawerSessionService = $drawerSessionService ?: new DrawerSessionService();
    }

    public function payTableOrder(mysqli $conn, array $request, array $context = []): array
    {
        if (!empty($context['skip_idempotency'])) {
            return $this->payTableOrderWithTransaction($conn, $request, $context);
        }

        $ownsTransaction = empty($context['in_transaction']) && empty($context['transaction_started']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $idempotency = $this->beginIdempotency($conn, self::SCOPE_TABLE_PAYMENT, $request, $context);
            if ($idempotency['status'] === 'completed') {
                if ($ownsTransaction) {
                    $conn->commit();
                }

                $response = is_array($idempotency['response'] ?? null) ? $idempotency['response'] : [];
                $response['idempotency_replayed'] = true;
                return $response;
            }

            $result = $this->payTableOrderInsideTransaction($conn, $request, $context);
            $this->idempotencyService->complete(
                $conn,
                self::SCOPE_TABLE_PAYMENT,
                $idempotency['key'],
                $idempotency['hash'],
                $result
            );
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

    private function payTableOrderWithTransaction(mysqli $conn, array $request, array $context): array
    {
        $ownsTransaction = empty($context['in_transaction']) && empty($context['transaction_started']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $result = $this->payTableOrderInsideTransaction($conn, $request, $context);
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

    private function payTableOrderInsideTransaction(mysqli $conn, array $request, array $context): array
    {
        $result = $this->paymentService->payTableOrder($conn, $request, $context);
        $orderId = (int) ($result['data']['order_id'] ?? 0);
        if ($orderId > 0 && !empty($result['data']['fully_paid'])) {
            $lines = $this->loadRecipeOrderLineContexts($conn, $orderId, 'table', 'dine_in', $request, $context);
            $inventoryLines = $this->loadInventoryInvoiceBridgeLines($conn, $orderId);
            $this->consumeInventoryInvoiceBridgeReservations(
                $conn,
                $orderId,
                $inventoryLines,
                'table',
                'dine_in',
                $request,
                $context
            );
            $this->recordRecipeOrderPaid($conn, $orderId, $lines, 'table', 'dine_in', $request, $context);
        }
        if ($orderId > 0) {
            $this->customerSideEffects()->afterOrderSaved($conn, $orderId, $request, 'table', [
                'paid_amount' => $this->moneyFromBoundary($result['data']['paid_amount'] ?? '0')->toString(),
                'payment_status' => (string) ($result['data']['payment_status'] ?? 'unpaid'),
                'config' => $context['config'] ?? null,
            ]);
            $this->recordOrderEvent($conn, $orderId, 'order.payment_recorded', $context['event_source'] ?? 'pos_table_payment', $context, [
                'payment_status' => $result['data']['payment_status'] ?? null,
                'order_status' => $result['data']['order_status'] ?? null,
                'paid_amount' => $result['data']['paid_amount'] ?? null,
                'remaining_amount' => $result['data']['remaining_amount'] ?? null,
                'applied_amount' => $result['data']['applied_amount'] ?? null,
            ]);
            $result['data']['mutation_version'] = $this->mutationVersionService()->bumpAndGet($conn, $orderId);
        }

        return $result;
    }

    public function cancelTableOrder(mysqli $conn, array $request, array $context = []): array
    {
        $orderId = (int) ($request['order_id'] ?? 0);
        $tableId = (int) ($request['table_id'] ?? 0);
        $order = ($orderId > 0 && $tableId > 0)
            ? $this->tableOrderService->findActiveOrderByTableAndOrderId($conn, $tableId, $orderId, true)
            : null;
        if (!$order) {
            throw new RuntimeException('ORDER_NOT_FOUND');
        }
        if (
            strtolower(trim((string) ($order['payment_status'] ?? 'unpaid'))) !== 'unpaid'
            || $this->moneyIsPositive($order['paid_amount'] ?? '0')
        ) {
            throw new RuntimeException('ORDER_HAS_PAYMENT_USE_REFUND');
        }

        $lines = $this->loadRecipeOrderLineContexts($conn, $orderId, 'table', 'dine_in', $request, $context);
        $this->recordRecipeOrderLinesCancelled($conn, $lines, 'order_cancelled');
        $this->releaseInventoryInvoiceBridgeReservations(
            $conn,
            $orderId,
            $this->loadInventoryInvoiceBridgeLines($conn, $orderId),
            'order_cancelled',
            'table',
            'dine_in',
            $request,
            $context
        );
        $result = $this->tableStateService->cancelActiveOrder($conn, $request, $context);
        $orderId = (int) ($result['data']['order_id'] ?? 0);
        if ($orderId > 0) {
            $this->recordOrderEvent($conn, $orderId, 'order.cancelled', $context['event_source'] ?? 'pos_order_cancel', $context, [
                'table_id' => $result['data']['table_id'] ?? null,
                'table_freed' => $result['data']['table_freed'] ?? null,
                'reason' => $request['reason'] ?? $request['cancellation_reason'] ?? null,
            ]);
            (new KdsTicketService())->syncForOrder($conn, $orderId, 'cancelled', $this->contextUserId($request, $context), [
                'reason' => $request['reason'] ?? $request['cancellation_reason'] ?? '',
                'manager_approval_id' => $request['manager_approval_id'] ?? null,
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
            if ($paymentStatus === 'paid' || $this->moneyIsPositive($order['paid_amount'] ?? '0')) {
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
                    $this->releaseInventoryInvoiceBridgeReservations(
                        $conn,
                        $orderId,
                        $inventoryLines,
                        'delivery_cancelled',
                        'pos',
                        'delivery',
                        $request,
                        $context
                    );
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
            (new KdsTicketService())->syncForOrder($conn, $orderId, 'cancelled', $userId, [
                'reason' => $reason,
                'manager_approval_id' => $request['manager_approval_id'] ?? null,
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
        $headDiscount = $this->moneyFromBoundary($request['headdisc'] ?? $request['discount'] ?? '0');
        $zoneResolved = (new DeliveryZoneService())->resolvePostedZone($conn, $request);
        $deliveryFee = $this->moneyFromBoundary($zoneResolved['delivery_fee'] ?? '0');
        $deliveryZoneName = trim((string) ($zoneResolved['delivery_zone_name'] ?? ''));
        $headPlus = $this->moneyFromBoundary($request['headplus'] ?? $request['plus'] ?? '0');
        if ($deliveryFee->compare($headPlus) > 0) {
            $headPlus = $deliveryFee;
        }

        $lineSubtotal = $this->sumPostedItemSubtotal($request);
        $headTotal = $lineSubtotal->isPositive()
            ? $lineSubtotal
            : $this->moneyFromBoundary($request['headtotal'] ?? $request['total'] ?? '0');
        $headNet = $headTotal->subtract($headDiscount)->add($headPlus);
        if ($headNet->isNegative()) {
            throw new InvalidArgumentException('PAYMENT_AMOUNT_INVALID');
        }

        return [
            'headtotal' => $headTotal->toString(),
            'headdisc' => $headDiscount->toString(),
            'headplus' => $headPlus->toString(),
            'headnet' => $headNet->toString(),
            'delivery_fee' => $deliveryFee->toString(),
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
            if ($action === 'void' && (
                !empty($request['lines'])
                || !empty($request['payments'])
                || trim((string) ($request['refund_amount'] ?? '')) !== ''
                || !in_array(strtolower(trim((string) ($request['refund_mode'] ?? 'full'))), ['', 'full'], true)
            )) {
                throw new InvalidArgumentException('PARTIAL_VOID_NOT_SUPPORTED');
            }

            $order = $this->tableOrderService->queryOne($conn, "
                SELECT id, table_id, pro_tybe, order_type, payment_status, invoice_status,
                       order_status, fat_net, paid_amount, remaining_amount, mutation_version, isdeleted
                FROM ot_head
                WHERE id = ?
                  AND pro_tybe = 9
                LIMIT 1
                FOR UPDATE
            ", [$orderId]);
            if (!$order) {
                throw new RuntimeException('ORDER_NOT_FOUND');
            }

            $financialRefunds = new FinancialRefundService();
            $explicitIdempotencyKey = trim((string) ($request['idempotency_key'] ?? $context['idempotency_key'] ?? ''));
            if ($explicitIdempotencyKey !== '') {
                $existingRefund = $financialRefunds->findPostedRefundByIdempotency(
                    $conn,
                    $explicitIdempotencyKey,
                    $orderId,
                    $request
                );
                if ($existingRefund !== null) {
                    if ($ownsTransaction) {
                        $conn->commit();
                    }

                    return $this->paidReversalResponse(
                        $order,
                        $action,
                        $existingRefund,
                        null,
                        $this->resolveRecipeRefundPolicy($request, $context)
                    );
                }
            }

            if ((int) ($order['isdeleted'] ?? 0) === 1) {
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
            $scope = $this->orderAccountingScope($conn, $orderId);
            $tenant = max(0, (int) ($context['tenant'] ?? $context['pos_tenant'] ?? $request['tenant'] ?? $request['pos_tenant'] ?? $scope['tenant']));
            $branch = max(0, (int) ($context['branch'] ?? $context['pos_branch'] ?? $request['branch'] ?? $request['pos_branch'] ?? $scope['branch']));
            $refundPreview = $action === 'refund'
                ? $financialRefunds->previewRefund($conn, $orderId, $request)
                : null;
            $amount = $refundPreview !== null
                ? $this->moneyFromBoundary($refundPreview['total_amount'])->toString()
                : $this->moneyFromBoundary($order['paid_amount'] ?? $order['fat_net'] ?? '0')->toString();
            $limitKey = $action === 'void' ? 'pos.void.paid' : 'pos.refund.limit';
            $escalationKey = $action === 'void' ? 'pos.void.paid' : 'pos.refund';
            $approval = $this->managerApprovalService->requireApprovedIfNeeded(
                $conn,
                $escalationKey,
                'order',
                $orderId,
                $amount,
                $request,
                array_merge($context, [
                    'user_id' => $userId,
                    'limit_permission_key' => $limitKey,
                    'escalation_permission_key' => $escalationKey,
                ])
            );

            $request = $this->resolvePosRequestAccounts($conn, $request);
            $customerId = (int) ($request['customer_account_id'] ?? $request['acc2_id'] ?? 0);
            $salesAccountId = (int) ($request['revenue_account_id'] ?? $request['sales_account_id'] ?? 0);
            if ($customerId < 1 || $salesAccountId < 1) {
                throw new RuntimeException('REFUND_ACCOUNTS_REQUIRED');
            }

            $refundReason = trim((string) ($request['reason'] ?? $request['refund_reason'] ?? $request['void_reason'] ?? ''));
            if ($refundReason === '') {
                $refundReason = $action === 'void' ? 'paid_order_voided' : 'paid_order_refunded';
            }

            $drawerSessionId = (int) ($request['drawer_session_id'] ?? $context['drawer_session_id'] ?? 0);
            if ($drawerSessionId < 1 && session_status() === PHP_SESSION_ACTIVE) {
                $drawerSessionId = (int) ($_SESSION['pos_drawer_session_id'] ?? 0);
            }

            $managerApprovalId = (int) ($approval['id'] ?? $request['manager_approval_id'] ?? 0);
            $financialRequest = array_merge($request, [
                'original_order_id' => $orderId,
                'customer_account_id' => $customerId,
                'revenue_account_id' => $salesAccountId,
                'user_id' => $userId,
                'reason' => $refundReason,
                'idempotency_key' => $explicitIdempotencyKey !== ''
                    ? $explicitIdempotencyKey
                    : 'paid-reversal:' . $action . ':' . $orderId,
                'refund_stock_policy' => $request['refund_stock_policy'] ?? 'waste',
                'drawer_session_id' => $drawerSessionId,
                'manager_approval_id' => $managerApprovalId > 0 ? $managerApprovalId : null,
                'tenant' => $tenant,
                'branch' => $branch,
            ]);
            $creditNoteRefund = $financialRefunds->createPostedRefund($conn, $financialRequest, [
                'user_id' => $userId,
                'in_transaction' => true,
                'drawer_session_id' => $drawerSessionId,
                'manager_approval_id' => $managerApprovalId > 0 ? $managerApprovalId : null,
                'tenant' => $tenant,
                'branch' => $branch,
                'require_drawer_session' => !empty($context['require_drawer_session']),
                'sync_config' => $context['sync_config'] ?? $context['config'] ?? null,
            ]);

            $reversalStatus = (string) ($creditNoteRefund['reversal_status'] ?? 'full');
            $isFull = $action === 'void' || $reversalStatus === 'full';
            $newPaymentStatus = $isFull ? ($action === 'void' ? 'voided' : 'refunded') : $paymentStatus;
            $reason = trim((string) ($request['reason'] ?? $request['refund_reason'] ?? $request['void_reason'] ?? ''));
            if ($reason === '') {
                $reason = $action === 'void' ? 'paid_order_voided' : 'paid_order_refunded';
            }

            if ($isFull) {
                $this->tableOrderService->execute($conn, "
                    UPDATE ot_head
                    SET payment_status = ?,
                        invoice_status = 'cancelled',
                        order_status = 'cancelled',
                        remaining_amount = 0,
                        cancelled_at = NOW(),
                        cancelled_by = ?,
                        cancellation_reason = ?,
                        updated_by = ?,
                        mdtime = NOW()
                    WHERE id = ?
                ", [$newPaymentStatus, $userId, $reason, $userId, $orderId]);
                $order['payment_status'] = $newPaymentStatus;
                $order['invoice_status'] = 'cancelled';
                $order['order_status'] = 'cancelled';
            }

            $tableId = (int) ($order['table_id'] ?? 0);
            if ($isFull && $tableId > 0) {
                $this->tableOrderService->setTableFreeIfNoActiveOrder($conn, $tableId);
            }

            [$channel, $orderType] = $this->recipeChannelAndOrderType((string) ($order['order_type'] ?? ''));
            $lines = $this->loadRecipeOrderLineContexts($conn, $orderId, $channel, $orderType, $request, $context);
            $lines = $this->recipeLinesForCreditNote(
                $conn,
                $lines,
                (int) ($creditNoteRefund['credit_note_id'] ?? 0)
            );
            $request['refund_uuid'] = 'credit-note:' . (int) ($creditNoteRefund['credit_note_id'] ?? 0);
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
            $policy = $this->resolveRecipeRefundPolicy($request, $context);
            $creditNoteId = (int) ($creditNoteRefund['credit_note_id'] ?? 0);
            // The posted credit-note line is the durable authority for direct
            // stock disposition. Recipe policy is separate: a branch may
            // discard consumed ingredients while still restocking a sealed
            // direct-sale item selected by the manager.
            if ($creditNoteId > 0) {
                $restockLines = $this->loadRefundInventoryInvoiceBridgeLines($conn, $orderId, $creditNoteId);
                if ($restockLines) {
                    $this->recordInventoryInvoiceBridgeReversalLines(
                        $conn,
                        $orderId,
                        $restockLines,
                        'credit_note_' . $creditNoteId,
                        $channel,
                        $orderType,
                        $request,
                        $context
                    );
                }
            }
            $customerRollup = $this->customerSideEffects()->refreshCustomerRollupForOrder($conn, $orderId, [
                'in_transaction' => true,
                'sync_config' => $context['sync_config'] ?? $context['config'] ?? null,
            ]);

            $eventType = $action === 'void'
                ? 'order.voided'
                : ($isFull ? 'order.refunded' : 'order.partially_refunded');
            $eventMetadata = [
                'payment_status_before' => $paymentStatus,
                'payment_status_after' => $newPaymentStatus,
                'refund_stock_policy' => $policy,
                'reason' => $reason,
                'amount' => (string) ($creditNoteRefund['total_amount'] ?? '0.00'),
                'credit_note_id' => (int) ($creditNoteRefund['credit_note_id'] ?? 0),
                'refund_mode' => (string) ($creditNoteRefund['refund_mode'] ?? 'full'),
                'credit_note_total' => (string) ($creditNoteRefund['total_amount'] ?? '0.00'),
                'cumulative_refunded_amount' => (string) ($creditNoteRefund['cumulative_refunded_amount'] ?? '0.00'),
                'remaining_refundable_amount' => (string) ($creditNoteRefund['remaining_refundable_amount'] ?? '0.00'),
                'reversal_status' => $reversalStatus,
                'pending_external_amount' => (string) ($creditNoteRefund['pending_external_amount'] ?? '0.00'),
                'refund_tenders' => $creditNoteRefund['refund_tenders'] ?? [],
                'business_day' => $creditNoteRefund['business_day'] ?? null,
                'drawer_session_id' => $creditNoteRefund['drawer_session_id'] ?? null,
                'customer_rollup_refreshed' => !empty($customerRollup['applied']),
            ];
            if ($approval) {
                $eventMetadata['manager_approval_id'] = (int) ($approval['id'] ?? 0);
            }
            $this->recordOrderEvent($conn, $orderId, $eventType, $context['event_source'] ?? 'pos_paid_reversal', $context, $eventMetadata);
            if ($isFull) {
                (new KdsTicketService())->syncForOrder($conn, $orderId, 'cancelled', $userId, [
                    'reason' => $reason,
                    'manager_approval_id' => $eventMetadata['manager_approval_id'] ?? null,
                ]);
            }

            if ($approval) {
                $this->managerApprovalService->consumeApproval($conn, (int) $approval['id'], $userId);
            }

            $order['mutation_version'] = $this->mutationVersionService()->bumpAndGet($conn, $orderId);
            if ($ownsTransaction) {
                $conn->commit();
            }

            return $this->paidReversalResponse($order, $action, $creditNoteRefund, $recipeResult, $policy);
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $conn->rollback();
            }

            throw $exception;
        }
    }

    private function paidReversalResponse(
        array $order,
        string $action,
        array $refund,
        ?array $recipeResult,
        string $policy
    ): array {
        $reversalStatus = (string) ($refund['reversal_status'] ?? 'full');
        $isFull = $action === 'void' || $reversalStatus === 'full';
        $paymentStatus = (string) ($order['payment_status'] ?? ($isFull ? ($action === 'void' ? 'voided' : 'refunded') : 'paid'));
        $invoiceStatus = (string) ($order['invoice_status'] ?? ($isFull ? 'cancelled' : 'posted'));
        $orderStatus = (string) ($order['order_status'] ?? ($isFull ? 'cancelled' : 'completed'));
        $replayed = !empty($refund['replayed']);

        return [
            'success' => true,
            'code' => $replayed ? 'REFUND_REPLAYED' : 'OK',
            'message' => $action === 'void'
                ? 'ORDER_VOIDED'
                : ($isFull ? 'ORDER_REFUNDED' : 'ORDER_PARTIALLY_REFUNDED'),
            'data' => [
                'order_id' => (int) ($order['id'] ?? 0),
                'table_id' => (int) ($order['table_id'] ?? 0),
                'action' => $action,
                'payment_status' => $paymentStatus,
                'invoice_status' => $invoiceStatus,
                'order_status' => $orderStatus,
                'mutation_version' => max(1, (int) ($order['mutation_version'] ?? 1)),
                'credit_note_id' => (int) ($refund['credit_note_id'] ?? 0),
                'refund_mode' => (string) ($refund['refund_mode'] ?? 'full'),
                'refund_amount' => (string) ($refund['total_amount'] ?? '0.00'),
                'cumulative_refunded_amount' => (string) ($refund['cumulative_refunded_amount'] ?? '0.00'),
                'remaining_refundable_amount' => (string) ($refund['remaining_refundable_amount'] ?? '0.00'),
                'reversal_status' => $reversalStatus,
                'pending_external_amount' => (string) ($refund['pending_external_amount'] ?? '0.00'),
                'refund_tenders' => $refund['refund_tenders'] ?? [],
                'business_day' => $refund['business_day'] ?? null,
                'drawer_session_id' => $refund['drawer_session_id'] ?? null,
                'manager_approval_id' => $refund['manager_approval_id'] ?? null,
                'refund_stock_policy' => $policy,
                'replayed' => $replayed,
                'recipe' => $recipeResult,
            ],
        ];
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
        if (!empty($context['skip_idempotency'])) {
            return $this->splitTablePaymentWithTransaction($conn, $request, $context);
        }

        $ownsTransaction = empty($context['in_transaction']) && empty($context['transaction_started']);
        if ($ownsTransaction) {
            $conn->begin_transaction();
        }

        try {
            $idempotency = $this->beginIdempotency($conn, self::SCOPE_SPLIT_PAYMENT, $request, $context);
            if ($idempotency['status'] === 'completed') {
                if ($ownsTransaction) {
                    $conn->commit();
                }

                $response = is_array($idempotency['response'] ?? null) ? $idempotency['response'] : [];
                $response['idempotency_replayed'] = true;
                return $response;
            }

            $result = $this->splitTablePaymentInsideTransaction($conn, $request, $context);
            $this->idempotencyService->complete(
                $conn,
                self::SCOPE_SPLIT_PAYMENT,
                $idempotency['key'],
                $idempotency['hash'],
                $result
            );
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

    private function splitTablePaymentWithTransaction(mysqli $conn, array $request, array $context): array
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

    public function assertCashierEditVoidApprovalIfNeeded(mysqli $conn, array $request, array $context = []): void
    {
        $orderId = (int) ($request['edit_id'] ?? $request['order_id'] ?? 0);
        if ($orderId < 1) {
            return;
        }
        $this->assertCashierOrderEditable($conn, $orderId);

        $userId = $this->contextUserId($request, $context);
        if ($userId > 0) {
            $roleStmt = $conn->prepare('SELECT userrole FROM users WHERE id = ? LIMIT 1');
            $roleStmt->bind_param('i', $userId);
            $roleStmt->execute();
            $roleRow = $roleStmt->get_result()->fetch_assoc();
            $roleStmt->close();
            $roleId = (int) ($roleRow['userrole'] ?? 0);
            if ($roleId > 0) {
                if (!class_exists('RolePermissionSyncService', false)) {
                    require_once __DIR__ . '/../../Security/RolePermissionSyncService.php';
                }
                RolePermissionSyncService::repairPresetRoleCapabilitiesIfNeeded($conn, $roleId);
            }
        }

        $items = $this->normalizeTakeawayItems($conn, $request);
        $this->ensureItemVoidApprovalPresent($conn, $orderId, $items, $request, $context);
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
                if (SideEffectPolicy::orderEventShouldRollback($exception)) {
                    throw $exception;
                }
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

        $order = $this->tableOrderService->queryOne($conn, 'SELECT * FROM ot_head WHERE id = ? LIMIT 1 FOR UPDATE', [$orderId]);
        if (!$order) {
            throw new InvalidArgumentException('ORDER_NOT_FOUND');
        }
        $this->mutationVersionService()->lockAndAssert(
            $conn,
            $orderId,
            $this->expectedMutationVersion($request),
            true
        );
        $this->assertCashierOrderEditable($conn, $orderId, $order);

        $orderType = trim((string) ($order['order_type'] ?? 'takeaway'));
        if ($orderType === 'table') {
            throw new InvalidArgumentException('USE_TABLE_SAVE_FOR_TABLE_ORDERS');
        }

        $request = $this->resolvePosRequestAccounts($conn, $request);
        $storeId = $this->requiredPositiveInt($request, 'store_id', 'بيانات مطلوبة مفقودة - المخزن');
        $customerId = $this->requiredPositiveInt($request, 'acc2_id', 'بيانات مطلوبة مفقودة - العميل');
        $empId = $this->requiredPositiveInt($request, 'emp_id', 'بيانات مطلوبة مفقودة - الموظف');
        $fundId = $this->requiredPositiveInt($request, 'fund_id', 'بيانات مطلوبة مفقودة - الصندوق');
        $items = $this->normalizeTakeawayItems($conn, $request);
        $this->assertItemsAvailable($conn, $items, $request, $context);
        $userId = $this->contextUserId($request, $context);
        $date = $this->requestDate($request, ['pro_date', 'order_date', 'date'], (string) ($order['pro_date'] ?? date('Y-m-d')));
        $accrualDate = $this->requestDate($request, ['accural_date', 'accrual_date'], $date);
        $headTotal = $this->moneyFromBoundary($request['headtotal'] ?? $request['total'] ?? '0')->toString();
        $headDiscount = $this->moneyFromBoundary($request['headdisc'] ?? $request['discount'] ?? '0')->toString();
        $this->requireOrderEscalationsIfNeeded($conn, $orderId, $headDiscount, $request, $context);
        $this->requireItemVoidApprovalIfNeeded($conn, $orderId, $items, $request, $context);
        $headPlus = $this->moneyFromBoundary($request['headplus'] ?? $request['plus'] ?? '0')->toString();
        $headNet = array_key_exists('headnet', $request) || array_key_exists('net', $request)
            ? $this->moneyFromBoundary($request['headnet'] ?? $request['net'])->toString()
            : $this->moneyFromBoundary($headTotal)
                ->subtract($this->moneyFromBoundary($headDiscount))
                ->add($this->moneyFromBoundary($headPlus))
                ->toString();
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
                $upserted = $posCustomerService->upsertForDelivery(
                    $conn,
                    $deliveryPhone,
                    $deliveryName,
                    $deliveryAddress,
                    $zoneId > 0 ? $zoneId : null,
                    ['in_transaction' => true, 'config' => $context['config'] ?? null]
                );
                $request['pos_customer_id'] = (int) ($upserted['id'] ?? 0);
            }
            $resolvedTotals = $this->resolveDeliveryPostedTotals($conn, $request);
            $headPlus = (string) $resolvedTotals['headplus'];
            $headTotal = (string) $resolvedTotals['headtotal'];
            $headNet = (string) $resolvedTotals['headnet'];
        }
        if ($this->moneyFromBoundary($headNet)->isNegative()) {
            throw new InvalidArgumentException('PAYMENT_AMOUNT_INVALID');
        }

        $paidCash = $this->moneyFromBoundary($request['paid_cash'] ?? $request['paid'] ?? '0')->toString();
        $paidBank = $this->moneyFromBoundary($request['paid_bank'] ?? '0')->toString();
        $paymentFundId = (int) ($request['payment_fund_id'] ?? $fundId);
        $paymentBankId = (int) ($request['payment_bank_id'] ?? 0);
        $payment = $this->calculateTakeawayPayment($headNet, $paidCash, $paidBank);
        if ($this->moneyIsPositive($payment['cash']) && $paymentFundId <= 0) {
            throw new InvalidArgumentException('PAYMENT_FUND_REQUIRED');
        }
        if ($this->moneyIsPositive($payment['bank']) && $paymentBankId <= 0) {
            throw new InvalidArgumentException('PAYMENT_BANK_REQUIRED');
        }

        $status = $this->paidStatusForNet($headNet, $payment['applied']);
        $proId = (int) ($order['pro_id'] ?? 0);
        $channel = $orderType === 'delivery' ? 'delivery' : 'takeaway';
        $info = $this->tableOrderService->buildInfo($channel, '', (string) ($request['info'] ?? ''));
        $fatDiscPer = $this->percentageString($headDiscount, $headTotal);
        $fatPlusPer = $this->percentageString($headPlus, $headTotal);

        $recipeChannel = $orderType === 'delivery' ? 'delivery' : 'takeaway';
        $oldRecipeLines = $this->loadRecipeOrderLineContexts($conn, $orderId, 'pos', $recipeChannel, $request, $context);
        $oldInventoryBridgeLines = $this->loadInventoryInvoiceBridgeLines($conn, $orderId);
        $this->recordRecipeOrderLinesCancelled($conn, $oldRecipeLines, 'order_updated');
        $this->releaseInventoryInvoiceBridgeReservations(
            $conn,
            $orderId,
            $oldInventoryBridgeLines,
            'order_updated',
            'pos',
            $recipeChannel,
            $request,
            $context
        );
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
            $this->moneyFromBoundary($request['jal_amount'] ?? '0')->toString(),
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
        if ($this->moneyIsPositive($payment['cash'])) {
            $receipts[] = $this->insertTakeawayReceipt($conn, $orderId, $proId, $info, $date, $empId, $paymentFundId, $customerId, $payment['cash'], 'كاش', $userId);
        }
        if ($this->moneyIsPositive($payment['bank'])) {
            $receipts[] = $this->insertTakeawayReceipt($conn, $orderId, $proId, $info, $date, $empId, $paymentBankId, $customerId, $payment['bank'], 'صرافة', $userId);
        }
        $this->recordOrderCashPaymentDelta($conn, $orderId, $payment['cash'], $request, $context, 'order_update_cash_payment');
        $this->recordOrderBankPaymentDelta($conn, $orderId, $payment['bank'], $request, $context);

        $lineResult = $this->inventoryMovementService->normalizeInvoiceLines($conn, InventoryMovementService::TYPE_POS, $items, [
            'store_id' => $storeId,
        ]);
        $recipeLines = [];
        $inventoryBridgeLines = [];
        foreach ($lineResult['lines'] as $index => $line) {
            $line['_source_item'] = $items[$index] ?? [];
            $line['note'] = $this->lineNoteFromItem($line['_source_item']);
            $recipeLine = $this->insertTakeawayDetailLine($conn, $orderId, $storeId, $line, $context, 'pos', $recipeChannel);
            $recipeLines[] = $recipeLine;
            $inventoryBridgeLines[] = $this->inventoryBridgeLineFromLegacyLine($line, $recipeLine, $storeId);
        }
        if ($status['order_status'] === 'completed') {
            $this->recordInventoryInvoiceBridgeLines($conn, $orderId, $inventoryBridgeLines, 'pos', $recipeChannel, $request, $context);
        } else {
            $this->reserveInventoryInvoiceBridgeLines($conn, $orderId, $inventoryBridgeLines, 'pos', $recipeChannel, $request, $context);
        }
        $this->recordRecipeOrderLinesAdded($conn, $recipeLines);
        if ($status['order_status'] === 'completed') {
            $this->recordRecipeOrderPaid($conn, $orderId, $recipeLines, 'pos', $recipeChannel, $request, $context);
        }
        $this->tableOrderService->execute($conn, 'UPDATE ot_head SET profit = ? WHERE id = ?', [
            $this->moneyFromBoundary($lineResult['totals']['profit'])->toString(),
            $orderId,
        ]);

        $this->customerSideEffects()->afterOrderSaved($conn, $orderId, $request, $channel, [
            'paid_amount' => $status['paid_amount'],
            'payment_status' => (string) $status['payment_status'],
            'in_transaction' => true,
            'config' => $context['config'] ?? null,
        ]);

        $mutationVersion = $this->mutationVersionService()->bumpAndGet($conn, $orderId);
        if (!array_key_exists('record_outbox', $context) || $context['record_outbox']) {
            try {
                $syncOutbox = new SyncOutboxEventService();
                $syncOutbox->recordOrderSnapshot($conn, $orderId, [
                    'event_type' => 'order.updated',
                    'source_system' => $orderType === 'delivery' ? 'pos_cashier_delivery' : 'pos_cashier',
                ]);
            } catch (Throwable $exception) {
                if (SideEffectPolicy::orderEventShouldRollback($exception)) {
                    throw $exception;
                }
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

        return $this->attachKitchenRevision($conn, $orderId, [
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
                'mutation_version' => $mutationVersion,
                'profit' => $this->moneyFromBoundary($lineResult['totals']['profit'])->toString(),
                'journal_head_id' => $salesJournal['journal_head_id'],
                'journal_id' => $salesJournal['journal_id'],
                'receipt_ids' => array_column($receipts, 'receipt_id'),
            ],
        ]);
    }

    private function assertCashierOrderEditable(mysqli $conn, int $orderId, ?array $order = null): void
    {
        $order = $order ?? $this->tableOrderService->queryOne(
            $conn,
            'SELECT id, pro_tybe, payment_status, order_status, paid_amount, isdeleted FROM ot_head WHERE id = ? LIMIT 1',
            [$orderId]
        );
        if (!$order || (int) ($order['pro_tybe'] ?? 0) !== 9 || (int) ($order['isdeleted'] ?? 0) === 1) {
            throw new InvalidArgumentException('ORDER_NOT_FOUND');
        }

        $paymentStatus = strtolower(trim((string) ($order['payment_status'] ?? 'unpaid')));
        $orderStatus = strtolower(trim((string) ($order['order_status'] ?? 'active')));
        if (
            $paymentStatus !== 'unpaid'
            || $orderStatus !== 'active'
            || $this->moneyIsPositive($order['paid_amount'] ?? '0')
        ) {
            throw new RuntimeException('COMPLETED_ORDER_EDIT_REQUIRES_REFUND');
        }

        if ($this->tableExists($conn, 'credit_notes')) {
            $posted = $this->tableOrderService->queryOne(
                $conn,
                "SELECT id FROM credit_notes WHERE original_order_id = ? AND status = 'posted' LIMIT 1",
                [$orderId]
            );
            if ($posted) {
                throw new RuntimeException('COMPLETED_ORDER_EDIT_REQUIRES_REFUND');
            }
        }
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
        $items = $this->normalizeTakeawayItems($conn, $request);
        $this->assertItemsAvailable($conn, $items, $request, $context);
        $userId = $this->contextUserId($request, $context);
        $date = $this->requestDate($request, ['pro_date', 'order_date', 'date']);
        $accrualDate = $this->requestDate($request, ['accural_date', 'accrual_date'], $date);
        $headTotal = $this->moneyFromBoundary($request['headtotal'] ?? $request['total'] ?? '0')->toString();
        $headDiscount = $this->moneyFromBoundary($request['headdisc'] ?? $request['discount'] ?? '0')->toString();
        $this->requireOrderEscalationsIfNeeded($conn, null, $headDiscount, $request, $context);
        $headPlus = $this->moneyFromBoundary($request['headplus'] ?? $request['plus'] ?? '0')->toString();
        $headNet = array_key_exists('headnet', $request) || array_key_exists('net', $request)
            ? $this->moneyFromBoundary($request['headnet'] ?? $request['net'])->toString()
            : $this->moneyFromBoundary($headTotal)
                ->subtract($this->moneyFromBoundary($headDiscount))
                ->add($this->moneyFromBoundary($headPlus))
                ->toString();
        if ($this->moneyFromBoundary($headNet)->isNegative()) {
            throw new InvalidArgumentException('PAYMENT_AMOUNT_INVALID');
        }

        $paidCash = $this->moneyFromBoundary($request['paid_cash'] ?? $request['paid'] ?? '0')->toString();
        $paidBank = $this->moneyFromBoundary($request['paid_bank'] ?? '0')->toString();
        $paymentFundId = (int) ($request['payment_fund_id'] ?? $fundId);
        $paymentBankId = (int) ($request['payment_bank_id'] ?? 0);
        $payment = $this->calculateTakeawayPayment($headNet, $paidCash, $paidBank);
        if ($this->moneyIsPositive($payment['cash']) && $paymentFundId <= 0) {
            throw new InvalidArgumentException('PAYMENT_FUND_REQUIRED');
        }
        if ($this->moneyIsPositive($payment['bank']) && $paymentBankId <= 0) {
            throw new InvalidArgumentException('PAYMENT_BANK_REQUIRED');
        }

        $status = $this->paidStatusForNet($headNet, $payment['applied']);
        $proId = $this->nextInvoiceProId($conn, InventoryMovementService::TYPE_POS, 0, 0);
        $info = $this->tableOrderService->buildInfo('takeaway', '', (string) ($request['info'] ?? ''));
        $fatDiscPer = $this->percentageString($headDiscount, $headTotal);
        $fatPlusPer = $this->percentageString($headPlus, $headTotal);

        $this->tableOrderService->execute($conn, "
            INSERT INTO ot_head (
                pro_id, pro_tybe, is_stock, is_journal, journal_tybe, info, pro_date,
                accural_date, pro_pattren, pro_serial, price_list, store_id, emp_id,
                emp2_id, acc1, acc2, pro_value, fat_cost, cost_center, profit,
                fat_total, fat_disc, fat_disc_per, fat_plus, fat_plus_per,
                fat_tax, fat_tax_per, fat_net, user, jal_name, jal_notes, jal_amount,
                table_id, order_type, payment_status, invoice_status, order_status,
                paid_amount, remaining_amount, waiter_id, payment_date, completed_at, crtime
            ) VALUES (
                ?, 9, 1, 1, 9, ?, ?,
                ?, 1, ?, 1, ?, ?,
                ?, ?, ?, ?, 0, 1, 0,
                ?, ?, ?, ?, ?,
                0, 0, ?, ?, ?, ?, ?,
                NULL, 'takeaway', ?, ?, ?,
                ?, ?, ?, CASE WHEN ? = 'paid' THEN NOW() ELSE NULL END,
                CASE WHEN ? = 'completed' THEN NOW() ELSE NULL END,
                CURRENT_TIMESTAMP
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
            $this->moneyFromBoundary($request['jal_amount'] ?? '0')->toString(),
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
        $cashReceiptId = null;
        if ($this->moneyIsPositive($payment['cash'])) {
            $cashReceipt = $this->insertTakeawayReceipt($conn, $orderId, $proId, $info, $date, $empId, $paymentFundId, $customerId, $payment['cash'], 'كاش', $userId);
            $receipts[] = $cashReceipt;
            $cashReceiptId = (int) ($cashReceipt['receipt_id'] ?? 0) ?: null;
        }
        if ($this->moneyIsPositive($payment['bank'])) {
            $receipts[] = $this->insertTakeawayReceipt($conn, $orderId, $proId, $info, $date, $empId, $paymentBankId, $customerId, $payment['bank'], 'صرافة', $userId);
        }
        $this->recordOrderCashCollected($conn, $orderId, $payment['cash'], $request, $context, 'takeaway_cash_payment', $cashReceiptId);
        $this->recordOrderBankCollected($conn, $orderId, $payment['bank'], $request, $context);

        $lineResult = $this->inventoryMovementService->normalizeInvoiceLines($conn, InventoryMovementService::TYPE_POS, $items, [
            'store_id' => $storeId,
        ]);
        $recipeLines = [];
        $inventoryBridgeLines = [];
        foreach ($lineResult['lines'] as $index => $line) {
            $line['_source_item'] = $items[$index] ?? [];
            $line['note'] = $this->lineNoteFromItem($line['_source_item']);
            $recipeLine = $this->insertTakeawayDetailLine($conn, $orderId, $storeId, $line, $context, 'pos', 'takeaway');
            $recipeLines[] = $recipeLine;
            $inventoryBridgeLines[] = $this->inventoryBridgeLineFromLegacyLine($line, $recipeLine, $storeId);
        }
        if ($status['order_status'] === 'completed') {
            $this->recordInventoryInvoiceBridgeLines($conn, $orderId, $inventoryBridgeLines, 'pos', 'takeaway', $request, $context);
        } else {
            $this->reserveInventoryInvoiceBridgeLines($conn, $orderId, $inventoryBridgeLines, 'pos', 'takeaway', $request, $context);
        }
        $this->recordRecipeOrderLinesAdded($conn, $recipeLines);
        if ($status['order_status'] === 'completed') {
            $this->recordRecipeOrderPaid($conn, $orderId, $recipeLines, 'pos', 'takeaway', $request, $context);
        }
        $this->tableOrderService->execute($conn, "UPDATE ot_head SET profit = ? WHERE id = ?", [
            $this->moneyFromBoundary($lineResult['totals']['profit'])->toString(),
            $orderId,
        ]);

        $this->customerSideEffects()->afterOrderSaved($conn, $orderId, $request, 'takeaway', [
            'paid_amount' => $status['paid_amount'],
            'payment_status' => (string) $status['payment_status'],
            'in_transaction' => true,
            'config' => $context['config'] ?? null,
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
                if (SideEffectPolicy::orderEventShouldRollback($exception)) {
                    throw $exception;
                }
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

        return $this->attachKitchenRevision($conn, $orderId, [
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
                'profit' => $this->moneyFromBoundary($lineResult['totals']['profit'])->toString(),
                'journal_head_id' => $salesJournal['journal_head_id'],
                'journal_id' => $salesJournal['journal_id'],
                'receipt_ids' => array_column($receipts, 'receipt_id'),
                'outbox_id' => $outboxResult['outbox_id'] ?? null,
            ],
        ]);
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
            $upserted = $posCustomerService->upsertForDelivery(
                $conn,
                $deliveryPhone,
                $deliveryName,
                $deliveryAddress,
                $zoneId > 0 ? $zoneId : null,
                ['in_transaction' => true, 'config' => $context['config'] ?? null]
            );
            $request['pos_customer_id'] = (int) ($upserted['id'] ?? 0);
        }
        $deliveryCustomerId = (int) ($request['pos_customer_id'] ?? 0);

        $items = $this->normalizeTakeawayItems($conn, $request);
        $this->assertItemsAvailable($conn, $items, $request, $context);
        $userId = $this->contextUserId($request, $context);
        $date = $this->requestDate($request, ['pro_date', 'order_date', 'date']);
        $accrualDate = $this->requestDate($request, ['accural_date', 'accrual_date'], $date);
        $headTotal = $this->moneyFromBoundary($request['headtotal'] ?? $request['total'] ?? '0')->toString();
        $headDiscount = $this->moneyFromBoundary($request['headdisc'] ?? $request['discount'] ?? '0')->toString();
        $this->requireOrderEscalationsIfNeeded($conn, null, $headDiscount, $request, $context);
        $resolvedTotals = $this->resolveDeliveryPostedTotals($conn, $request);
        $deliveryFee = (string) $resolvedTotals['delivery_fee'];
        $deliveryZoneName = (string) $resolvedTotals['delivery_zone_name'];
        $headPlus = (string) $resolvedTotals['headplus'];
        $headTotal = (string) $resolvedTotals['headtotal'];
        $headNet = (string) $resolvedTotals['headnet'];
        if ($this->moneyFromBoundary($headNet)->isNegative()) {
            throw new InvalidArgumentException('PAYMENT_AMOUNT_INVALID');
        }

        $isSaveOnly = (($request['submit'] ?? '') === 'save');
        $paidCash = $isSaveOnly
            ? '0.00'
            : $this->moneyFromBoundary($request['paid_cash'] ?? $request['paid'] ?? '0')->toString();
        $paidBank = $isSaveOnly
            ? '0.00'
            : $this->moneyFromBoundary($request['paid_bank'] ?? '0')->toString();
        $paymentFundId = (int) ($request['payment_fund_id'] ?? $fundId);
        $paymentBankId = (int) ($request['payment_bank_id'] ?? 0);
        $payment = $this->calculateTakeawayPayment($headNet, $paidCash, $paidBank);
        if ($this->moneyIsPositive($payment['cash']) && $paymentFundId <= 0) {
            throw new InvalidArgumentException('PAYMENT_FUND_REQUIRED');
        }
        if ($this->moneyIsPositive($payment['bank']) && $paymentBankId <= 0) {
            throw new InvalidArgumentException('PAYMENT_BANK_REQUIRED');
        }

        $status = $this->paidStatusForNet($headNet, $payment['applied']);
        $proId = $this->nextInvoiceProId($conn, InventoryMovementService::TYPE_POS, 0, 0);
        $info = $this->tableOrderService->buildInfo('delivery', '', (string) ($request['info'] ?? ''));
        $fatDiscPer = $this->percentageString($headDiscount, $headTotal);
        $fatPlusPer = $this->percentageString($headPlus, $headTotal);

        $this->tableOrderService->execute($conn, "
            INSERT INTO ot_head (
                pro_id, pro_tybe, is_stock, is_journal, journal_tybe, info, pro_date,
                accural_date, pro_pattren, pro_serial, price_list, store_id, emp_id,
                emp2_id, acc1, acc2, pro_value, fat_cost, cost_center, profit,
                fat_total, fat_disc, fat_disc_per, fat_plus, fat_plus_per,
                fat_tax, fat_tax_per, fat_net, user, jal_name, jal_notes, jal_amount,
                table_id, order_type, payment_status, invoice_status, order_status,
                paid_amount, remaining_amount, waiter_id, payment_date, completed_at, crtime
            ) VALUES (
                ?, 9, 1, 1, 9, ?, ?,
                ?, 1, ?, 1, ?, ?,
                ?, ?, ?, ?, 0, 1, 0,
                ?, ?, ?, ?, ?,
                0, 0, ?, ?, ?, ?, ?,
                NULL, 'delivery', ?, ?, ?,
                ?, ?, ?, CASE WHEN ? = 'paid' THEN NOW() ELSE NULL END,
                CASE WHEN ? = 'completed' THEN NOW() ELSE NULL END,
                CURRENT_TIMESTAMP
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
            $this->moneyFromBoundary($request['jal_amount'] ?? '0')->toString(),
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
        if ($status['payment_status'] === 'paid' || $this->moneyIsPositive($payment['applied'])) {
            $salesJournal = $this->insertTakeawaySalesJournal($conn, $orderId, $proId, $headNet, $date, $customerId, $userId, (int) ($request['sales_account_id'] ?? 0));
            $cashReceiptId = null;
            if ($this->moneyIsPositive($payment['cash'])) {
                $cashReceipt = $this->insertTakeawayReceipt($conn, $orderId, $proId, $info, $date, $empId, $paymentFundId, $customerId, $payment['cash'], 'كاش', $userId);
                $receipts[] = $cashReceipt;
                $cashReceiptId = (int) ($cashReceipt['receipt_id'] ?? 0) ?: null;
            }
            if ($this->moneyIsPositive($payment['bank'])) {
                $receipts[] = $this->insertTakeawayReceipt($conn, $orderId, $proId, $info, $date, $empId, $paymentBankId, $customerId, $payment['bank'], 'صرافة', $userId);
            }
            $this->recordOrderCashCollected($conn, $orderId, $payment['cash'], $request, $context, 'delivery_cash_payment', $cashReceiptId);
            $this->recordOrderBankCollected($conn, $orderId, $payment['bank'], $request, $context);
        }

        $lineResult = $this->inventoryMovementService->normalizeInvoiceLines($conn, InventoryMovementService::TYPE_POS, $items, [
            'store_id' => $storeId,
        ]);
        $recipeLines = [];
        $inventoryBridgeLines = [];
        foreach ($lineResult['lines'] as $index => $line) {
            $line['_source_item'] = $items[$index] ?? [];
            $line['note'] = $this->lineNoteFromItem($line['_source_item']);
            $recipeLine = $this->insertTakeawayDetailLine($conn, $orderId, $storeId, $line, $context, 'pos', 'delivery');
            $recipeLines[] = $recipeLine;
            $inventoryBridgeLines[] = $this->inventoryBridgeLineFromLegacyLine($line, $recipeLine, $storeId);
        }
        if ($status['order_status'] === 'completed') {
            $this->recordInventoryInvoiceBridgeLines($conn, $orderId, $inventoryBridgeLines, 'pos', 'delivery', $request, $context);
        } else {
            $this->reserveInventoryInvoiceBridgeLines($conn, $orderId, $inventoryBridgeLines, 'pos', 'delivery', $request, $context);
        }
        $this->recordRecipeOrderLinesAdded($conn, $recipeLines);
        if ($status['order_status'] === 'completed') {
            $this->recordRecipeOrderPaid($conn, $orderId, $recipeLines, 'pos', 'delivery', $request, $context);
        }
        $this->tableOrderService->execute($conn, "UPDATE ot_head SET profit = ? WHERE id = ?", [
            $this->moneyFromBoundary($lineResult['totals']['profit'])->toString(),
            $orderId,
        ]);

        $this->customerSideEffects()->afterOrderSaved($conn, $orderId, $request, 'delivery', [
            'paid_amount' => $status['paid_amount'],
            'payment_status' => (string) $status['payment_status'],
            'in_transaction' => true,
            'config' => $context['config'] ?? null,
        ]);

        $collectionMode = strtolower(trim((string) ($request['collection_mode'] ?? 'prepaid')));
        if (!in_array($collectionMode, ['prepaid', 'cod'], true)) {
            $collectionMode = 'prepaid';
        }
        // The invoice balance is authoritative: any unpaid remainder travels as
        // COD, while a fully paid order cannot leave phantom cash with a worker.
        $collectionMode = $this->moneyIsPositive($status['remaining_amount']) ? 'cod' : 'prepaid';
        $courierSource = strtolower(trim((string) ($request['courier_source'] ?? 'in_house')));
        if (!in_array($courierSource, ['in_house', 'external'], true)) {
            $courierSource = 'in_house';
        }
        $fulfillmentService = new OrderFulfillmentService();
        $fulfillment = $fulfillmentService->upsertForOrder($conn, $orderId, [
            'order_channel' => 'cashier',
            'fulfillment_type' => 'delivery',
            'customer_name' => $deliveryName,
            'customer_phone' => $deliveryPhone,
            'customer_address' => $deliveryAddress,
            'pos_customer_id' => $deliveryCustomerId,
            'delivery_zone' => $deliveryZoneName,
            'delivery_fee' => $deliveryFee,
            'delivery_status' => 'pending',
            'metadata_json' => ['source' => 'pos_cashier_delivery'],
        ], ['require_table' => true]);
        $fulfillmentUpdate = $conn->prepare("UPDATE order_fulfillment SET delivery_zone_id = NULLIF(?, 0), courier_source = ?, collection_mode = ?, cod_amount = CASE WHEN ? = 'cod' THEN ? ELSE 0 END WHERE order_id = ?");
        $deliveryZoneId = (int) ($resolvedTotals['delivery_zone_id'] ?? 0);
        $codAmount = $collectionMode === 'cod' ? (string) $status['remaining_amount'] : '0.00';
        $fulfillmentUpdate->bind_param('issssi', $deliveryZoneId, $courierSource, $collectionMode, $collectionMode, $codAmount, $orderId);
        $fulfillmentUpdate->execute();
        $fulfillmentUpdate->close();
        $deliveryWorkerId = max(0, (int) ($request['delivery_worker_id'] ?? 0));
        if ($deliveryWorkerId > 0 && $courierSource === 'in_house') {
            (new DeliveryWorkerService())->assignOrder($conn, $orderId, $deliveryWorkerId, [
                'in_transaction' => true,
                'user_id' => $userId,
                'tenant' => (int) ($context['tenant'] ?? $_SESSION['pos_tenant'] ?? 0),
                'branch' => (int) ($context['branch'] ?? $_SESSION['pos_branch'] ?? 0),
                'config' => $context['config'] ?? null,
            ]);
        }

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
                if (SideEffectPolicy::orderEventShouldRollback($exception)) {
                    throw $exception;
                }
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

        return $this->attachKitchenRevision($conn, $orderId, [
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
                'profit' => $this->moneyFromBoundary($lineResult['totals']['profit'])->toString(),
                'pos_customer_id' => $deliveryCustomerId,
                'delivery_worker_id' => $deliveryWorkerId ?: null,
                'collection_mode' => $collectionMode,
                'fulfillment' => $fulfillmentService->fulfillmentForOrder($conn, $orderId),
                'journal_head_id' => $salesJournal['journal_head_id'] ?? null,
                'journal_id' => $salesJournal['journal_id'] ?? null,
                'receipt_ids' => array_column($receipts, 'receipt_id'),
                'outbox_id' => $outboxResult['outbox_id'] ?? null,
            ],
        ]);
    }

    private function normalizeTakeawayItems(mysqli $conn, array $request): array
    {
        if (isset($request['items']) && is_array($request['items']) && $request['items']) {
            return $this->resolveAuthoritativeItemFactors($conn, $request['items']);
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
                'qty' => $this->quantityStringFromBoundary($request['itmqty'][$index] ?? '1'),
                'price' => $this->unitPriceStringFromBoundary($request['itmprice'][$index] ?? '0'),
                'discount' => $this->unitPriceStringFromBoundary($request['itmdisc'][$index] ?? '0'),
                'u_val' => $this->quantityStringFromBoundary($request['u_val'][$index] ?? '1'),
                'unit_id' => (int) ($request['unit_id'][$index] ?? $request['unitid'][$index] ?? 0),
                'note' => (string) ($request['itmnote'][$index] ?? ''),
                'modifiers' => $this->decodeLineModifiers($request['itmmodifiers'][$index] ?? []),
                'preparation_values' => $this->decodeLinePreparationValues($request['itmpreparation'][$index] ?? []),
                'base_price' => $this->unitPriceStringFromBoundary(
                    $request['itmbaseprice'][$index] ?? $request['itmprice'][$index] ?? '0'
                ),
                'manager_approval_id' => (int) ($request['itmmanagerapproval'][$index] ?? $request['manager_approval_id'][$index] ?? 0),
            ];
        }

        if (!$items) {
            throw new InvalidArgumentException('الرجاء إضافة أصناف للطلب');
        }

        return $this->resolveAuthoritativeItemFactors($conn, $items);
    }

    private function resolveAuthoritativeItemFactors(mysqli $conn, array $items): array
    {
        foreach ($items as &$item) {
            $itemId = (int) ($item['item_id'] ?? $item['id'] ?? 0);
            if ($itemId < 1) {
                continue;
            }
            $unitId = (int) ($item['unit_id'] ?? $item['unitid'] ?? 0);
            $resolved = ItemUnitResolver::resolvePosStockFactor(
                $conn,
                $itemId,
                $unitId > 0 ? $unitId : null,
                $item['u_val'] ?? $item['unit_value'] ?? null
            );
            $item['u_val'] = $resolved['factor_decimal'];
            $item['u_val_decimal'] = $resolved['factor_decimal'];
            $submittedPreparation = $item['preparation_values']
                ?? $item['preparations']
                ?? $item['preparation']
                ?? $this->preparationValuesFromOptions($item['options'] ?? []);
            $item['preparation_values'] = $this->preparationSelectionService->validateForItem(
                $conn,
                $itemId,
                $submittedPreparation,
                ['config' => function_exists('posmain_app_config') ? posmain_app_config() : []]
            );
        }
        unset($item);

        return $items;
    }

    private function sumPostedItemSubtotal(array $request): Money
    {
        if (!isset($request['itmname']) || !is_array($request['itmname'])) {
            return Money::zero();
        }

        $total = '0.000000';
        foreach ($request['itmname'] as $index => $itemId) {
            if ((int) $itemId <= 0) {
                continue;
            }
            $qty = $this->quantityStringFromBoundary($request['itmqty'][$index] ?? '1');
            $price = $this->unitPriceStringFromBoundary($request['itmprice'][$index] ?? '0');
            $discount = $this->unitPriceStringFromBoundary($request['itmdisc'][$index] ?? '0');
            if (FinancialDecimal::compare($discount, $price, UnitPrice::SCALE) > 0) {
                throw new InvalidArgumentException('LINE_DISCOUNT_EXCEEDS_PRICE');
            }
            $unitNet = FinancialDecimal::subtract($price, $discount, UnitPrice::SCALE);
            $total = FinancialDecimal::add(
                $total,
                FinancialDecimal::multiply($qty, $unitNet, 6),
                6
            );
        }

        return Money::from(RoundingPolicy::halfUp($total));
    }

    private function calculateTakeawayPayment($headNet, $paidCash, $paidBank): array
    {
        $head = $this->moneyFromBoundary($headNet);
        $cashTendered = $this->moneyFromBoundary($paidCash);
        $bankTendered = $this->moneyFromBoundary($paidBank);
        $totalPaid = $cashTendered->add($bankTendered);
        $change = $totalPaid->compare($head) > 0 ? $totalPaid->subtract($head) : Money::zero();
        $cash = $cashTendered;
        $bank = $bankTendered;

        if ($change->compare($cashTendered) <= 0) {
            $cash = $cashTendered->subtract($change);
        } else {
            $remainingChange = $change->subtract($cashTendered);
            $cash = Money::zero();
            $bank = $remainingChange->compare($bankTendered) >= 0
                ? Money::zero()
                : $bankTendered->subtract($remainingChange);
        }

        $collected = $cash->add($bank);
        $applied = $collected->compare($head) > 0 ? $head : $collected;

        return [
            'cash' => $cash->toString(),
            'bank' => $bank->toString(),
            'applied' => $applied->toString(),
            'change' => $change->toString(),
        ];
    }

    private function paidStatusForNet($headNet, $paidAmount): array
    {
        $head = $this->moneyFromBoundary($headNet);
        $paid = $this->moneyFromBoundary($paidAmount);
        if ($paid->compare($head) > 0) {
            $paid = $head;
        }
        if (!$paid->isPositive()) {
            $paid = Money::zero();
        }
        $remaining = $head->subtract($paid);
        if (!$paid->isPositive()) {
            $paymentStatus = 'unpaid';
            $invoiceStatus = 'draft';
            $orderStatus = 'active';
        } elseif ($remaining->compare(Money::zero()) === 0) {
            $paymentStatus = 'paid';
            $invoiceStatus = 'completed';
            $orderStatus = 'completed';
        } else {
            $paymentStatus = 'partial';
            $invoiceStatus = 'draft';
            $orderStatus = 'active';
        }

        return [
            'paid_amount' => $paid->toString(),
            'remaining_amount' => $remaining->toString(),
            'payment_status' => $paymentStatus,
            'invoice_status' => $invoiceStatus,
            'order_status' => $orderStatus,
        ];
    }

    private function insertTakeawaySalesJournal(mysqli $conn, int $orderId, int $proId, $amount, string $date, int $customerId, int $userId, int $salesAccountId = 0): array
    {
        $scope = $this->orderAccountingScope($conn, $orderId);
        $salesAccountId = posmain_ensure_sales_account($conn, $salesAccountId > 0 ? $salesAccountId : 91);
        if ($salesAccountId <= 0) {
            throw new InvalidArgumentException('لا يوجد حساب مبيعات صالح في دليل الحسابات');
        }

        $postedAmount = $this->moneyFromBoundary($amount)->toString();
        $posted = (new FinancialInvoicePostingService())->postInvoiceFinalization(
            $conn,
            $orderId,
            ['net' => $postedAmount, 'tax' => '0.00'],
            $customerId,
            $salesAccountId,
            $userId,
            [
                'jdate' => $date,
                'idempotency_key' => 'takeaway-invoice:' . $orderId,
                'tenant' => $scope['tenant'],
                'branch' => $scope['branch'],
            ]
        );

        return [
            'journal_id' => (int) $posted['journal_id'],
            'journal_head_id' => (int) $posted['journal_head_id'],
            'pro_id' => $proId,
        ];
    }

    private function insertTakeawayReceipt(mysqli $conn, int $orderId, int $proId, string $info, string $date, int $empId, int $fundAccountId, int $customerId, $amount, string $methodLabel, int $userId): array
    {
        $scope = $this->orderAccountingScope($conn, $orderId);
        $postedAmount = $this->moneyFromBoundary($amount)->toString();
        $receiptProId = $this->nextInvoiceProId($conn, 1, $scope['tenant'], $scope['branch']);
        $receiptInfo = $info . ' - دفع ' . $methodLabel;
        $this->tableOrderService->execute($conn, "
            INSERT INTO ot_head (
                pro_id, pro_tybe, is_journal, journal_tybe, info, pro_date,
                emp_id, acc1, acc2, pro_value, cost_center, profit, user, op2
            ) VALUES (?, 1, 1, 1, ?, ?, ?, ?, ?, ?, 1, 0, ?, ?)
        ", [$receiptProId, $receiptInfo, $date, $empId, $fundAccountId, $customerId, $postedAmount, $userId, $orderId]);
        $receiptId = (int) $conn->insert_id;
        $this->tableOrderService->assignUuidIfPresent($conn, 'ot_head', $receiptId);
        if ($this->columnExists($conn, 'ot_head', 'tenant') && $this->columnExists($conn, 'ot_head', 'branch')) {
            $scopeUpdate = $conn->prepare('UPDATE ot_head SET tenant = ?, branch = ? WHERE id = ?');
            $scopeUpdate->bind_param('iii', $scope['tenant'], $scope['branch'], $receiptId);
            $scopeUpdate->execute();
            $scopeUpdate->close();
        }

        $journalId = $this->tableOrderService->nextJournalId($conn, $scope['tenant'], $scope['branch']);
        $details = 'سند قبض ' . $methodLabel . ' _ ' . $proId;
        $journalHeadId = JournalPostingService::postBalancedHead(
            $conn,
            (string) $journalId,
            $postedAmount,
            $date,
            $details,
            $userId,
            [
                ['account_id' => $fundAccountId, 'debit' => $postedAmount, 'credit' => '0.00', 'tybe' => 0, 'op2' => $orderId],
                ['account_id' => $customerId, 'debit' => '0.00', 'credit' => $postedAmount, 'tybe' => 1, 'op2' => $orderId],
            ],
            [
                'op_id' => $receiptId,
                'op2' => $orderId,
                'source_type' => 'payment',
                'source_id' => $receiptId,
                'posting_kind' => 'payment_receipt',
                'idempotency_key' => 'takeaway-receipt:' . $orderId . ':' . $receiptId,
                'tenant' => $scope['tenant'],
                'branch' => $scope['branch'],
            ]
        );

        return [
            'receipt_id' => $receiptId,
            'receipt_pro_id' => $receiptProId,
            'journal_id' => $journalId,
            'journal_head_id' => $journalHeadId,
        ];
    }

    private function insertCashRefundVoucher(mysqli $conn, int $orderId, int $proId, string $info, string $date, int $empId, int $fundAccountId, int $customerId, $amount, string $methodLabel, int $userId): array
    {
        throw new RuntimeException('LEGACY_CASH_REFUND_FORBIDDEN_USE_CREDIT_NOTE');
    }

    private function insertTakeawayDetailLine(
        mysqli $conn,
        int $orderId,
        int $storeId,
        array $line,
        array $context = [],
        string $channel = 'pos',
        string $orderType = 'takeaway'
    ): array
    {
        $qtyIn = $this->quantityStringFromBoundary($line['qty_in'] ?? '0');
        $qtyOut = $this->quantityStringFromBoundary($line['qty_out'] ?? '0');
        $price = $this->unitPriceStringFromBoundary($line['price'] ?? '0');
        $discount = $this->unitPriceStringFromBoundary($line['discount'] ?? '0');
        $detValue = $this->moneyFromBoundary($line['det_value'] ?? '0')->toString();
        $costPrice = $this->unitPriceStringFromBoundary($line['cost_price'] ?? '0');
        $profit = $this->moneyFromBoundary($line['profit'] ?? '0')->toString();
        $uVal = $this->quantityStringFromBoundary($line['u_val'] ?? '1');
        $postedQty = DecimalQuantity::from((string) ($line['posted_qty'] ?? FinancialDecimal::subtract($qtyOut, $qtyIn, DecimalQuantity::SCALE)))->toString();
        if (FinancialDecimal::compare($postedQty, '0', DecimalQuantity::SCALE) < 0) {
            $postedQty = FinancialDecimal::subtract($qtyIn, $qtyOut, DecimalQuantity::SCALE);
        }
        $postedNet = Money::from((string) ($line['net'] ?? $line['posted_net'] ?? $detValue))->toString();
        $postedGross = Money::from((string) ($line['gross'] ?? $line['posted_gross'] ?? $detValue))->toString();
        $postedTax = Money::from((string) ($line['tax_amount'] ?? $line['posted_tax'] ?? '0'))->toString();
        $postedTaxable = Money::from((string) ($line['taxable_amount'] ?? $line['posted_taxable'] ?? $postedNet))->toString();
        $postedLineDiscount = Money::from((string) ($line['line_discount'] ?? $line['posted_line_discount'] ?? '0'))->toString();
        $postedOrderDiscount = Money::from((string) ($line['allocated_order_discount'] ?? $line['posted_order_discount'] ?? '0'))->toString();
        $taxRate = (string) ($line['tax_rate'] ?? $line['tax_rate_snapshot'] ?? '0.000000');
        $hasSnapshots = $this->fatDetailsHasPostedSnapshots($conn);

        if ($hasSnapshots) {
            $this->tableOrderService->execute($conn, "
                INSERT INTO fat_details (
                    pro_tybe, pro_id, item_id, u_val, qty_in, qty_out, price,
                    discount, det_value, fatid, fat_tybe, det_store, cost_price, profit,
                    posted_qty, posted_unit_price, posted_line_discount, posted_order_discount,
                    posted_taxable, posted_tax, posted_gross, posted_net,
                    posted_unit_cost, posted_total_cost, tax_rate_snapshot
                ) VALUES (9, ?, ?, ?, ?, ?, ?, ?, ?, ?, 9, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", [
                $orderId,
                (int) $line['item_id'],
                $uVal,
                $qtyIn,
                $qtyOut,
                $price,
                $discount,
                $detValue,
                $orderId,
                $storeId,
                $costPrice,
                $profit,
                $postedQty,
                $price,
                $postedLineDiscount,
                $postedOrderDiscount,
                $postedTaxable,
                $postedTax,
                $postedGross,
                $postedNet,
                $costPrice,
                Money::from(RoundingPolicy::halfUp(
                    FinancialDecimal::multiply($postedQty, $costPrice, 6)
                ))->toString(),
                $taxRate,
            ]);
        } else {
            $this->tableOrderService->execute($conn, "
                INSERT INTO fat_details (
                    pro_tybe, pro_id, item_id, u_val, qty_in, qty_out, price,
                    discount, det_value, fatid, fat_tybe, det_store, cost_price, profit
                ) VALUES (9, ?, ?, ?, ?, ?, ?, ?, ?, ?, 9, ?, ?, ?)
            ", [
                $orderId,
                (int) $line['item_id'],
                $uVal,
                $qtyIn,
                $qtyOut,
                $price,
                $discount,
                $detValue,
                $orderId,
                $storeId,
                $costPrice,
                $profit,
            ]);
        }
        $detailId = (int) $conn->insert_id;
        $detailUuid = $this->tableOrderService->assignUuidIfPresent($conn, 'fat_details', $detailId);
        $sourceItem = is_array($line['_source_item'] ?? null) ? $line['_source_item'] : $line;
        $this->persistLineCustomizationsIfAvailable(
            $conn,
            $orderId,
            $detailId,
            (int) $line['item_id'],
            $sourceItem,
            ltrim(
                FinancialDecimal::subtract(
                    $this->quantityStringFromBoundary($line['qty_out'] ?? '0'),
                    $this->quantityStringFromBoundary($line['qty_in'] ?? '0'),
                    DecimalQuantity::SCALE
                ),
                '-'
            ),
            $context
        );
        $this->preparationSelectionService->persistLineValues(
            $conn,
            $orderId,
            $detailId,
            (int) $line['item_id'],
            is_array($sourceItem['preparation_values'] ?? null) ? $sourceItem['preparation_values'] : []
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
            $channel,
            $orderType,
            $sourceItem,
            [],
            $context
        );
    }

    private function fatDetailsHasPostedSnapshots(mysqli $conn): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $result = $conn->query("SHOW COLUMNS FROM fat_details LIKE 'posted_net'");
        $cache = $result !== false && $result->num_rows > 0;

        return $cache;
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
        if ($orderId > 0) {
            $this->mutationVersionService()->lockAndAssert(
                $conn,
                $orderId,
                $this->expectedMutationVersion($request),
                true
            );
        }
        $orderDate = trim((string) ($request['order_date'] ?? date('Y-m-d')));
        $storeId = (int) ($request['store_id'] ?? 0);
        if ($storeId < 1) {
            throw new RuntimeException('بيانات المخزن أو الموظف أو الصندوق ناقصة');
        }
        $empId = $this->requiredPositiveInt($request, 'emp_id', 'بيانات المخزن أو الموظف أو الصندوق ناقصة');
        $fundId = $this->requiredPositiveInt($request, 'fund_id', 'بيانات المخزن أو الموظف أو الصندوق ناقصة');
        $items = $this->requiredItems($request);
        $items = $this->resolveAuthoritativeItemFactors($conn, $items);
        $this->assertItemsAvailable($conn, $items, $request, $context);
        $total = $this->moneyFromBoundary($request['total'] ?? '0')->toString();
        $discount = $this->moneyFromBoundary($request['discount'] ?? '0')->toString();
        $this->requireOrderEscalationsIfNeeded($conn, $orderId > 0 ? $orderId : null, $discount, $request, $context);
        if ($orderId > 0) {
            $this->requireItemVoidApprovalIfNeeded($conn, $orderId, $items, $request, $context);
        }
        $net = array_key_exists('net', $request)
            ? $this->moneyFromBoundary($request['net'])->toString()
            : $this->moneyFromBoundary($total)->subtract($this->moneyFromBoundary($discount))->toString();
        if (ItemUnitConversionFeatureFlags::strictPosFactorResolution()) {
            $serverNet = PaymentReconciliationService::recomputeTableNetFromLines($items, (string) $discount);
            if ($this->moneyFromBoundary($serverNet)->compare($this->moneyFromBoundary($net)) !== 0) {
                throw new InvalidArgumentException('ORDER_TOTAL_MISMATCH');
            }
            $net = $serverNet;
        }
        $userId = $this->contextUserId($request, $context);
        $isUpdate = $orderId > 0;

        $table = $this->tableOrderService->requireTable($conn, $tableId);
        $existingPaid = '0.00';
        if ($orderId > 0) {
            $activeOrder = $this->tableOrderService->findActiveOrderByTableAndOrderId($conn, $tableId, $orderId, true);
            if (!$activeOrder) {
                throw new RuntimeException('الطلب المحدد لا يخص هذه الطاولة أو لم يعد نشطاً');
            }
            $existingPaid = $this->moneyFromBoundary($activeOrder['paid_amount'] ?? '0')->toString();
            if ($this->moneyFromBoundary($existingPaid)->compare($this->moneyFromBoundary($net)) > 0) {
                throw new RuntimeException('ORDER_TOTAL_BELOW_PAID_AMOUNT_USE_REFUND');
            }
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
            $this->releaseInventoryInvoiceBridgeReservations(
                $conn,
                $orderId,
                $oldInventoryBridgeLines,
                'order_updated',
                'table',
                'dine_in',
                $request,
                $context
            );
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
        $this->registerExternalOrderLineMappings($conn, $recipeLines);
        $this->recordRecipeOrderLinesAdded($conn, $recipeLines);
        $totals = $this->tableOrderService->recalculateOrderTotals($conn, $orderId);
        $status = $this->applyPaidState($conn, $orderId, $tableId, $existingPaid, (string) $totals['net']);
        $inventoryBridgeLines = $this->loadInventoryInvoiceBridgeLines($conn, $orderId);
        if ($status['order_status'] === 'completed') {
            $this->recordInventoryInvoiceBridgeLines($conn, $orderId, $inventoryBridgeLines, 'table', 'dine_in', $request, $context);
            $this->recordRecipeOrderPaid($conn, $orderId, $recipeLines, 'table', 'dine_in', $request, $context);
        } else {
            $this->reserveInventoryInvoiceBridgeLines($conn, $orderId, $inventoryBridgeLines, 'table', 'dine_in', $request, $context);
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
            'net' => $this->moneyFromBoundary($totals['net'])->toString(),
        ]);

        $this->customerSideEffects()->afterOrderSaved($conn, $orderId, $request, 'table', [
            'paid_amount' => $status['paid_amount'],
            'payment_status' => (string) $status['payment_status'],
            'in_transaction' => true,
            'config' => $context['config'] ?? null,
        ]);

        $mutationVersion = $isUpdate
            ? $this->mutationVersionService()->bumpAndGet($conn, $orderId)
            : $this->mutationVersionService()->current($conn, $orderId);

        return $this->attachKitchenRevision($conn, $orderId, [
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
                'mutation_version' => $mutationVersion,
                'total' => (string) $totals['total'],
                'net' => (string) $totals['net'],
            ],
        ]);
    }

    private function splitTablePaymentInsideTransaction(mysqli $conn, array $request, array $context): array
    {
        $originalOrderId = (int) ($request['order_id'] ?? $request['original_order_id'] ?? 0);
        $tableId = (int) ($request['table_id'] ?? 0);
        $splitRequests = $this->normalizeSplitRequests($request['items'] ?? []);
        $selectedItems = array_keys($splitRequests);
        $paidAmount = Money::fromLegacy($request['paid_amount'] ?? $request['paid'] ?? '0');
        $paymentMethod = trim((string) ($request['payment_method'] ?? 'cash'));
        $userId = $this->contextUserId($request, $context);

        if ($originalOrderId <= 0 || $tableId <= 0 || !$selectedItems || !$paidAmount->isPositive()) {
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
        $childTotal = Money::zero();
        foreach ($splitLines as $line) {
            $childTotal = $childTotal->add(Money::fromLegacy($line['value']));
        }
        if (!$childTotal->isPositive()) {
            throw new RuntimeException('قيمة الأصناف المختارة غير صحيحة');
        }
        if ($paidAmount->compare($childTotal) < 0) {
            throw new RuntimeException('المبلغ المدفوع أقل من قيمة الأصناف المختارة');
        }

        $tender = (new PaymentMethodService())->resolveTender(
            $conn,
            $paymentMethod,
            $request['reference_no'] ?? $request['notes'] ?? null
        );
        $paymentMethod = (string) $tender['code'];

        $drawerContext = array_merge($request, $context, ['drawer_reason' => 'split_payment']);
        $drawerSession = $this->paymentService->preflightCashDrawerForPayment($conn, $paymentMethod, $childTotal->toString(), $userId, $drawerContext);
        $originalInventoryLines = $this->loadInventoryInvoiceBridgeLines($conn, $originalOrderId);
        $legacyDirectStockAlreadyConsumed = $this->hasInventorySaleMovementsForOrder($conn, $originalOrderId);
        if (!$legacyDirectStockAlreadyConsumed) {
            $this->releaseInventoryInvoiceBridgeReservations(
                $conn,
                $originalOrderId,
                $originalInventoryLines,
                'split_payment',
                'table',
                'dine_in',
                $request,
                $context
            );
        }
        $newHeadId = $this->insertSplitChildOrder($conn, $originalOrder, $tableId, $originalOrderId, $childTotal->toString(), $paymentMethod, $userId);
        foreach ($splitLines as $line) {
            $this->moveOrCopySplitLine($conn, $newHeadId, $line);
        }

        $recipeSplitAdjustments = $this->recipeSplitOriginalAdjustments($conn, $originalOrderId, $splitLines, 'table', 'dine_in', $request, $context);
        $remainingTotals = $this->tableOrderService->recalculateOrderTotals($conn, $originalOrderId);
        $activeTableOrderId = $this->refreshOriginalAfterSplit($conn, $originalOrder, $originalOrderId, $tableId, (string) $remainingTotals['net']);
        $paymentId = $this->insertSplitPaymentRecordIfAvailable($conn, $newHeadId, $childTotal->toString(), $paymentMethod, $userId);
        $this->paymentService->recordCashDrawerMovementForPayment($conn, $paymentMethod, $childTotal->toString(), $newHeadId, $userId, $drawerContext, $drawerSession, $paymentId);
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
        if (!$legacyDirectStockAlreadyConsumed) {
            $childInventoryLines = $this->loadInventoryInvoiceBridgeLines($conn, $newHeadId);
            $this->recordInventoryInvoiceBridgeLines(
                $conn,
                $newHeadId,
                $childInventoryLines,
                'table',
                'dine_in',
                $request,
                $context
            );
            if ($activeTableOrderId !== null) {
                $this->reserveInventoryInvoiceBridgeLines(
                    $conn,
                    $originalOrderId,
                    $this->loadInventoryInvoiceBridgeLines($conn, $originalOrderId),
                    'table',
                    'dine_in',
                    $request,
                    $context
                );
            }
        }
        $this->recordOrderEvent($conn, $originalOrderId, 'order.updated', $context['event_source'] ?? 'pos_split_payment', $context, [
            'table_id' => $tableId,
            'split_child_order_id' => $newHeadId,
            'remaining_total' => Money::fromLegacy($remainingTotals['net'])->toString(),
            'active_order_id' => $activeTableOrderId,
        ]);
        $this->recordOrderEvent($conn, $newHeadId, 'order.split_paid', $context['event_source'] ?? 'pos_split_payment', $context, [
            'table_id' => $tableId,
            'original_order_id' => $originalOrderId,
            'paid_amount' => $childTotal->toString(),
            'payment_method' => $paymentMethod,
        ]);

        $crmRequest = $request;
        $parentFulfillment = (new OrderFulfillmentService())->fulfillmentForOrder($conn, $originalOrderId);
        if (!empty($parentFulfillment['pos_customer_id'])) {
            $crmRequest['pos_customer_id'] = (int) $parentFulfillment['pos_customer_id'];
        }
        $this->customerSideEffects()->afterOrderSaved($conn, $newHeadId, $crmRequest, 'table', [
            'paid_amount' => $childTotal->toString(),
            'payment_status' => 'paid',
            'in_transaction' => true,
            'config' => $context['config'] ?? null,
        ]);

        $originalMutationVersion = $this->mutationVersionService()->bumpAndGet($conn, $originalOrderId);
        $childMutationVersion = $this->mutationVersionService()->current($conn, $newHeadId);

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
                'remaining_total' => Money::fromLegacy($remainingTotals['net'])->toString(),
                'active_order_id' => $activeTableOrderId,
                'paid_amount' => $childTotal->toString(),
                'original_mutation_version' => $originalMutationVersion,
                'mutation_version' => $childMutationVersion,
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
                $qty = isset($item['qty'])
                    ? DecimalQuantity::fromLegacy($item['qty'])->toString()
                    : (isset($item['quantity']) ? DecimalQuantity::fromLegacy($item['quantity'])->toString() : null);
            } else {
                $detailId = (int) $item;
                $qty = null;
            }

            if ($detailId > 0) {
                if (!isset($splitRequests[$detailId])) {
                    $splitRequests[$detailId] = ['qty' => null];
                }
                if ($qty !== null) {
                    $splitRequests[$detailId]['qty'] = isset($splitRequests[$detailId]['qty']) && $splitRequests[$detailId]['qty'] !== null
                        ? FinancialDecimal::add($splitRequests[$detailId]['qty'], $qty, DecimalQuantity::SCALE)
                        : $qty;
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

            $availableQty = FinancialDecimal::subtract(
                DecimalQuantity::from((string) ($detail['qty_out'] ?? '0'))->toString(),
                DecimalQuantity::from((string) ($detail['qty_in'] ?? '0'))->toString(),
                DecimalQuantity::SCALE
            );
            $requestedQty = $splitRequests[$detailId]['qty'];
            if ($requestedQty === null) {
                $requestedQty = $availableQty;
            }
            if (
                FinancialDecimal::compare($availableQty, '0', DecimalQuantity::SCALE) <= 0
                || FinancialDecimal::compare($requestedQty, '0', DecimalQuantity::SCALE) <= 0
                || FinancialDecimal::compare($requestedQty, $availableQty, DecimalQuantity::SCALE) > 0
            ) {
                throw new RuntimeException('كمية الصنف المختارة غير صحيحة');
            }

            $ratio = bcdiv($requestedQty, $availableQty, DecimalQuantity::SCALE);
            $value = RoundingPolicy::halfUp(bcmul((string) ($detail['det_value'] ?? '0'), $ratio, 8));
            $profit = RoundingPolicy::halfUp(bcmul((string) ($detail['profit'] ?? '0'), $ratio, 8));
            $splitLines[] = [
                'detail' => $detail,
                'qty' => $requestedQty,
                'value' => $value,
                'profit' => $profit,
                'is_full' => FinancialDecimal::compare($requestedQty, $availableQty, DecimalQuantity::SCALE) === 0,
            ];
        }

        return $splitLines;
    }

    private function insertSplitChildOrder(mysqli $conn, array $originalOrder, int $tableId, int $originalOrderId, string $childTotal, string $paymentMethod, int $userId): int
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

    private function refreshOriginalAfterSplit(mysqli $conn, array $originalOrder, int $originalOrderId, int $tableId, string $remainingNet): ?int
    {
        $remainingLines = $this->tableOrderService->queryOne($conn, "
            SELECT COUNT(*) AS c
            FROM fat_details
            WHERE fatid = ?
              AND isdeleted = 0
              AND qty_out > qty_in
        ", [$originalOrderId]);

        $remainingNet = Money::fromLegacy($remainingNet);
        if ((int) ($remainingLines['c'] ?? 0) > 0 && $remainingNet->isPositive()) {
            $existingPaid = Money::from((string) ($originalOrder['paid_amount'] ?? '0'));
            $originalPaid = $existingPaid->compare($remainingNet) > 0 ? $remainingNet : $existingPaid;
            $originalRemaining = $remainingNet->subtract($originalPaid);
            if (!$originalPaid->isPositive()) {
                $paymentStatus = 'unpaid';
                $invoiceStatus = 'draft';
                $orderStatus = 'active';
            } elseif (!$originalRemaining->isPositive()) {
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
            ", [$paymentStatus, $invoiceStatus, $orderStatus, $originalPaid->toString(), $originalRemaining->toString(), $originalOrderId, $tableId]);

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

    private function insertOrderPaymentRecordIfAvailable(mysqli $conn, int $orderId, $amount, string $paymentMethod, int $userId): ?int
    {
        $amount = Money::fromLegacy($amount, true)->toString();
        if (Money::from($amount, true)->compare(Money::zero()) === 0) {
            return null;
        }

        $tableCheck = $conn->query("SHOW TABLES LIKE 'order_payments'");
        if ($tableCheck && $tableCheck->num_rows > 0) {
            $this->tableOrderService->execute($conn, "
                INSERT INTO order_payments (order_id, amount, payment_method, created_by, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ", [$orderId, $amount, $paymentMethod, $userId]);
            $paymentId = (int) $conn->insert_id;
            $this->tableOrderService->assignUuidIfPresent($conn, 'order_payments', $paymentId);

            return $paymentId;
        }

        return null;
    }

    private function insertSplitPaymentRecordIfAvailable(mysqli $conn, int $newHeadId, $childTotal, string $paymentMethod, int $userId): ?int
    {
        return $this->insertOrderPaymentRecordIfAvailable($conn, $newHeadId, $childTotal, $paymentMethod, $userId);
    }

    private function recordOrderCashCollected(mysqli $conn, int $orderId, $cashAmount, array $request, array $context, string $reason, ?int $refOtHeadId = null): void
    {
        $cashAmount = Money::fromLegacy($cashAmount);
        if (!$cashAmount->isPositive()) {
            return;
        }

        $userId = $this->contextUserId($request, $context);
        $drawerContext = array_merge($request, $context, ['drawer_reason' => $reason]);
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
        $this->paymentService->recordCashDrawerMovementForPayment(
            $conn,
            'cash',
            $cashAmount->toString(),
            $orderId,
            $userId,
            $drawerContext,
            null,
            $paymentId,
            $refOtHeadId
        );
    }

    private function recordOrderBankCollected(mysqli $conn, int $orderId, $bankAmount, array $request, array $context): void
    {
        $bankAmount = $this->moneyFromBoundary($bankAmount);
        if (!$bankAmount->isPositive()) {
            return;
        }

        $userId = $this->contextUserId($request, $context);
        $this->insertOrderPaymentRecordIfAvailable($conn, $orderId, $bankAmount->toString(), 'bank', $userId);
    }

    private function recordOrderCashRefunded(mysqli $conn, int $orderId, $cashAmount, array $request, array $context, string $reason, ?int $refOtHeadId = null): void
    {
        $cashAmount = Money::fromLegacy($cashAmount);
        if (!$cashAmount->isPositive()) {
            return;
        }

        $userId = $this->contextUserId($request, $context);
        $drawerContext = array_merge($request, $context, ['drawer_reason' => $reason]);
        $paymentId = $this->insertOrderPaymentRecordIfAvailable($conn, $orderId, '-' . $cashAmount->toString(), 'cash', $userId);
        $this->paymentService->recordCashRefundMovementForPayment(
            $conn,
            $cashAmount->toString(),
            $orderId,
            $userId,
            $drawerContext,
            null,
            $paymentId,
            $refOtHeadId
        );
    }

    private function recordOrderCashPaymentDelta(mysqli $conn, int $orderId, $targetCashAmount, array $request, array $context, string $reason): void
    {
        $netRecorded = Money::from($this->drawerSessionService->netCashRecordedForOrder($conn, $orderId), true);
        $target = Money::fromLegacy($targetCashAmount);
        $delta = $target->subtract($netRecorded);
        if ($delta->isPositive()) {
            $this->recordOrderCashCollected($conn, $orderId, $delta->toString(), $request, $context, $reason);
        } elseif ($delta->isNegative()) {
            $refundAmount = Money::from(ltrim($delta->toString(), '-'))->toString();
            $this->recordOrderCashRefunded($conn, $orderId, $refundAmount, $request, $context, $reason . '_refund');
        }
    }

    private function recordOrderBankPaymentDelta(mysqli $conn, int $orderId, $targetBankAmount, array $request, array $context): void
    {
        $netRecorded = Money::fromLegacy($this->netPaymentRecordedForOrder($conn, $orderId, 'bank'));
        $target = Money::fromLegacy($targetBankAmount);
        $delta = $target->subtract($netRecorded);
        if ($delta->compare(Money::zero()) === 0) {
            return;
        }

        $userId = $this->contextUserId($request, $context);
        $this->insertOrderPaymentRecordIfAvailable($conn, $orderId, $delta->toString(), 'bank', $userId);
    }

    private function netPaymentRecordedForOrder(mysqli $conn, int $orderId, string $paymentMethod): string
    {
        if ($orderId < 1) {
            return '0.00';
        }

        $tableCheck = $conn->query("SHOW TABLES LIKE 'order_payments'");
        if (!$tableCheck instanceof mysqli_result || $tableCheck->num_rows < 1) {
            return '0.00';
        }

        $row = $this->tableOrderService->queryOne($conn, "
            SELECT COALESCE(SUM(amount), 0) AS total_amount
            FROM order_payments
            WHERE order_id = ?
              AND payment_method = ?
        ", [$orderId, $paymentMethod]);

        return $this->moneyFromBoundary($row['total_amount'] ?? '0', true)->toString();
    }

    private function resolveRefundableCashForOrder(mysqli $conn, int $orderId, $paidAmount): string
    {
        $netRecorded = Money::from($this->drawerSessionService->netCashRecordedForOrder($conn, $orderId), true);
        $paid = $this->moneyFromBoundary($paidAmount);
        if ($netRecorded->isPositive()) {
            return $netRecorded->compare($paid) > 0 ? $paid->toString() : $netRecorded->toString();
        }

        $fallback = $this->moneyFromBoundary(
            $this->sumCashReceiptVouchersForOrder($conn, $orderId, $this->resolveFundAccountIds($conn))
        );
        if (!$fallback->isPositive()) {
            return '0.00';
        }

        return $fallback->compare($paid) > 0 ? $paid->toString() : $fallback->toString();
    }

    private function resolveFundAccountIds(mysqli $conn): array
    {
        $defaults = posmain_resolve_pos_defaults($conn, []);
        $ids = [];
        foreach (['fund_id', 'payment_fund_id'] as $key) {
            $value = (int) ($defaults[$key] ?? 0);
            if ($value > 0) {
                $ids[$value] = $value;
            }
        }

        return array_values($ids);
    }

    private function sumCashReceiptVouchersForOrder(mysqli $conn, int $orderId, array $fundAccountIds): string
    {
        if ($orderId < 1 || !$fundAccountIds) {
            return '0.00';
        }

        $placeholders = implode(', ', array_fill(0, count($fundAccountIds), '?'));
        $params = array_merge([$orderId], $fundAccountIds);
        $row = $this->tableOrderService->queryOne($conn, "
            SELECT COALESCE(SUM(pro_value), 0) AS total_amount
            FROM ot_head
            WHERE pro_tybe = 1
              AND op2 = ?
              AND acc1 IN ({$placeholders})
        ", $params);

        return $this->moneyFromBoundary($row['total_amount'] ?? '0', true)->toString();
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
        $total,
        $discount,
        $net,
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
        $total,
        $discount,
        $net,
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
        $movementItems = [];
        foreach ($items as $item) {
            $itemId = (int) ($item['id'] ?? $item['item_id'] ?? 0);
            $qty = $this->quantityStringFromBoundary($item['qty'] ?? '0');
            if ($itemId <= 0 || FinancialDecimal::compare($qty, '0', DecimalQuantity::SCALE) <= 0) {
                continue;
            }

            $movementItems[] = [
                'item_id' => $itemId,
                'qty' => $qty,
                'price' => $this->unitPriceStringFromBoundary($item['price'] ?? '0'),
                'discount' => $this->unitPriceStringFromBoundary($item['discount'] ?? '0'),
                'unit_id' => (int) ($item['unit_id'] ?? $item['unitid'] ?? 0),
                'u_val' => $item['u_val'] ?? null,
                // The table path resolves unit factors a second time before
                // inventory normalization. Keep the already validated
                // preparation selection at the top level so explicit values
                // such as zero are not mistaken for an omitted selection.
                'preparation_values' => is_array($item['preparation_values'] ?? null)
                    ? $item['preparation_values']
                    : [],
                '_source_item' => $item,
            ];
        }

        $resolvedItems = $this->resolveAuthoritativeItemFactors($conn, $movementItems);
        $lineResult = $this->inventoryMovementService->normalizeInvoiceLines(
            $conn,
            InventoryMovementService::TYPE_POS,
            $resolvedItems,
            ['store_id' => $storeId]
        );

        $recipeLines = [];
        foreach ($lineResult['lines'] as $index => $line) {
            $line['_source_item'] = is_array($resolvedItems[$index]['_source_item'] ?? null)
                ? $resolvedItems[$index]['_source_item']
                : ($resolvedItems[$index] ?? []);
            $recipeLines[] = $this->insertTakeawayDetailLine($conn, $orderId, $storeId, $line, $context, 'table', 'dine_in');
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

    /**
     * Build direct-stock compensation from the posted credit note so partial
     * refunds use exactly the financially accepted quantity.
     */
    private function loadRefundInventoryInvoiceBridgeLines(mysqli $conn, int $orderId, int $creditNoteId): array
    {
        if ($orderId < 1
            || $creditNoteId < 1
            || !$this->columnExists($conn, 'credit_note_lines', 'original_detail_id')
            || !$this->columnExists($conn, 'credit_note_lines', 'stock_disposition')
        ) {
            return [];
        }

        $costSelect = $this->columnExists($conn, 'fat_details', 'cost_price')
            ? 'COALESCE(fd.cost_price, 0) AS cost_price'
            : '0 AS cost_price';
        $uuidSelect = $this->columnExists($conn, 'fat_details', 'uuid')
            ? 'fd.uuid AS order_line_uuid'
            : 'NULL AS order_line_uuid';

        $rows = $this->tableOrderService->queryAll($conn, "
            SELECT fd.id, fd.item_id, fd.u_val, fd.qty_in AS original_qty_in,
                   fd.qty_out AS original_qty_out, fd.det_store,
                   cnl.quantity AS refund_quantity, {$costSelect}, {$uuidSelect}
            FROM credit_note_lines cnl
            INNER JOIN fat_details fd ON fd.id = cnl.original_detail_id
            WHERE cnl.credit_note_id = ?
              AND cnl.stock_disposition = 'restock'
              AND fd.fatid = ?
              AND fd.isdeleted = 0
            ORDER BY cnl.id ASC
        ", [$creditNoteId, $orderId]);

        $lines = [];
        foreach ($rows as $row) {
            $quantity = DecimalQuantity::from((string) ($row['refund_quantity'] ?? '0'))->toString();
            if (FinancialDecimal::compare($quantity, '0', DecimalQuantity::SCALE) <= 0) {
                continue;
            }
            $isOutbound = FinancialDecimal::compare(
                (string) ($row['original_qty_out'] ?? '0'),
                (string) ($row['original_qty_in'] ?? '0'),
                DecimalQuantity::SCALE
            ) >= 0;
            $lines[] = [
                'id' => (int) $row['id'],
                'item_id' => (int) $row['item_id'],
                'qty_in' => $isOutbound ? '0' : $quantity,
                'qty_out' => $isOutbound ? $quantity : '0',
                'u_val' => (string) ($row['u_val'] ?? '1'),
                'cost_price' => (string) ($row['cost_price'] ?? '0'),
                'det_store' => (int) ($row['det_store'] ?? 0),
                'order_line_uuid' => $this->nullableString($row['order_line_uuid'] ?? null),
            ];
        }

        return $lines;
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

    private function hasInventorySaleMovementsForOrder(mysqli $conn, int $orderId): bool
    {
        if ($orderId < 1) {
            return false;
        }
        $row = $this->tableOrderService->queryOne($conn, "
            SELECT id
            FROM inventory_movements
            WHERE order_id = ?
              AND movement_type = 'sale_direct'
            LIMIT 1
        ", [$orderId]);

        return $row !== null;
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

    private function reserveInventoryInvoiceBridgeLines(
        mysqli $conn,
        int $orderId,
        array $lines,
        string $channel,
        string $orderType,
        array $request,
        array $context
    ): void {
        $this->executeInventoryLifecycleBridge(
            $conn,
            $orderId,
            $lines,
            'reserve',
            null,
            $channel,
            $orderType,
            $request,
            $context
        );
    }

    private function releaseInventoryInvoiceBridgeReservations(
        mysqli $conn,
        int $orderId,
        array $lines,
        string $reason,
        string $channel,
        string $orderType,
        array $request,
        array $context
    ): void {
        $this->executeInventoryLifecycleBridge(
            $conn,
            $orderId,
            $lines,
            'release',
            $reason,
            $channel,
            $orderType,
            $request,
            $context
        );
    }

    private function consumeInventoryInvoiceBridgeReservations(
        mysqli $conn,
        int $orderId,
        array $lines,
        string $channel,
        string $orderType,
        array $request,
        array $context
    ): void {
        $this->executeInventoryLifecycleBridge(
            $conn,
            $orderId,
            $lines,
            'consume',
            null,
            $channel,
            $orderType,
            $request,
            $context
        );
    }

    private function executeInventoryLifecycleBridge(
        mysqli $conn,
        int $orderId,
        array $lines,
        string $action,
        ?string $reason,
        string $channel,
        string $orderType,
        array $request,
        array $context
    ): void {
        if ($orderId < 1 || !$lines) {
            return;
        }

        try {
            $bridgeContext = $this->inventoryInvoiceBridgeContext($request, $context, $channel, $orderType);
            if ($action === 'reserve') {
                $result = $this->inventoryInvoiceBridge->reserveInvoiceLines(
                    $conn,
                    InventoryInvoiceBridge::TYPE_POS,
                    $orderId,
                    $lines,
                    $bridgeContext
                );
            } elseif ($action === 'release') {
                $result = $this->inventoryInvoiceBridge->releaseInvoiceReservations(
                    $conn,
                    InventoryInvoiceBridge::TYPE_POS,
                    $orderId,
                    $lines,
                    (string) $reason,
                    $bridgeContext
                );
            } else {
                $result = $this->inventoryInvoiceBridge->consumeInvoiceReservations(
                    $conn,
                    InventoryInvoiceBridge::TYPE_POS,
                    $orderId,
                    $lines,
                    $bridgeContext
                );
            }

            if (!empty($result['errors'])) {
                error_log('POS direct-stock ' . $action . ' errors: ' . json_encode($result['errors'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            if (SideEffectPolicy::inventoryBridgeShouldRollback(new RuntimeException('bridge_errors'), $result)) {
                throw new RuntimeException('INVENTORY_DIRECT_STOCK_' . strtoupper($action) . '_FAILED');
            }
        } catch (Throwable $exception) {
            error_log('POS direct-stock ' . $action . ' failed: ' . $exception->getMessage());
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
            if (SideEffectPolicy::inventoryBridgeShouldRollback(new RuntimeException('bridge_errors'), $result)) {
                throw new RuntimeException('INVENTORY_BRIDGE_REVERSAL_FAILED');
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

            if (!empty($splitLine['is_full']) || RecipeDecimal::compare($remainingRawQty, '0') <= 0) {
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

    /**
     * Limit recipe/COGS compensation to the quantities durably posted on the
     * credit note. This keeps item, amount, and full refunds on one authority.
     */
    private function recipeLinesForCreditNote(mysqli $conn, array $recipeLines, int $creditNoteId): array
    {
        if ($creditNoteId < 1) {
            return [];
        }
        $requested = [];
        $dispositionSelect = $this->columnExists($conn, 'credit_note_lines', 'stock_disposition')
            ? ', stock_disposition'
            : ", 'no_stock_return' AS stock_disposition";
        $rows = $this->tableOrderService->queryAll($conn, "
            SELECT id AS credit_note_line_id, original_detail_id, quantity{$dispositionSelect}
            FROM credit_note_lines
            WHERE credit_note_id = ?
            ORDER BY id ASC
        ", [$creditNoteId]);
        foreach ($rows as $line) {
            $detailId = (int) ($line['original_detail_id'] ?? 0);
            if ($detailId > 0) {
                $requested[$detailId] = [
                    'credit_note_line_id' => (int) ($line['credit_note_line_id'] ?? 0),
                    'quantity' => DecimalQuantity::from($line['quantity'] ?? '0')->toString(),
                    'stock_disposition' => (string) ($line['stock_disposition'] ?? 'no_stock_return'),
                ];
            }
        }

        $filtered = [];
        foreach ($recipeLines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $detailId = (int) ($line['fat_detail_id'] ?? 0);
            if ($detailId < 1 || !isset($requested[$detailId])) {
                continue;
            }
            $line['quantity'] = $requested[$detailId]['quantity'];
            $line['qty'] = $requested[$detailId]['quantity'];
            $line['credit_note_line_id'] = $requested[$detailId]['credit_note_line_id'];
            $line['stock_disposition'] = $requested[$detailId]['stock_disposition'];
            $filtered[] = $line;
        }

        return $filtered;
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
        foreach (['source_order_uuid', 'source_line_uuid', 'source_event_uuid', 'external_line_id'] as $identityKey) {
            if (isset($sourceItem[$identityKey]) && trim((string) $sourceItem[$identityKey]) !== '') {
                $line[$identityKey] = substr(trim((string) $sourceItem[$identityKey]), 0, 128);
            }
        }
        $sourceChannel = strtolower(trim((string) ($sourceItem['source_channel'] ?? '')));
        if (in_array($sourceChannel, ['moova', 'cofe', 'api', 'sync'], true)) {
            $line['channel'] = $sourceChannel;
        }
        if (isset($sourceItem['preparation_values']) && is_array($sourceItem['preparation_values'])) {
            $line['preparation_values'] = $sourceItem['preparation_values'];
        } elseif ($detailId > 0) {
            $line['preparation_values'] = $this->preparationSelectionService->fetchLineValues($conn, $orderId, $detailId);
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

    private function registerExternalOrderLineMappings(mysqli $conn, array &$recipeLines): void
    {
        if (!$this->tableExists($conn, 'external_order_line_map')) {
            return;
        }

        $identity = new ExternalOrderLineIdentityService();
        foreach ($recipeLines as $index => &$line) {
            if (!is_array($line)) {
                continue;
            }
            $sourceChannel = strtolower(trim((string) ($line['channel'] ?? '')));
            $externalOrderId = trim((string) ($line['source_order_uuid'] ?? ''));
            $externalLineId = trim((string) ($line['external_line_id'] ?? ''));
            if (!in_array($sourceChannel, ['moova', 'cofe', 'api', 'sync'], true)
                || $externalOrderId === ''
                || $externalLineId === '') {
                continue;
            }

            $scope = new RecipeScope(
                (int) ($line['tenant'] ?? $line['pos_tenant'] ?? 0),
                (int) ($line['branch'] ?? $line['pos_branch'] ?? 0),
                $line['branch_uuid'] ?? null,
                (int) ($line['store_id'] ?? 0),
                $sourceChannel,
                (string) ($line['order_type'] ?? 'dine_in'),
                $sourceChannel
            );
            $registered = $identity->registerLine(
                $conn,
                $scope,
                $sourceChannel,
                $externalOrderId,
                [
                    'item_id' => (int) ($line['item_id'] ?? $line['sellable_item_id'] ?? 0),
                    'external_line_id' => $externalLineId,
                    'modifiers' => is_array($line['modifiers'] ?? null) ? $line['modifiers'] : [],
                ],
                (int) $index,
                [
                    'order_id' => (int) ($line['order_id'] ?? 0),
                    'fat_detail_id' => (int) ($line['fat_detail_id'] ?? 0),
                    'order_line_uuid' => $line['order_line_uuid'] ?? null,
                    'line_status' => 'active',
                ]
            );
            $line['source_line_uuid'] = substr(
                $registered['source_channel'] . ':' . $registered['external_line_id'],
                0,
                128
            );
        }
        unset($line);
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

    private function moneyFromBoundary($value, bool $allowNegative = false): Money
    {
        return is_float($value)
            ? Money::fromLegacy($value, $allowNegative)
            : Money::from($value === null || $value === '' ? '0' : $value, $allowNegative);
    }

    private function quantityStringFromBoundary($value): string
    {
        return is_float($value)
            ? DecimalQuantity::fromLegacy($value)->toString()
            : DecimalQuantity::from($value === null || $value === '' ? '0' : $value)->toString();
    }

    private function unitPriceStringFromBoundary($value): string
    {
        return is_float($value)
            ? UnitPrice::fromLegacy($value)->toString()
            : UnitPrice::from($value === null || $value === '' ? '0' : $value)->toString();
    }

    private function moneyIsPositive($value): bool
    {
        return $this->moneyFromBoundary($value, true)->isPositive();
    }

    private function percentageString($amount, $total): string
    {
        $amountString = $this->moneyFromBoundary($amount)->toString();
        $totalString = $this->moneyFromBoundary($total)->toString();
        if (FinancialDecimal::compare($amountString, '0.00', Money::SCALE) <= 0) {
            return '0.00';
        }
        if (FinancialDecimal::compare($totalString, '0.00', Money::SCALE) <= 0) {
            return '0.00';
        }

        return FinancialDecimal::normalize(
            bcdiv(bcmul($amountString, '100', 6), $totalString, 2),
            2
        );
    }

    private function applyPaidState(mysqli $conn, int $orderId, int $tableId, $existingPaid, $net): array
    {
        $paidMoney = $this->moneyFromBoundary($existingPaid);
        $netMoney = $this->moneyFromBoundary($net);
        $appliedPaid = $paidMoney->compare($netMoney) > 0 ? $netMoney : $paidMoney;
        $remaining = $netMoney->subtract($appliedPaid);
        if (!$appliedPaid->isPositive()) {
            $paymentStatus = 'unpaid';
            $invoiceStatus = 'draft';
            $orderStatus = 'active';
        } elseif (!$remaining->isPositive()) {
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
        ", [$appliedPaid->toString(), $remaining->toString(), $paymentStatus, $invoiceStatus, $orderStatus, $orderStatus, $orderId, $tableId]);

        return [
            'paid_amount' => $appliedPaid->toString(),
            'remaining_amount' => $remaining->toString(),
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
        $lineQty,
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

    private function lineModifiersFromItem(array $item, $lineQty): array
    {
        foreach (['modifiers', 'modifier_options', 'selected_modifiers', 'itmmodifiers'] as $key) {
            if (!array_key_exists($key, $item)) {
                continue;
            }

            $decoded = $this->decodeLineModifiers($item[$key]);
            if (!$decoded) {
                return [];
            }

            $lineQty = $this->quantityStringFromBoundary($lineQty);
            if (FinancialDecimal::compare($lineQty, '0', DecimalQuantity::SCALE) <= 0) {
                $lineQty = '1.000000';
            }
            $scaled = [];
            foreach ($decoded as $modifier) {
                if (is_array($modifier)) {
                    $optionId = (int) ($modifier['option_id'] ?? $modifier['id'] ?? $modifier['modifier_option_id'] ?? 0);
                    if ($optionId <= 0) {
                        continue;
                    }
                    $perItemQty = $this->quantityStringFromBoundary($modifier['qty'] ?? $modifier['quantity'] ?? '1');
                    if (FinancialDecimal::compare($perItemQty, '0', DecimalQuantity::SCALE) <= 0) {
                        continue;
                    }
                    $scaled[] = [
                        'option_id' => $optionId,
                        'qty' => FinancialDecimal::multiply($perItemQty, $lineQty, DecimalQuantity::SCALE),
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

    private function decodeLinePreparationValues($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function preparationValuesFromOptions($options): array
    {
        if (!is_array($options)) {
            return [];
        }
        $values = [];
        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }
            $id = (string) ($option['id'] ?? $option['option_id'] ?? $option['providerOptionId'] ?? '');
            if (strpos($id, 'pos-preparation-') !== 0) {
                continue;
            }
            $values[] = [
                'code' => substr($id, strlen('pos-preparation-')),
                'value' => $option['value'] ?? $option['value_int'] ?? null,
            ];
        }

        return $values;
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
                    '1.000000',
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

    private function requireOrderEscalationsIfNeeded(
        mysqli $conn,
        ?int $orderId,
        $discount,
        array $request,
        array $context
    ): void {
        $this->requireDiscountApprovalIfNeeded($conn, $orderId, $discount, $request, $context);
        $this->requirePriceOverrideApprovalIfNeeded($conn, $orderId, $request, $context);
        $this->requireCreditSaleApprovalIfNeeded($conn, $orderId, $request, $context);
    }

    private function requireItemVoidApprovalIfNeeded(
        mysqli $conn,
        int $orderId,
        array $items,
        array $request,
        array $context,
        ?array $orderHeader = null
    ): void {
        if ($orderId < 1) {
            return;
        }

        $orderHeader = $orderHeader ?? $this->tableOrderService->queryOne($conn, "
            SELECT payment_status, order_status
            FROM ot_head
            WHERE id = ?
            LIMIT 1
        ", [$orderId]);
        if (!$orderHeader) {
            return;
        }

        $reductions = $this->detectPersistedLineReductions($conn, $orderId, $items);
        if (!$reductions) {
            return;
        }

        $paymentStatus = strtolower(trim((string) ($orderHeader['payment_status'] ?? 'unpaid')));
        $orderStatus = strtolower(trim((string) ($orderHeader['order_status'] ?? 'active')));
        if ($paymentStatus === 'paid' || $orderStatus === 'completed') {
            throw new RuntimeException('PAID_ORDER_LINE_REMOVAL_DENIED');
        }

        $userId = $this->contextUserId($request, $context);
        if (!class_exists('PermissionService', false)) {
            require_once __DIR__ . '/../../Security/PermissionService.php';
        }
        if (PermissionService::forConnection($conn)->check($userId, 'pos.void.item_after_send')) {
            return;
        }

        $approval = $this->managerApprovalService->requireApprovedIfNeeded(
            $conn,
            'pos.void.item_after_send',
            'pos_order',
            $orderId,
            '1.000000',
            $request,
            array_merge($context, [
                'user_id' => $userId,
                'require_manager_approval' => true,
            ])
        );
        if ($approval) {
            $this->managerApprovalService->consumeApproval($conn, (int) $approval['id'], $userId);
            $this->recordOrderEvent($conn, $orderId, 'order.item_voided', $context['event_source'] ?? 'pos_item_void', $context, [
                'reductions' => $reductions,
                'manager_approval_id' => (int) ($approval['id'] ?? 0),
                'approved_by' => (int) ($approval['approved_by'] ?? 0),
            ]);
        }
    }

    private function ensureItemVoidApprovalPresent(
        mysqli $conn,
        int $orderId,
        array $items,
        array $request,
        array $context
    ): void {
        if ($orderId < 1) {
            return;
        }

        $orderHeader = $this->tableOrderService->queryOne($conn, "
            SELECT payment_status, order_status
            FROM ot_head
            WHERE id = ?
            LIMIT 1
        ", [$orderId]);
        if (!$orderHeader) {
            return;
        }

        $reductions = $this->detectPersistedLineReductions($conn, $orderId, $items);
        if (!$reductions) {
            return;
        }

        $paymentStatus = strtolower(trim((string) ($orderHeader['payment_status'] ?? 'unpaid')));
        $orderStatus = strtolower(trim((string) ($orderHeader['order_status'] ?? 'active')));
        if ($paymentStatus === 'paid' || $orderStatus === 'completed') {
            throw new RuntimeException('PAID_ORDER_LINE_REMOVAL_DENIED');
        }

        $userId = $this->contextUserId($request, $context);
        if (!class_exists('PermissionService', false)) {
            require_once __DIR__ . '/../../Security/PermissionService.php';
        }
        if (PermissionService::forConnection($conn)->check($userId, 'pos.void.item_after_send')) {
            return;
        }

        $this->managerApprovalService->requireApprovedIfNeeded(
            $conn,
            'pos.void.item_after_send',
            'pos_order',
            $orderId,
            '1.000000',
            $request,
            array_merge($context, [
                'user_id' => $userId,
                'require_manager_approval' => true,
            ])
        );
    }

    private function detectPersistedLineReductions(mysqli $conn, int $orderId, array $items): array
    {
        $existing = $this->loadPersistedItemQuantities($conn, $orderId);
        if (!$existing) {
            return [];
        }

        $incoming = $this->incomingItemQuantities($items);
        $reductions = [];
        foreach ($existing as $itemId => $existingQty) {
            $newQty = $this->quantityStringFromBoundary($incoming[$itemId] ?? '0');
            $oldQty = $this->quantityStringFromBoundary($existingQty);
            if (FinancialDecimal::compare($newQty, $oldQty, DecimalQuantity::SCALE) < 0) {
                $reductions[] = [
                    'item_id' => $itemId,
                    'from_qty' => $oldQty,
                    'to_qty' => $newQty,
                    'removed_qty' => FinancialDecimal::subtract($oldQty, $newQty, DecimalQuantity::SCALE),
                ];
            }
        }

        return $reductions;
    }

    private function loadPersistedItemQuantities(mysqli $conn, int $orderId): array
    {
        $rows = $this->tableOrderService->queryAll($conn, "
            SELECT item_id, SUM(GREATEST(0, qty_out - qty_in)) AS qty
            FROM fat_details
            WHERE fatid = ?
              AND isdeleted = 0
            GROUP BY item_id
        ", [$orderId]);

        $map = [];
        foreach ($rows as $row) {
            $itemId = (int) ($row['item_id'] ?? 0);
            if ($itemId < 1) {
                continue;
            }
            $map[$itemId] = $this->quantityStringFromBoundary($row['qty'] ?? '0');
        }

        return $map;
    }

    private function incomingItemQuantities(array $items): array
    {
        $map = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $itemId = (int) ($item['item_id'] ?? $item['id'] ?? 0);
            if ($itemId < 1) {
                continue;
            }
            $qty = $this->quantityStringFromBoundary($item['qty'] ?? $item['quantity'] ?? '1');
            $map[$itemId] = FinancialDecimal::add(
                $map[$itemId] ?? '0.000000',
                $qty,
                DecimalQuantity::SCALE
            );
        }

        return $map;
    }

    private function requirePriceOverrideApprovalIfNeeded(mysqli $conn, ?int $orderId, array $request, array $context): void
    {
        if (!empty($request['price_override_approval_id'])) {
            $userId = $this->contextUserId($request, $context);
            $approval = $this->managerApprovalService->requireApprovedIfNeeded(
                $conn,
                'pos.price.override',
                'pos_order',
                $orderId,
                '1.000000',
                $request,
                array_merge($context, ['user_id' => $userId])
            );
            if ($approval) {
                $this->managerApprovalService->consumeApproval($conn, (int) $approval['id'], $userId);
            }
            return;
        }

        if (!class_exists('PermissionService', false)) {
            require_once __DIR__ . '/../../Security/PermissionService.php';
        }
        $userId = $this->contextUserId($request, $context);
        $permissionService = PermissionService::forConnection($conn);
        if ($permissionService->check($userId, 'pos.price.override')) {
            return;
        }

        $items = is_array($request['items'] ?? null) ? $request['items'] : [];
        $hasOverride = false;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $itemId = (int) ($item['id'] ?? $item['item_id'] ?? 0);
            $submittedPrice = $this->unitPriceStringFromBoundary($item['price'] ?? '0');
            if ($itemId < 1 || FinancialDecimal::compare($submittedPrice, '0', UnitPrice::SCALE) <= 0) {
                continue;
            }
            $stmt = $conn->prepare('SELECT price1 FROM myitems WHERE id = ? AND isdeleted = 0 LIMIT 1');
            $stmt->bind_param('i', $itemId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $catalogPrice = $this->unitPriceStringFromBoundary($row['price1'] ?? '0');
            if (
                FinancialDecimal::compare($catalogPrice, '0', UnitPrice::SCALE) > 0
                && FinancialDecimal::compare($submittedPrice, $catalogPrice, UnitPrice::SCALE) !== 0
            ) {
                $hasOverride = true;
                break;
            }
        }

        if (!$hasOverride) {
            return;
        }

        $approval = $this->managerApprovalService->requireApprovedIfNeeded(
            $conn,
            'pos.price.override',
            'pos_order',
            $orderId,
            '1.000000',
            $request,
            array_merge($context, [
                'user_id' => $userId,
                'require_manager_approval' => true,
            ])
        );
        if ($approval) {
            $this->managerApprovalService->consumeApproval($conn, (int) $approval['id'], $userId);
        }
    }

    private function requireCreditSaleApprovalIfNeeded(mysqli $conn, ?int $orderId, array $request, array $context): void
    {
        $jalAmount = $this->moneyFromBoundary($request['jal_amount'] ?? '0')->toString();
        if (!$this->moneyIsPositive($jalAmount)) {
            return;
        }

        $userId = $this->contextUserId($request, $context);
        if (!class_exists('PermissionService', false)) {
            require_once __DIR__ . '/../../Security/PermissionService.php';
        }
        if (PermissionService::forConnection($conn)->check($userId, 'pos.credit.sale')) {
            return;
        }

        $approval = $this->managerApprovalService->requireApprovedIfNeeded(
            $conn,
            'pos.credit.sale',
            'pos_order',
            $orderId,
            $jalAmount,
            $request,
            array_merge($context, ['user_id' => $userId])
        );
        if ($approval) {
            $this->managerApprovalService->consumeApproval($conn, (int) $approval['id'], $userId);
        }
    }

    private function requireDiscountApprovalIfNeeded(mysqli $conn, ?int $orderId, $discount, array $request, array $context): void
    {
        $userId = $this->contextUserId($request, $context);
        $total = $this->moneyFromBoundary($request['headtotal'] ?? $request['total'] ?? '0')->toString();
        $discountPct = $this->percentageString($discount, $total);
        if (FinancialDecimal::compare($discountPct, '0', 2) <= 0) {
            return;
        }

        $approval = $this->managerApprovalService->requireApprovedIfNeeded(
            $conn,
            'pos.discount.manual_pct.limit',
            'pos_order',
            $orderId,
            $discountPct,
            $request,
            array_merge($context, [
                'user_id' => $userId,
                'limit_permission_key' => 'pos.discount.apply',
                'escalation_permission_key' => 'pos.discount.manual_pct.limit',
            ])
        );
        if ($approval) {
            $this->managerApprovalService->consumeApproval($conn, (int) $approval['id'], $userId);
        }
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

    public function escalationAttributionLineForOrder(mysqli $conn, int $orderId): ?string
    {
        if ($orderId < 1 || !$this->tableExists($conn, 'manager_approvals')) {
            return null;
        }

        $stmt = $conn->prepare("
            SELECT performed_by, approved_by
              FROM manager_approvals
             WHERE consumed_at IS NOT NULL
               AND target_id = ?
               AND target_type IN ('order', 'pos_order')
             ORDER BY consumed_at DESC
             LIMIT 1
        ");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return null;
        }

        $performerId = (int) ($row['performed_by'] ?? 0);
        $approverId = (int) ($row['approved_by'] ?? 0);
        if ($performerId < 1 || $approverId < 1) {
            return null;
        }

        $performerName = $this->userDisplayLabel($conn, $performerId);
        $approverName = $this->userDisplayLabel($conn, $approverId);

        return 'بواسطة ' . $performerName . ' — بموافقة ' . $approverName;
    }

    private function userDisplayLabel(mysqli $conn, int $userId): string
    {
        $stmt = $conn->prepare(
            'SELECT COALESCE(NULLIF(TRIM(display_name), ""), uname) AS label FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $label = trim((string) ($row['label'] ?? ''));
        if ($label === '') {
            return 'موظف #' . $userId;
        }

        return $label;
    }

    private function columnExists(mysqli $conn, string $tableName, string $columnName): bool
    {
        $tableName = $conn->real_escape_string($tableName);
        $columnName = $conn->real_escape_string($columnName);
        $result = $conn->query("SHOW COLUMNS FROM `{$tableName}` LIKE '{$columnName}'");

        return $result && $result->num_rows > 0;
    }

    private function orderAccountingScope(mysqli $conn, int $orderId): array
    {
        if ($orderId < 1
            || !$this->columnExists($conn, 'ot_head', 'tenant')
            || !$this->columnExists($conn, 'ot_head', 'branch')) {
            return ['tenant' => 0, 'branch' => 0];
        }

        $stmt = $conn->prepare('SELECT tenant, branch FROM ot_head WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return [
            'tenant' => max(0, (int) ($row['tenant'] ?? 0)),
            'branch' => max(0, (int) ($row['branch'] ?? 0)),
        ];
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

    private function orderRevisionService(): OrderRevisionService
    {
        static $service = null;
        if ($service === null) {
            $service = new OrderRevisionService();
        }

        return $service;
    }

    private function mutationVersionService(): OrderMutationVersionService
    {
        static $service = null;
        if ($service === null) {
            $service = new OrderMutationVersionService();
        }

        return $service;
    }

    private function expectedMutationVersion(array $request)
    {
        return $request['mutation_version'] ?? $request['order_version'] ?? null;
    }

    private function attachKitchenRevision(mysqli $conn, int $orderId, array $envelope): array
    {
        if ($orderId > 0) {
            $revision = $this->orderRevisionService()->bumpAndGet($conn, $orderId);
            if (!isset($envelope['data']) || !is_array($envelope['data'])) {
                $envelope['data'] = [];
            }
            $envelope['data']['kitchen_revision'] = $revision;
            if (!isset($envelope['data']['mutation_version'])) {
                $envelope['data']['mutation_version'] = $this->mutationVersionService()->current($conn, $orderId);
            }
        }

        return $envelope;
    }
}
